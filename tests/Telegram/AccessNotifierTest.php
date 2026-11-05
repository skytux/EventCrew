<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AccessNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\Roles;

/**
 * The person hears, by Telegram and email, that an organizer gave them a role,
 * a one-time pass or organizer rights: the access_* pairs of the Messages page,
 * each channel only when the person allows permission changes on it.
 */
final class AccessNotifierTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');

        // The plain-text mail, so the whole text can be compared.
        $this->options[EmailTemplate::HTML_OPTION] = false;
        $this->options['eventcrew_signup_page_id'] = 897;
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'dj', 'label' => 'DJ', 'restricted' => true],
        ];
    }

    private function notifier(): AccessNotifier
    {
        return new AccessNotifier(
            new PersonRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $this->client()
        );
    }

    /**
     * Sam, found by the notifier.
     *
     * @param array<string, mixed> $extra
     */
    private function sam(array $extra = []): void
    {
        $this->wpdb->nextRows[] = $extra + [
            'id' => 8,
            'display_name' => 'Sam Lee',
            'email' => 'sam@example.com',
            'telegram_chat_id' => 999,
        ];
    }

    /** The email without its manage-account footer. */
    private function mailText(int $index = 0): string
    {
        $body = $this->mails[$index]['body'];

        return $this->mails[$index]['subject'] . "\n---\n" . substr($body, 0, (int) strpos($body, "\n\n—\n"));
    }

    private function dm(): string
    {
        $dm = $this->lastCallTo('sendMessage');
        self::assertNotNull($dm);
        self::assertSame(999, $dm['chat_id']);

        return $dm['text'];
    }

    // ---- What each message says ------------------------------------------

    public function testARoleIsToldByTelegramAndEmail(): void
    {
        $this->sam();

        $this->notifier()->roleGiven(8, 'dj');

        self::assertSame(
            "📣 Test Site\n\nHi Sam Lee,\n\nYou now have the role DJ. Its tasks are shown to you with /board in a"
                . ' private chat with the bot and in the Crew app, and you can sign up for them.',
            $this->dm()
        );
        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertSame(
            "You have a new role: DJ\n---\nHi Sam,\n\nYou now have the role DJ. Its tasks are open to you on the crew"
                . " page, and you can sign up for them.\n\nSee open tasks: https://site.test/crew/",
            $this->mailText()
        );
    }

    public function testAPassIsToldByTelegramAndEmail(): void
    {
        $this->sam();

        $this->notifier()->passGiven(8);

        self::assertSame(
            "📣 Test Site\n\nHi Sam Lee,\n\n🎟 You’ve a one-time pass to sign up for your next task, even while your"
                . ' standing is low.',
            $this->dm()
        );
        self::assertSame(
            "You have a one-time pass\n---\nHi Sam,\n\nYou have a one-time pass to sign up for your next task, even"
                . ' while your standing is low. It is used up the next time you sign up.'
                . "\n\nSee open tasks: https://site.test/crew/",
            $this->mailText()
        );
    }

    public function testOrganizerRightsAreToldByTelegramAndEmail(): void
    {
        $this->sam();

        $this->notifier()->organizerGiven(8);

        self::assertSame("📣 Test Site\n\nHi Sam Lee,\n\n⭐ You’re now an organizer of Test Site.", $this->dm());
        self::assertSame(
            "You are now an organizer of Test Site.\n---\nHi Sam,\n\nYou are now an organizer. The crew page has an"
                . ' Admin tab for you, where you manage tasks and people.'
                . "\n\n🛠️ Open the Admin tab: https://site.test/crew/#eventcrew-panel-admin",
            $this->mailText()
        );
    }

    /** A person with no Telegram chat gets the same email, and no DM. */
    public function testAWebOnlyPersonGetsTheEmailOnly(): void
    {
        $this->sam(['telegram_chat_id' => null]);

        $this->notifier()->roleGiven(8, 'dj');

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('Its tasks are open to you on the crew page', $this->mails[0]['body']);
        self::assertStringNotContainsString('/board', $this->mails[0]['body']);
    }

    /** With no crew page set, the email has no button. */
    public function testWithNoCrewPageTheEmailHasNoButton(): void
    {
        unset($this->options['eventcrew_signup_page_id']);
        $this->sam();
        $this->notifier()->organizerGiven(8);
        $this->sam();
        $this->notifier()->passGiven(8);

        self::assertStringNotContainsString('Admin tab:', $this->mails[0]['body']);
        self::assertStringNotContainsString('See open tasks', $this->mails[1]['body']);
    }

    public function testTheMessagesAreInThePersonsLanguage(): void
    {
        $this->sam(['language' => Language::FINNISH]);

        $this->notifier()->roleGiven(8, 'dj');

        self::assertStringContainsString(
            'Sinulla on nyt rooli DJ. Näet sen tehtävät komennolla /board yksityiskeskustelussa botin kanssa ja'
                . ' Crew-sovelluksessa, ja voit ilmoittautua niihin.',
            $this->dm()
        );
        self::assertSame('Sinulla on uusi rooli: DJ', $this->mails[0]['subject']);
        self::assertStringStartsWith('Hei Sam,', $this->mails[0]['body']);
    }

    // ---- Preferences -----------------------------------------------------

    public function testTelegramOffSendsTheEmailOnly(): void
    {
        $this->sam(['notify_prefs' => json_encode(['permission' => ['dm' => false, 'email' => true]])]);

        $this->notifier()->passGiven(8);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertCount(1, $this->mails);
    }

    public function testEmailOffSendsTheTelegramMessageOnly(): void
    {
        $this->sam(['notify_prefs' => json_encode(['permission' => ['dm' => true, 'email' => false]])]);

        $this->notifier()->organizerGiven(8);

        self::assertNotNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    public function testBothOffSendsNothing(): void
    {
        $this->sam(['notify_prefs' => json_encode(['permission' => ['dm' => false, 'email' => false]])]);

        $this->notifier()->roleGiven(8, 'dj');

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    public function testAnErasedOrMissingPersonIsNotTold(): void
    {
        $this->sam(['anonymized_at' => '2026-01-01 00:00:00']);
        $this->notifier()->roleGiven(8, 'dj');
        $this->notifier()->passGiven(0);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    // ---- Stored texts ----------------------------------------------------

    private function store(string $id, string $part, string $text): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . $id][$part] = [
            'source' => $text,
            'texts' => ['en' => $text],
            'manual' => ['en' => false],
        ];
    }

    public function testStoredTextsAreUsed(): void
    {
        $this->store('access_role_dm', MessageTemplates::BODY, 'You are a {role} now.');
        $this->store('access_role', MessageTemplates::SUBJECT, 'Hello {role}');
        $this->store('access_role', MessageTemplates::BODY, 'Welcome to {role}.');
        $this->sam();

        $this->notifier()->roleGiven(8, 'dj');

        self::assertStringEndsWith('You are a DJ now.', $this->dm());
        self::assertSame('Hello DJ', $this->mails[0]['subject']);
        self::assertStringStartsWith("Hi Sam,\n\nWelcome to DJ.", $this->mails[0]['body']);
    }

    /** The old single email is gone: a text stored for it is ignored. */
    public function testAnOldStoredAccessChangedEmailIsIgnored(): void
    {
        $this->store('access_changed', MessageTemplates::SUBJECT, 'News, {name}');
        $this->store('access_changed', MessageTemplates::BODY, 'Dear {name}: {change}');
        $this->sam();

        $this->notifier()->passGiven(8);

        self::assertNull(MessageTemplates::find('access_changed'));
        self::assertSame('You have a one-time pass', $this->mails[0]['subject']);
        self::assertStringNotContainsString('Dear', $this->mails[0]['body']);
    }
}
