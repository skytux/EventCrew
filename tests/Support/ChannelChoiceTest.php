<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\PushSubscriptionRepository;
use EventCrew\Support\ChannelChoice;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\PushNotifier;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * App only / Telegram only / Both (Owner 2026-11-13): only one of the two
 * copies of the same message is dropped, and App only still sends the DM
 * when the app notification reached no device.
 */
final class ChannelChoiceTest extends TelegramTestCase
{
    /** @param array<string, mixed> $notices */
    private function person(array $notices = [], bool $telegram = true): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'telegram_user_id' => $telegram ? 555 : null,
            'telegram_chat_id' => $telegram ? 999 : null,
            // Email off for credits, so no mail is made.
            'notify_prefs' => json_encode(['credit' => ['email' => false]]),
            'role_notices' => [] === $notices ? null : json_encode($notices),
        ]);
    }

    private function withChoice(string $choice): Person
    {
        return $this->person([ChannelChoice::KEY => $choice]);
    }

    /**
     * A push double that keeps the real pushAllowed() check and reports
     * $devices devices reached.
     *
     * @param list<string> $pushed
     */
    private function pushTo(int $devices, array &$pushed): void
    {
        PushNotifier::use(new class ($pushed, $devices) extends PushNotifier {
            /** @param list<string> $pushed */
            public function __construct(private array &$pushed, private int $devices)
            {
                parent::__construct(new PushSubscriptionRepository());
            }

            public function send(Person $person, string $type, string $text, string $url = ''): int
            {
                if (! (new NotificationPreferences())->pushAllowed($person, $type)) {
                    return 0;
                }

                $this->pushed[] = $type;

                return $this->devices;
            }
        });
    }

    private function grant(Person $person): void
    {
        (new CreditGrantNotifier(new Mailer(new Logger(), new EmailTemplate(new Logger())), $this->client()))
            ->notify($person);
    }

    public function testUnsetIsBothAndNeverAsked(): void
    {
        $sam = $this->person();

        self::assertSame(ChannelChoice::BOTH, ChannelChoice::of($sam));
        self::assertFalse(ChannelChoice::wasAsked($sam));
        self::assertSame(ChannelChoice::BOTH, ChannelChoice::of($this->person([ChannelChoice::KEY => 'pigeon'])));
    }

    public function testItAppliesOnlyWithTelegramAndADevice(): void
    {
        self::assertFalse(ChannelChoice::applies($this->person()), 'No device lookup: no device.');

        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => 7 === $id);

        self::assertTrue(ChannelChoice::applies($this->person()));
        self::assertTrue(ChannelChoice::due($this->person()));
        self::assertFalse(ChannelChoice::applies($this->person([], false)), 'Without Telegram there is nothing to choose.');
        self::assertFalse(ChannelChoice::due($this->person([ChannelChoice::KEY_ASKED => '2026-11-13 10:00:00'])), 'Asked once.');
        self::assertFalse(ChannelChoice::due($this->withChoice(ChannelChoice::APP)));
    }

    public function testTheStoredEntries(): void
    {
        self::assertSame(
            [ChannelChoice::KEY => 'telegram', ChannelChoice::KEY_ASKED => '2026-11-13 10:00:00'],
            ChannelChoice::entriesFor('telegram', '2026-11-13 10:00:00')
        );
        self::assertSame([ChannelChoice::KEY_ASKED => 'now'], ChannelChoice::askedEntries($this->person(), 'now'));
        self::assertSame([], ChannelChoice::askedEntries($this->withChoice(ChannelChoice::BOTH), 'now'), 'The first time counts.');
        self::assertNull(ChannelChoice::parse('email'));
        self::assertSame(ChannelChoice::CHOICES, array_keys(ChannelChoice::labels()));
    }

    public function testTheDeliveryRule(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $prefs = new NotificationPreferences();
        $credit = NotificationPreferences::CREDIT;

        $both = $this->withChoice(ChannelChoice::BOTH);
        self::assertTrue($prefs->pushAllowed($both, $credit));
        self::assertTrue($prefs->dmWanted($both, $credit, 1));

        $app = $this->withChoice(ChannelChoice::APP);
        self::assertTrue($prefs->pushAllowed($app, $credit));
        self::assertFalse($prefs->dmWanted($app, $credit, 1), 'The app notification arrived: no DM.');
        self::assertTrue($prefs->dmWanted($app, $credit, 0), 'It reached no device: the DM after all.');

        $telegram = $this->withChoice(ChannelChoice::TELEGRAM);
        self::assertFalse($prefs->pushAllowed($telegram, $credit), 'Telegram only: no app notification.');
        self::assertTrue($prefs->dmWanted($telegram, $credit, 0));

        // Telegram only with that DM switched off: the app notification still comes.
        $dmOff = Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 999,
            'notify_prefs' => json_encode(['credit' => ['dm' => false]]),
            'role_notices' => json_encode([ChannelChoice::KEY => ChannelChoice::TELEGRAM]),
        ]);
        self::assertTrue($prefs->pushAllowed($dmOff, $credit));
    }

    public function testAppOnlySkipsTheDmWhenTheAppNotificationArrived(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $pushed = [];
        $this->pushTo(1, $pushed);

        $this->grant($this->withChoice(ChannelChoice::APP));

        self::assertSame(['credit'], $pushed);
        self::assertNotContains('sendMessage', $this->calledMethods());
    }

    public function testAppOnlySendsTheDmWhenNoDeviceWasReached(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $pushed = [];
        $this->pushTo(0, $pushed);

        $this->grant($this->withChoice(ChannelChoice::APP));

        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testTelegramOnlySkipsTheAppNotification(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $pushed = [];
        $this->pushTo(1, $pushed);

        $this->grant($this->withChoice(ChannelChoice::TELEGRAM));

        self::assertSame([], $pushed);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testBothSendsBoth(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $pushed = [];
        $this->pushTo(1, $pushed);

        $this->grant($this->person());

        self::assertSame(['credit'], $pushed);
        self::assertContains('sendMessage', $this->calledMethods());
    }
}
