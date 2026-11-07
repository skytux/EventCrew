<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\VerificationController;

final class OnboardingServiceTest extends TelegramTestCase
{
    private const CONFIRMED = 'Your email is confirmed. Tap a task on the board to sign up.';
    private const MOVED_AWAY = 'Your Test Site account is now linked to another Telegram account.'
        . " Contact an organizer if that wasn't you.";

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->mails = [];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });

        Functions\when('sanitize_email')->alias(static fn (string $email): string => trim($email));
        Functions\when('is_email')->alias(
            static fn (string $email): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)
        );
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function service(): OnboardingService
    {
        return new OnboardingService(
            new PersonRepository(),
            new AuthTokenRepository(),
            $this->client(),
            new Logger(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    public function testStartAsksANewUserForTheirEmailAndRemembersThem(): void
    {
        $this->service()->start(555, 555, 'Sam Volunteer');

        self::assertArrayHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    public function testStartTellsAnAlreadyVerifiedUserTheyAreSetUp(): void
    {
        // findByTelegramUserId resolves a verified person.
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];

        $this->service()->start(555, 555, 'Sam');

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    public function testCaptureEmailCreatesThePersonIssuesATokenAndSendsTheLink(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> null, findByEmail -> null (both get_row, unqueued).
        $this->service()->captureEmail(555, 555, 'sam@example.com');
        // A newcomer is asked the organizers' questions first; Skip sends the link.
        $this->tapJoin('nwq:s');

        // One person insert, one auth-token insert.
        self::assertCount(2, $this->wpdb->inserts);
        self::assertSame('sam@example.com', $this->wpdb->inserts[0]['data']['email']);
        // Created without Telegram ids: those wait for the click.
        self::assertNull($this->wpdb->inserts[0]['data']['telegram_user_id']);
        self::assertNull($this->wpdb->inserts[0]['data']['telegram_chat_id']);

        // The magic link was emailed and points at the verify endpoint.
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('wp-json/eventcrew/v1/verify?token=', $this->mails[0]['body']);

        // The pending state is cleared and a confirmation message sent.
        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testCaptureEmailReusesAnExistingPersonRatherThanDuplicating(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> null; findByEmail -> an existing person (id 9).
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'sam@example.com',
            'email_verified_at' => null,
            'telegram_user_id' => null,
        ];

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // No new person row - just the token insert - and nothing linked yet.
        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame([], $this->telegramLinkUpdates());
        self::assertCount(1, $this->mails);
    }

    /**
     * The takeover this guards against: someone types another person's email.
     * Before the click nothing changes on that person; the click links the new
     * account and tells the old chat.
     */
    public function testAnExistingEmailIsLinkedToANewTelegramOnlyAfterTheClickAndTheOldChatIsTold(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId(555) -> null; findByEmail -> Sam, verified, on Telegram 111.
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->sam(111);

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // Typing the email linked nothing; the account waits on the token.
        self::assertSame([], $this->telegramLinkUpdates());
        self::assertCount(1, $this->pendingLinks());

        $this->clickLink(9, $this->sam(111), null, $this->sam(555));

        self::assertSame(
            [['telegram_user_id' => 555, 'telegram_chat_id' => 555, 'id' => 9]],
            $this->telegramLinkUpdates()
        );
        self::assertSame([], $this->pendingLinks());

        $sent = $this->sentMessages();
        self::assertContains([111, self::MOVED_AWAY], $sent);
        self::assertContains([555, self::CONFIRMED], $sent);
    }

    public function testAnExistingEmailWithNoTelegramIsFilledAfterTheClick(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->sam(null);

        $this->service()->captureEmail(555, 555, 'sam@example.com');
        self::assertSame([], $this->telegramLinkUpdates());

        $this->clickLink(9, $this->sam(null), null, $this->sam(555));

        self::assertSame(
            [['telegram_user_id' => 555, 'telegram_chat_id' => 555, 'id' => 9]],
            $this->telegramLinkUpdates()
        );
        // Nobody to warn: only the "confirmed" nudge in the new chat.
        self::assertSame([[555, self::CONFIRMED]], $this->sentMessages());
    }

    public function testANewEmailIsCreatedWithoutTelegramIdsAndLinkedAfterTheClick(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> null, findByEmail -> null: a new person, id 1.
        $this->service()->captureEmail(555, 555, 'sam@example.com');
        $this->tapJoin('nwq:s');

        self::assertNull($this->wpdb->inserts[0]['data']['telegram_user_id']);
        self::assertSame([], $this->telegramLinkUpdates());

        $fresh = ['id' => 1, 'email' => 'sam@example.com', 'email_verified_at' => null, 'telegram_user_id' => null];
        $this->clickLink(1, $fresh, null, ['telegram_user_id' => 555, 'telegram_chat_id' => 555] + $fresh);

        self::assertSame(
            [['telegram_user_id' => 555, 'telegram_chat_id' => 555, 'id' => 1]],
            $this->telegramLinkUpdates()
        );
        self::assertSame([[555, self::CONFIRMED]], $this->sentMessages());
    }

    /**
     * @return array<int, array{0: string, 1: ?string}>
     */
    public static function deadTokens(): array
    {
        return [
            'expired' => ['1999-01-01 00:00:00', null],
            'already used' => ['2999-01-01 00:00:00', '2026-07-01 00:00:00'],
        ];
    }

    /**
     * @dataProvider deadTokens
     */
    public function testAnExpiredOrUsedTokenLinksNothing(string $expiresAt, ?string $usedAt): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->sam(111);

        $this->service()->captureEmail(555, 555, 'sam@example.com');
        $this->telegramCalls = [];

        $this->wpdb->nextRows[] = ['id' => 3, 'person_id' => 9, 'expires_at' => $expiresAt, 'used_at' => $usedAt];

        self::assertFalse($this->verification()->verify($this->emailedToken()));
        self::assertSame([], $this->telegramLinkUpdates());
        self::assertSame([], $this->telegramCalls);
    }

    public function testTheClickRefusesATelegramAccountAlreadyLinkedToAnotherPerson(): void
    {
        // Telegram 555 typed Sam's email before it was linked anywhere ...
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->sam(null);

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // ... and by the click 555 belongs to Alex (id 12), through another link.
        $alex = [
            'id' => 12,
            'email' => 'alex@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
        ];
        $this->clickLink(9, $this->sam(null), $alex, $this->sam(null));

        // Not spliced: Sam stays unlinked, Alex keeps 555, and 555 is told why.
        self::assertSame([], $this->telegramLinkUpdates());
        self::assertSame(
            [[555, 'This Telegram account is already linked to another email. Contact an organizer if that is wrong.']],
            $this->sentMessages()
        );
    }

    public function testRetypingTheEmailOfThisSameTelegramAccountWarnsNobody(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId(555) -> Sam himself, so the "another email" check passes.
        $this->wpdb->nextRows[] = $this->sam(555);
        $this->wpdb->nextRows[] = $this->sam(555);

        $this->service()->captureEmail(555, 555, 'sam@example.com');
        self::assertSame([], $this->telegramLinkUpdates());

        $this->clickLink(9, $this->sam(555), $this->sam(555), $this->sam(555));

        // The same link written again; no "linked to another account" notice.
        self::assertSame([[555, self::CONFIRMED]], $this->sentMessages());
    }

    /**
     * Sam, person 9 with a verified email, on the given Telegram account (the
     * private chat id equals the user id) or on none.
     *
     * @return array<string, mixed>
     */
    private function sam(?int $telegram): array
    {
        return [
            'id' => 9,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $telegram,
            'telegram_chat_id' => $telegram,
        ];
    }

    private function verification(): VerificationController
    {
        return new VerificationController(new AuthTokenRepository(), new PersonRepository(), $this->client());
    }

    /**
     * Clicks the emailed link. The rows are what the database answers, in
     * order: the person before linking, who holds the waiting Telegram id now,
     * and the person after.
     *
     * @param array<string, mixed>      $before
     * @param array<string, mixed>|null $holder
     * @param array<string, mixed>      $after
     */
    private function clickLink(int $personId, array $before, ?array $holder, array $after): void
    {
        $this->telegramCalls = [];
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => $personId,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => null,
        ];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE that spends the link
        $this->wpdb->nextRows[] = $before;
        $this->wpdb->nextRows[] = $holder;
        $this->wpdb->nextRows[] = $after;

        self::assertTrue($this->verification()->verify($this->emailedToken()));
    }

    private function emailedToken(): string
    {
        self::assertSame(1, preg_match('/verify\?token=([0-9a-f]{64})/', $this->mails[0]['body'] ?? '', $match));

        return $match[1];
    }

    /**
     * Every write of Telegram ids onto a person, as data plus the row id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function telegramLinkUpdates(): array
    {
        $links = [];

        foreach ($this->wpdb->updates as $update) {
            if (array_key_exists('telegram_user_id', $update['data'])) {
                $links[] = [
                    'telegram_user_id' => $update['data']['telegram_user_id'],
                    'telegram_chat_id' => $update['data']['telegram_chat_id'] ?? null,
                ] + $update['where'];
            }
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingLinks(): array
    {
        return array_filter(
            $this->transients,
            static fn (string $key): bool => str_starts_with($key, 'eventcrew_tg_pending_link_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return array<int, array{0: mixed, 1: mixed}>
     */
    private function sentMessages(): array
    {
        $sent = [];

        foreach ($this->telegramCalls as $call) {
            if ('sendMessage' === $call['method']) {
                $sent[] = [$call['body']['chat_id'] ?? null, $call['body']['text'] ?? null];
            }
        }

        return $sent;
    }

    public function testCaptureEmailRejectsAnInvalidAddressAndKeepsWaiting(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        $this->service()->captureEmail(555, 555, 'not-an-email');

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
        // Still waiting for a better answer.
        self::assertArrayHasKey('eventcrew_tg_await_email_555', $this->transients);
    }

    public function testCaptureEmailIgnoresAMessageWhenNotAwaitingOne(): void
    {
        $this->service()->captureEmail(555, 555, 'sam@example.com');

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
        self::assertSame([], $this->telegramCalls);
    }

    public function testCaptureEmailRefusesWhenTheTelegramIdIsLinkedToAnotherEmail(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> a person whose email differs from the one given.
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'someone.else@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
    }
    public function testStartOffersThePrivacyNoticeAsANamedButton(): void
    {
        $this->options['wp_page_for_privacy_policy'] = 12;
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_permalink')->justReturn('https://example.test/privacy/');

        $this->service()->start(555, 555, 'Sam');

        $message = $this->lastCallTo('sendMessage');
        self::assertStringNotContainsString('https://example.test/privacy/', (string) $message['text']);
        self::assertSame('https://example.test/privacy/', $message['reply_markup']['inline_keyboard'][0][0]['url']);
    }

    public function testTheVerificationEmailNamesItsLink(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        $this->service()->captureEmail(555, 555, 'sam@example.com');
        $this->tapJoin('nwq:s');

        // Sent through the Mailer as HTML, so the link sits behind a named
        // button; the plain-text alternative alone carries the address.
        self::assertStringContainsString('Confirm your email', $this->mails[0]['body']);
        self::assertStringContainsString('<a ', $this->mails[0]['body']);
    }

    // ---- The Messages page template (Q297) --------------------------------

    /**
     * The confirmation email to sam@example.com as plain text, its link tokens
     * replaced by TOKEN.
     *
     * @param string $name The name the Telegram account has ('' for none).
     */
    private function confirmationEmail(string $name): array
    {
        $this->options[EmailTemplate::HTML_OPTION] = false;
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => $name];

        $this->service()->captureEmail(555, 555, 'sam@example.com');
        $this->tapJoin('nwq:s');

        $mail = $this->mails[0];
        $mail['body'] = (string) preg_replace('/\?token=[^\s]+/', '?token=TOKEN', $mail['body']);

        return $mail;
    }

    /** With nothing stored, the email is what it was before the template. */
    public function testTheConfirmationEmailIsTheSameAsBeforeTheTemplate(): void
    {
        $mail = $this->confirmationEmail('Sam');

        self::assertSame('Confirm your email for Test Site', $mail['subject']);
        self::assertSame(
            "Hi Sam,\n\nConfirm your email to finish signing up for Test Site tasks.\n\nThe link works once and"
                . " expires in a day. If you didn't ask for this, you can ignore this email.\n\n"
                . "We store your email to run the crew.\n\n"
                . "Confirm your email: https://example.test/wp-json/eventcrew/v1/verify?token=TOKEN\n\n"
                . '—' . "\n" . 'Manage your account or stop these emails: '
                . 'https://example.test/wp-json/eventcrew/v1/manage?token=TOKEN',
            $mail['body']
        );
    }

    /** A text saved on the Messages page is sent; the link button is added all the same. */
    public function testAStoredConfirmationTextIsSentAndKeepsTheLink(): void
    {
        $stored = static fn (string $text): array => [
            'source' => $text,
            'texts' => ['fi' => $text, 'en' => $text],
            'manual' => ['fi' => false, 'en' => false],
        ];
        $this->options[MessageTemplates::OPTION_PREFIX . 'email_confirm'] = [
            MessageTemplates::SUBJECT => $stored('Welcome {name}'),
            MessageTemplates::BODY => $stored('Tap below, {name}.'),
        ];

        $mail = $this->confirmationEmail('');

        self::assertSame('Welcome sam@example.com', $mail['subject'], 'no Telegram name: the email address');
        self::assertStringStartsWith(
            "Tap below, sam@example.com.\n\nConfirm your email: "
            . 'https://example.test/wp-json/eventcrew/v1/verify?token=TOKEN',
            $mail['body']
        );
    }

    // ---- The newcomer's questions (Owner 2026-11-07) ------------------------

    /** A tap on one of the question buttons, by Telegram user 555 on message 40. */
    private function tapJoin(string $data): void
    {
        $this->service()->onJoinTap([
            'id' => 'cb1',
            'from' => ['id' => 555],
            'message' => ['message_id' => 40, 'chat' => ['id' => 555]],
            'data' => $data,
        ]);
    }

    /**
     * Queues the two reads of person 1 one stored answer makes (the check,
     * then the fresh read before the write): a newcomer waiting for the OK.
     *
     * @param array<string, string> $notices
     */
    private function queueNewcomer(array $notices = []): void
    {
        $row = [
            'id' => 1,
            'email' => 'sam@example.com',
            'email_verified_at' => null,
            'approval' => 'waiting',
            'role_notices' => [] === $notices ? null : json_encode($notices),
        ];
        $this->wpdb->nextRows[] = $row;
        $this->wpdb->nextRows[] = $row;
    }

    /** @return list<array<string, string>> Every role_notices write, decoded. */
    private function noticeWrites(): array
    {
        $writes = [];

        foreach ($this->wpdb->updates as $update) {
            if (array_key_exists('role_notices', $update['data'])) {
                $writes[] = json_decode((string) $update['data']['role_notices'], true);
            }
        }

        return $writes;
    }

    public function testANewEmailIsAskedTheQuestionsBeforeTheLinkGoesOut(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        self::assertSame([], $this->mails, 'the link waits for the answers');
        self::assertCount(1, $this->wpdb->inserts, 'the person only, no token yet');
        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame('before', $this->transients['eventcrew_tg_await_join_555']['step']);
        self::assertSame(1, $this->transients['eventcrew_tg_await_join_555']['person_id']);

        $question = $this->lastCallTo('sendMessage');
        self::assertStringContainsString('Have you worked on the crew here before?', $question['text']);
        self::assertSame(
            ['nwq:y', 'nwq:n', 'nwq:s'],
            array_column(array_merge(...$question['reply_markup']['inline_keyboard']), 'callback_data')
        );
    }

    public function testYesTimesAndATypedTicketCountAreStoredAndThenTheLinkIsSent(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->service()->captureEmail(555, 555, 'sam@example.com');

        $this->queueNewcomer();
        $this->tapJoin('nwq:y');
        $times = $this->lastCallTo('editMessageText');
        self::assertSame('About how many times? Tap a number or type one.', $times['text']);
        self::assertSame('nwq:t:10', $times['reply_markup']['inline_keyboard'][1][4]['callback_data']);
        self::assertTrue($this->service()->isAwaitingJoinNumber(555));

        $this->queueNewcomer(['newcomer:before' => 'yes']);
        $this->tapJoin('nwq:t:5');
        self::assertStringStartsWith('How many free-entry tickets', $this->lastCallTo('editMessageText')['text']);
        self::assertSame([], $this->mails);

        $this->queueNewcomer(['newcomer:before' => 'yes', 'newcomer:times' => '5']);
        $this->service()->captureJoinNumber(555, 555, ' 3 ');

        self::assertSame(
            [
                ['newcomer:before' => 'yes'],
                ['newcomer:before' => 'yes', 'newcomer:times' => '5'],
                ['newcomer:before' => 'yes', 'newcomer:times' => '5', 'newcomer:tickets' => '3'],
            ],
            $this->noticeWrites()
        );
        self::assertArrayNotHasKey('eventcrew_tg_await_join_555', $this->transients);
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('verify?token=', $this->mails[0]['body']);
        self::assertStringStartsWith(
            'Check your email and tap the link to confirm it. Then pick a task on the board.',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    /**
     * The link never came, or the address was wrong: the "Check your email"
     * message has a button that asks for the address again.
     */
    public function testTheCheckYourEmailMessageOffersAnotherEmail(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->service()->captureEmail(555, 555, 'sam@example.com');
        $this->queueNewcomer();
        $this->tapJoin('nwq:s');

        $sent = $this->lastCallTo('sendMessage');
        self::assertStringContainsString('not even in spam', $sent['text']);
        self::assertSame(
            [[['text' => '✏️ Use another email', 'callback_data' => OnboardingService::AGAIN_DATA]]],
            $sent['reply_markup']['inline_keyboard']
        );

        $this->service()->onAgainTap([
            'id' => 'cb2',
            'from' => ['id' => 555, 'first_name' => 'Sam'],
            'message' => ['message_id' => 41, 'chat' => ['id' => 555]],
            'data' => OnboardingService::AGAIN_DATA,
        ]);

        self::assertSame(['inline_keyboard' => []], $this->lastCallTo('editMessageReplyMarkup')['reply_markup']);
        self::assertStringStartsWith("Welcome! What's your email address?", $this->lastCallTo('sendMessage')['text']);
        self::assertTrue($this->service()->isAwaitingEmail(555));
    }

    public function testNoEndsTheQuestionsAndSendsTheLink(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->service()->captureEmail(555, 555, 'sam@example.com');

        $this->queueNewcomer();
        $this->tapJoin('nwq:n');

        self::assertSame([['newcomer:before' => 'no']], $this->noticeWrites());
        self::assertStringContainsString('Thanks', $this->lastCallTo('editMessageText')['text']);
        self::assertSame(['inline_keyboard' => []], $this->lastCallTo('editMessageText')['reply_markup']);
        self::assertCount(1, $this->mails);
        self::assertFalse($this->service()->isAwaitingJoin(555));
    }

    public function testSkipStoresNothingAndSendsTheLink(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->service()->captureEmail(555, 555, 'sam@example.com');

        $this->tapJoin('nwq:s');

        self::assertSame([], $this->noticeWrites());
        self::assertCount(1, $this->mails);
        self::assertCount(1, $this->pendingLinks());
    }

    public function testATypedNumberOutOfRangeIsRefusedAndTheQuestionStays(): void
    {
        $this->transients['eventcrew_tg_await_join_555'] = [
            'step' => 'times', 'person_id' => 1, 'email' => 'sam@example.com', 'name' => 'Sam', 'chat_id' => 555,
        ];

        foreach (['lots', '0', '51', '-2', '2.5'] as $typed) {
            $this->service()->captureJoinNumber(555, 555, $typed);
            self::assertSame('Send a number from 1 to 50, or tap Skip.', $this->lastCallTo('sendMessage')['text'], $typed);
        }

        self::assertSame([], $this->noticeWrites());
        self::assertSame([], $this->mails);
        self::assertSame('times', $this->transients['eventcrew_tg_await_join_555']['step']);
    }

    public function testATapOnAnExpiredQuestionSaysToStartAgain(): void
    {
        $this->tapJoin('nwq:y');

        $answer = $this->lastCallTo('answerCallbackQuery');
        self::assertSame('This question has run out. Send /start to begin again.', $answer['text']);
        self::assertTrue($answer['show_alert']);
        self::assertContains('editMessageReplyMarkup', $this->calledMethods());
        self::assertSame([], $this->mails);
    }

    public function testAnAnswerIsNotStoredOnceTheEmailIsConfirmed(): void
    {
        $this->transients['eventcrew_tg_await_join_555'] = [
            'step' => 'before', 'person_id' => 1, 'email' => 'sam@example.com', 'name' => 'Sam', 'chat_id' => 555,
        ];
        $this->wpdb->nextRows[] = [
            'id' => 1, 'email' => 'sam@example.com', 'email_verified_at' => '2026-11-01 00:00:00', 'approval' => 'waiting',
        ];

        $this->tapJoin('nwq:n');

        self::assertSame([], $this->noticeWrites());
    }

    public function testAnExistingConfirmedPersonIsNotAskedTheQuestions(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = [
            'id' => 9, 'email' => 'sam@example.com', 'email_verified_at' => '2026-01-01 00:00:00', 'approval' => 'ok',
        ];

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        self::assertArrayNotHasKey('eventcrew_tg_await_join_555', $this->transients);
        self::assertCount(1, $this->mails);
    }

    public function testStartAndCancelClearTheQuestions(): void
    {
        $this->transients['eventcrew_tg_await_join_555'] = ['step' => 'before', 'person_id' => 1];

        self::assertTrue($this->service()->cancelPending(555));
        self::assertFalse($this->service()->isAwaitingJoin(555));

        $this->transients['eventcrew_tg_await_join_555'] = ['step' => 'before', 'person_id' => 1];
        $this->service()->start(555, 555, 'Sam');
        self::assertFalse($this->service()->isAwaitingJoin(555));
    }
}
