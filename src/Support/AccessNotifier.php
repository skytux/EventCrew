<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * Tells a person, by Telegram and email, that an organizer gave them
 * something: a role, a one-time sign-up pass, or organizer rights. One sender
 * for every place that gives them - the bot's /people, the crew page's Admin
 * tab (the role and pass forms, editing a person, adding someone to a task of
 * a role they lack) and the wp-admin People page - because it listens to the
 * hooks those places fire: RoleNotifier::GIVEN_HOOK,
 * RoleNotifier::ORGANIZER_HOOK and PASS_HOOK.
 *
 * Only what is given is told; taking a role or organizer rights away sends
 * nothing. Each channel is sent only when the person allows "Permission
 * changes" on it (NotificationPreferences::PERMISSION), in the person's own
 * language. The texts are the Messages page pairs access_role /
 * access_role_dm, access_pass / access_pass_dm and access_organizer /
 * access_organizer_dm: the emails name the crew page, the DMs the bot.
 */
final class AccessNotifier
{
    /** Fired with (int $personId) when an organizer gives someone a one-time sign-up pass. */
    public const PASS_HOOK = 'eventcrew/pass_given';

    private const ROLE = 'access_role';
    private const PASS = 'access_pass';
    private const ORGANIZER = 'access_organizer';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    /** Fires PASS_HOOK. Call it after the pass is written. */
    public static function passGranted(int $personId): void
    {
        do_action(self::PASS_HOOK, $personId);
    }

    /** The RoleNotifier::GIVEN_HOOK listener. */
    public function roleGiven(int $personId, string $slug): void
    {
        $this->tell($personId, self::ROLE, $slug);
    }

    /** The PASS_HOOK listener. */
    public function passGiven(int $personId): void
    {
        $this->tell($personId, self::PASS);
    }

    /** The RoleNotifier::ORGANIZER_HOOK listener. */
    public function organizerGiven(int $personId): void
    {
        $this->tell($personId, self::ORGANIZER);
    }

    private function tell(int $personId, string $template, string $slug = ''): void
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized()) {
            return;
        }

        Language::forPerson($person, fn () => $this->send($person, $template, $slug));
    }

    /** Both channels the person allows, in the language that is current. */
    private function send(Person $person, string $template, string $slug): void
    {
        $context = ['person' => $person, 'role' => '' === $slug ? '' : Roles::label($slug)];
        $prefs = new NotificationPreferences();

        if ($prefs->dmAllowed($person, NotificationPreferences::PERMISSION)) {
            // Unprompted from the person's side: an organizer, not they,
            // pressed the button that caused this.
            $this->telegram->sendMessage(
                (int) $person->telegramChatId,
                DmBody::frame($person, MessageTemplates::body($template . '_dm', $context))
            );
        }

        if ($prefs->emailAllowed($person, NotificationPreferences::PERMISSION)) {
            $this->mailer->toPerson(
                $person->id,
                $person->email,
                MessageTemplates::subject($template, $context),
                EmailBody::withGreeting($person->displayName, MessageTemplates::body($template, $context)),
                $this->actions($template)
            );
        }
    }

    /**
     * The email's button: the crew page's open tasks for a role or a pass,
     * its Admin tab for a new organizer. None when no crew page is set.
     *
     * @return array<int, array{label: string, url: string}>
     */
    private function actions(string $template): array
    {
        $url = self::ORGANIZER === $template
            ? HelpPage::url(DoorLinkSender::ADMIN_ANCHOR)
            : $this->mailer->boardUrl();

        if ('' === $url) {
            return [];
        }

        $label = self::ORGANIZER === $template
            ? DoorLinkSender::adminButtonLabel()
            : __('See open tasks', 'eventcrew');

        return [['label' => $label, 'url' => $url]];
    }
}
