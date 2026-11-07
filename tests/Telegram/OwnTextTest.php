<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\OwnTextNotice;
use EventCrew\Telegram\FollowupCommand;
use EventCrew\Telegram\NewsletterCommand;
use EventCrew\Web\CrewAdminFollowup;
use EventCrew\Web\CrewAdminNewsletter;

/**
 * Owner: the organizers' own texts (/followup, /newsletter, the crew app's
 * Admin tab) tell the other organizers who set one, warn before one replaces
 * another, and can be reset to the default.
 *
 * Person 9 (Telegram 555, "Anna") makes the changes. Person 10 ("Ben") has a
 * chat with the bot, person 11 ("Cai") only a confirmed email.
 */
final class OwnTextTest extends TelegramTestCase
{
    private Mailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });

        $this->mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()), true);
    }

    private function notice(): OwnTextNotice
    {
        return new OwnTextNotice(new PersonRepository(), $this->client(), $this->mailer);
    }

    private function followup(): FollowupCommand
    {
        return new FollowupCommand(
            new PersonRepository(),
            new FollowupText(new LlmClient(new Logger()), new Logger()),
            $this->client(),
            static fn (\Closure $work) => $work(),
            $this->notice()
        );
    }

    private function newsletter(): NewsletterCommand
    {
        return new NewsletterCommand(
            new PersonRepository(),
            new NewsletterText(new LlmClient(new Logger()), new Logger()),
            $this->client(),
            $this->notice()
        );
    }

    /** @return array<string, mixed> */
    private static function anna(): array
    {
        return [
            'id' => 9,
            'email' => 'anna@example.test',
            'display_name' => 'Anna',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
            'is_organizer' => 1,
            'email_verified_at' => '2026-01-01 00:00:00',
        ];
    }

    /** The organizers the notice goes through: Anna (who acts), Ben (Telegram) and Cai (email). */
    private function queueOrganizers(): void
    {
        $this->wpdb->nextResults[] = [
            self::anna(),
            [
                'id' => 10,
                'email' => 'ben@example.test',
                'display_name' => 'Ben',
                'telegram_user_id' => 600,
                'telegram_chat_id' => 600,
                'is_organizer' => 1,
            ],
            [
                'id' => 11,
                'email' => 'cai@example.test',
                'display_name' => 'Cai',
                'is_organizer' => 1,
                'email_verified_at' => '2026-01-01 00:00:00',
            ],
        ];
    }

    /** @return list<array<string, mixed>> The messages sent to chat $chatId. */
    private function sentTo(int $chatId): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['body'],
            array_filter(
                $this->telegramCalls,
                static fn (array $call): bool => 'sendMessage' === $call['method']
                    && $chatId === (int) ($call['body']['chat_id'] ?? 0)
            )
        ));
    }

    /** @return array<string, mixed> */
    private static function tap(string $data): array
    {
        return [
            'id' => 'cb1',
            'from' => ['id' => 555],
            'message' => ['message_id' => 40, 'chat' => ['id' => 555, 'type' => 'private']],
            'data' => $data,
        ];
    }

    /** A one-time thank-you text Ben set earlier. */
    private function bensText(): void
    {
        $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]] = [
            'en' => 'Ben thanks you',
            'source' => 'en',
            'translated' => false,
            'written' => 'Ben thanks you',
            'by' => 10,
            'at' => '2026-11-06 18:30:00',
            'rev' => 1,
        ];
    }

    public function testSettingATextTellsTheOtherOrganizersNotTheOneWhoSetIt(): void
    {
        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();

        $this->followup()->onCommand(555, 555, true, 'came Thanks, all!');

        $ben = $this->sentTo(600);
        self::assertCount(1, $ben);
        self::assertStringContainsString('Post-event email · Thank-you text (came)', $ben[0]['text']);
        self::assertStringContainsString("Anna set this text:\n\nThanks, all!", $ben[0]['text']);
        self::assertStringContainsString('/followup', $ben[0]['text']);

        $mails = $this->mailer->captured();
        self::assertCount(1, $mails, 'Cai, who has no chat with the bot, gets an email; Anna nothing');
        self::assertSame('cai@example.test', $mails[0]['to']);
        self::assertStringContainsString('Thanks, all!', $mails[0]['text']);

        $own = $this->sentTo(555);
        self::assertCount(1, $own, 'Anna gets her own answer only');
        self::assertStringContainsString('Saved for the next post-event email only', $own[0]['text']);
        self::assertSame('fu:reset:came', $this->callbackOf($own[0], '↩️ Reset to default'));
    }

    public function testSettingOverAnEarlierTextShowsItAndWhoSetItFirst(): void
    {
        $this->bensText();
        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = ['id' => 10, 'email' => 'ben@example.test', 'display_name' => 'Ben'];

        $this->followup()->onCommand(555, 555, true, 'came Thanks, all!');

        $reply = $this->lastCallTo('sendMessage') ?? [];
        self::assertStringContainsString('There is already a one-time text', $reply['text']);
        self::assertStringContainsString('Ben thanks you', $reply['text']);
        self::assertStringContainsString('Set by Ben on 06.11.2026 at 18:30.', $reply['text']);
        self::assertNotNull($this->callbackOf($reply, '✅ Replace'));
        self::assertNotNull($this->callbackOf($reply, '✖️ Keep'));
        self::assertSame(
            'Ben thanks you',
            $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['en'],
            'nothing is replaced before the answer'
        );
    }

    public function testReplaceSavesTheNewTextAndKeepKeepsTheOld(): void
    {
        $this->bensText();
        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = ['id' => 10, 'email' => 'ben@example.test', 'display_name' => 'Ben'];
        $this->followup()->onCommand(555, 555, true, 'came Thanks, all!');
        $keep = (string) $this->callbackOf($this->lastCallTo('sendMessage') ?? [], '✖️ Keep');

        $this->wpdb->nextRows[] = self::anna();
        $this->followup()->onCallback(self::tap($keep));

        self::assertStringContainsString('Kept the current text', (string) ($this->lastCallTo('sendMessage')['text'] ?? ''));
        self::assertSame('Ben thanks you', $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['en']);

        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = ['id' => 10, 'email' => 'ben@example.test', 'display_name' => 'Ben'];
        $this->followup()->onCommand(555, 555, true, 'came Thanks, all!');
        $replace = (string) $this->callbackOf($this->lastCallTo('sendMessage') ?? [], '✅ Replace');

        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();
        $this->followup()->onCallback(self::tap($replace));

        self::assertSame('Thanks, all!', $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['en']);
        self::assertSame(9, $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['by']);
        self::assertCount(1, $this->sentTo(600), 'Ben is told his text was replaced');

        // Used once.
        $this->wpdb->nextRows[] = self::anna();
        $this->followup()->onCallback(self::tap($replace));
        self::assertStringContainsString('expired', (string) ($this->lastCallTo('answerCallbackQuery')['text'] ?? ''));
    }

    public function testTheHelpersConfirmedActionReplacesWithoutAskingAgain(): void
    {
        $this->bensText();
        $this->wpdb->nextRows[] = ['id' => 10, 'email' => 'ben@example.test', 'display_name' => 'Ben'];

        $warning = $this->followup()->replaceWarning('came Thanks, all!');
        self::assertStringContainsString('Ben thanks you', $warning);
        self::assertStringContainsString('Set by Ben', $warning);
        self::assertSame('', $this->followup()->replaceWarning('missed Sorry!'), 'no earlier sorry text');

        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();
        $this->followup()->onCommand(555, 555, true, 'came Thanks, all!', true);

        self::assertSame('Thanks, all!', $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['en']);
    }

    public function testTheResetButtonAndCommandPutTheDefaultBackAndTellTheOthers(): void
    {
        $this->bensText();
        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();

        $this->followup()->onCallback(self::tap('fu:reset:came'));

        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
        self::assertStringContainsString('Messages page', (string) ($this->lastCallTo('sendMessage')['text'] ?? ''));
        self::assertStringContainsString('Anna reset it to the default.', $this->sentTo(600)[0]['text']);

        $this->bensText();
        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();

        $this->followup()->onCommand(555, 555, true, 'came reset');

        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
        self::assertCount(2, $this->sentTo(600));
    }

    public function testTheTextsShownCarryWhoSetThemAndTheResetButton(): void
    {
        $this->bensText();
        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = ['id' => 10, 'email' => 'ben@example.test', 'display_name' => 'Ben'];

        $this->followup()->onCommand(555, 555, true, '');

        $reply = $this->lastCallTo('sendMessage') ?? [];
        self::assertStringContainsString('Set by Ben on 06.11.2026', $reply['text']);
        self::assertStringContainsString('/followup came reset', $reply['text']);
        self::assertSame('fu:reset:came', $this->callbackOf($reply, '↩️ Reset to default'));
    }

    public function testTheNewsletterTextWarnsReplacesAndResetsTheSameWay(): void
    {
        $this->options[NewsletterText::OPTION] = [
            'fi' => 'Moi',
            'en' => 'Hi',
            'translated' => true,
            'written' => 'Hi',
            'by' => 10,
            'at' => '2026-11-06 18:30:00',
            'rev' => 1,
        ];
        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = ['id' => 10, 'email' => 'ben@example.test', 'display_name' => 'Ben'];

        $this->newsletter()->onCommand(555, 555, true, 'Doors at 19.');

        $ask = $this->lastCallTo('sendMessage') ?? [];
        self::assertStringContainsString('There is already a text for the next announcement', $ask['text']);
        self::assertStringContainsString('Set by Ben on 06.11.2026 at 18:30.', $ask['text']);
        self::assertSame('Hi', $this->options[NewsletterText::OPTION]['en']);

        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();
        $this->newsletter()->onCallback(self::tap((string) $this->callbackOf($ask, '✅ Replace')));

        self::assertSame('Doors at 19.', $this->options[NewsletterText::OPTION]['en']);
        self::assertStringContainsString("Next announcement\nAnna set this text:", $this->sentTo(600)[0]['text']);
        $saved = $this->lastCallTo('sendMessage') ?? [];
        self::assertSame(555, (int) $saved['chat_id']);
        self::assertSame('nl:reset', $this->callbackOf($saved, '↩️ Reset to default'));

        $this->wpdb->nextRows[] = self::anna();
        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();
        $this->newsletter()->onCommand(555, 555, true, 'reset');

        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
        self::assertStringContainsString('Anna reset it to the default.', $this->sentTo(600)[1]['text']);
    }

    public function testSomeoneWhoIsNotAnOrganizerCannotTapReset(): void
    {
        $this->bensText();
        $this->wpdb->nextRows[] = ['id' => 12, 'email' => 'x@example.test', 'telegram_user_id' => 555];

        $this->followup()->onCallback(self::tap('fu:reset:came'));

        self::assertArrayHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
    }

    public function testTheAdminTabAsksToTickReplaceAndTellsTheOthers(): void
    {
        $this->bensText();
        $screen = new CrewAdminFollowup(new FollowupText(new LlmClient(new Logger()), new Logger()), $this->notice());

        self::assertSame('admin_followup_exists', $screen->saveText(9, FollowupText::CAME, 'save', 'Mine', true, false));
        self::assertSame('Ben thanks you', $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['en']);

        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();
        $screen->saveText(9, FollowupText::CAME, 'save', 'Mine', true, true);

        self::assertSame('Mine', $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['en']);
        self::assertStringContainsString("Anna set this text:\n\nMine", $this->sentTo(600)[0]['text']);

        $news = new CrewAdminNewsletter(
            (new \ReflectionClass(\EventCrew\Support\Newsletter::class))->newInstanceWithoutConstructor(),
            new NewsletterText(new LlmClient(new Logger()), new Logger()),
            $this->notice()
        );
        $this->options[NewsletterText::OPTION] = ['en' => 'Hi', 'by' => 10, 'rev' => 1];

        self::assertSame('admin_newsletter_exists', $news->saveText(9, 'save', 'Mine', true, false));

        $this->wpdb->nextRows[] = self::anna();
        $this->queueOrganizers();
        self::assertSame('admin_newsletter_cleared', $news->saveText(9, 'clear', '', true, false));
        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
        self::assertStringContainsString('Anna reset it to the default.', $this->sentTo(600)[1]['text']);
    }
}
