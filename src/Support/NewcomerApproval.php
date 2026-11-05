<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * The team's OK for a newcomer.
 *
 * Someone who signs themselves up (the web sign-in form, the bot's /start)
 * is created waiting (Person::APPROVAL_WAITING). They can sign in and see
 * the board, but SignupService refuses their claims until an organizer
 * says OK.
 *
 * - When they confirm their email the first time, the organizers get
 *   "New: <name> wants to join": by Telegram with OK and No buttons
 *   (organizers with Telegram) and by email with a button to the Admin
 *   tab. Each organizer's NotificationPreferences::NEWCOMER row switches
 *   either route off; both are on by default.
 * - The first organizer to press OK or No decides
 *   (PersonRepository::decideNewcomer()); a later press is told who did.
 * - On OK the newcomer is told: by Telegram if linked, else by email. On
 *   No they are not messaged; the board shows a short neutral text.
 * - A person who is given a role or a one-time pass is OK from then on.
 *
 * People an organizer adds are never waiting, and everyone already in the
 * list at schema 33 is OK.
 */
final class NewcomerApproval
{
    /** Callback data prefix of the OK and No buttons: "nco:ok:<id>", "nco:no:<id>". */
    public const PREFIX = 'nco:';

    /** decide(): this press made the decision. */
    public const DECIDED = 'decided';

    /** decide(): someone else had decided already. */
    public const ALREADY = 'already';

    /** decide(): no such person, or they are not a newcomer. */
    public const UNKNOWN = 'unknown';

    /** The role_notices key that records the organizers were asked. */
    private const ASKED_KEY = 'newcomer:asked';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/email_verified', [$this, 'onEmailVerified']);

        // Before the person's own role and pass messages (priority 10).
        add_action(RoleNotifier::GIVEN_HOOK, [$this, 'approveByAccess'], 5);
        add_action(AccessNotifier::PASS_HOOK, [$this, 'approveByAccess'], 5);
    }

    /**
     * The OK and No buttons under the organizers' Telegram notice.
     *
     * @return array<string, mixed>
     */
    public static function markup(int $personId): array
    {
        return ['inline_keyboard' => [[
            ['text' => '✅ ' . __('OK', 'eventcrew'), 'callback_data' => self::PREFIX . 'ok:' . $personId],
            ['text' => '❌ ' . __('No', 'eventcrew'), 'callback_data' => self::PREFIX . 'no:' . $personId],
        ]]];
    }

    /**
     * A confirmed address. A waiting newcomer's first one asks the
     * organizers; every later one (each web sign-in re-confirms) does
     * nothing.
     */
    public function onEmailVerified(int $personId): void
    {
        $person = $this->people->find($personId);

        if (null === $person || ! $person->isWaitingForOk() || $person->hasRoleNotice(self::ASKED_KEY)) {
            return;
        }

        // Recorded first, so a second confirmation in the same moment does
        // not ask twice.
        $this->people->markRoleNotice($person->id, self::ASKED_KEY);

        foreach ($this->people->organizers() as $organizer) {
            Language::forPerson($organizer, fn () => $this->ask($organizer, $person));
        }
    }

    /** RoleNotifier::GIVEN_HOOK and AccessNotifier::PASS_HOOK: a role or a pass makes the person OK. */
    public function approveByAccess(int $personId): void
    {
        if ($personId > 0) {
            $this->people->approve($personId);
        }
    }

    /**
     * An organizer pressed OK or No (in Telegram or on the crew page).
     * Returns self::DECIDED, self::ALREADY or self::UNKNOWN. On OK the
     * newcomer is told.
     */
    public function decide(int $personId, bool $ok, Person $organizer): string
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized()) {
            return self::UNKNOWN;
        }

        if (! $this->people->decideNewcomer($person->id, $ok, $organizer->name())) {
            return self::ALREADY;
        }

        if ($ok) {
            Language::forPerson($person, fn () => $this->tellOk($person));
        }

        return self::DECIDED;
    }

    /**
     * A tap on OK or No under the Telegram notice. A message can be
     * forwarded, so the person tapping must be an organizer.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onTap(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $data = (string) ($callbackQuery['data'] ?? '');

        if (1 !== preg_match('/^nco:(ok|no):(\d+)$/', $data, $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $organizer = 0 === $fromId ? null : $this->people->findByTelegramUserId($fromId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->answerCallbackQuery($callbackId, __('Only organizers can answer this.', 'eventcrew'), true);

            return;
        }

        $personId = (int) $m[2];
        $outcome = $this->decide($personId, 'ok' === $m[1], $organizer);

        if (self::UNKNOWN === $outcome) {
            $this->telegram->answerCallbackQuery($callbackId, __('That person was not found.', 'eventcrew'), true);
            $this->replace($message, '');

            return;
        }

        $person = $this->people->find($personId);
        $line = null === $person ? '' : self::decisionLine($person);

        if (self::ALREADY === $outcome) {
            $this->telegram->answerCallbackQuery($callbackId, $line, true);
        } else {
            $this->telegram->answerCallbackQuery($callbackId);
        }

        $this->replace($message, $line);
    }

    /**
     * "OK by <name>." or "No by <name>.": who decided, for a later press and
     * under the decided notice.
     */
    public static function decisionLine(Person $person): string
    {
        $by = '' !== $person->approvalBy ? $person->approvalBy : __('an organizer', 'eventcrew');

        return $person->isTurnedDown()
            /* translators: %s: the name of the organizer who said No */
            ? '❌ ' . sprintf(__('No, by %s.', 'eventcrew'), $by)
            /* translators: %s: the name of the organizer who said OK */
            : '✅ ' . sprintf(__('OK, by %s.', 'eventcrew'), $by);
    }

    /**
     * One organizer, in their language: by Telegram (if they have it) and by
     * email, each unless they switched that route off.
     */
    private function ask(Person $organizer, Person $person): void
    {
        $context = ['member' => $person];
        $prefs = new NotificationPreferences();

        if ($prefs->dmAllowed($organizer, NotificationPreferences::NEWCOMER)) {
            $this->telegram->sendMessage(
                $organizer->telegramChatId,
                DmBody::frame($organizer, MessageTemplates::body('newcomer_waiting_dm', $context)),
                self::markup($person->id)
            );
        }

        if (! $prefs->emailAllowed($organizer, NotificationPreferences::NEWCOMER)) {
            return;
        }

        $url = HelpPage::url(DoorLinkSender::ADMIN_ANCHOR);

        $this->mailer->toPerson(
            $organizer->id,
            $organizer->email,
            MessageTemplates::subject('newcomer_waiting', $context),
            MessageTemplates::body('newcomer_waiting', $context),
            '' === $url ? [] : [['label' => DoorLinkSender::adminButtonLabel(), 'url' => $url]]
        );
    }

    /** The newcomer's own "the team said OK": Telegram if linked, else email. */
    private function tellOk(Person $person): void
    {
        if (null !== $person->telegramChatId) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                DmBody::frame($person, MessageTemplates::body('newcomer_ok_dm'))
            );

            return;
        }

        $url = $this->mailer->boardUrl();

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('newcomer_ok'),
            EmailBody::withGreeting($person->displayName, MessageTemplates::body('newcomer_ok')),
            '' === $url ? [] : [['label' => __('See open tasks', 'eventcrew'), 'url' => $url]]
        );
    }

    /**
     * The notice keeps its text, gets $line under it, and loses its buttons,
     * so it cannot be pressed again from the same message.
     *
     * @param array<string, mixed> $message
     */
    private function replace(array $message, string $line): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $text = (string) ($message['text'] ?? '');
        $text = '' === $line ? $text : ('' === $text ? $line : $text . "\n\n" . $line);

        if ('' === $text) {
            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => []]);
    }
}
