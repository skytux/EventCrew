<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\NotificationPreferences;

/**
 * 🔔 Notifications (a button under /me) - a keyboard menu for a person to choose which notifications
 * reach them, and on which channel, like the web tab: per type its name (a no-op
 * row) and a row of three toggles, 📱 Telegram, ✉ email and 🔔 app (Web Push);
 * tapping a toggle flips it and edits the message in place.
 *
 * The same per-type, per-channel preferences the web Notifications tab edits, so
 * a change in one shows in the other.
 *
 * The last row is 📰 Newsletter (Owner, 2026-11-08): the announcement email, on
 * or off for the person's verified address, with a ✉ toggle only. On adds the
 * address as a confirmed subscriber (it is verified, so no confirmation email)
 * or brings back one who left; off unsubscribes it.
 */
final class NotificationSettingsService
{
    use GroupBreadcrumb;

    /** Callback data prefix for a toggle: np:<type>:<dm|email|push>. */
    private const PREFIX = 'np:';

    /** The newsletter row's callback data. */
    private const NEWSLETTER = 'np:newsletter';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        // Optional: without it there is no newsletter row.
        private readonly ?NewsletterSubscriberRepository $subscribers = null
    ) {
    }

    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person) {
            // Not known yet, so the bot may not be able to DM them: the nudge
            // goes back to wherever they asked, in English in the group.
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        if (! $person->isEmailVerified()) {
            // Known, so the refusal goes to the DM, with a breadcrumb in the group.
            $this->telegram->sendMessage($telegramUserId, SetupNudge::text());
            $this->sentDmNote($chatId, $isPrivate);

            return;
        }

        $map = (new NotificationPreferences())->asMap($person);
        $this->telegram->sendMessage(
            $telegramUserId,
            $this->text(),
            ['inline_keyboard' => $this->keyboard($map, $this->subscribed($person))]
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * A toggle tap: flip that one type+channel and edit the menu in place.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onToggle(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $data = (string) ($callbackQuery['data'] ?? '');
        [$type, $channel] = $this->parse($data);

        $person = 0 === $telegramUserId ? null : $this->people->findByTelegramUserId($telegramUserId);

        if (self::NEWSLETTER === $data && null !== $person && null !== $this->subscribers) {
            $this->toggleNewsletter($person, $callbackQuery);

            return;
        }

        if (null === $person || '' === $type) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $map = (new NotificationPreferences())->asMap($person);

        if (! isset($map[$type]) || ! in_array($channel, NotificationPreferences::channelsFor($type), true)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $map[$type][$channel] = ! ($map[$type][$channel] ?? false);
        $this->people->setNotifyPrefs($person->id, $map);
        $this->telegram->answerCallbackQuery($callbackId, __('Saved.', 'eventcrew'));

        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 !== $chatId && 0 !== $messageId) {
            $this->telegram->editMessageText(
                $chatId,
                $messageId,
                $this->text(),
                ['inline_keyboard' => $this->keyboard($map, $this->subscribed($person))]
            );
        }
    }

    /**
     * Whether the person's address gets the newsletter. null when there is no
     * newsletter row: no subscriber store, or no verified address.
     */
    private function subscribed(Person $person): ?bool
    {
        if (null === $this->subscribers || ! $person->isEmailVerified() || '' === trim($person->email)) {
            return null;
        }

        $row = $this->subscribers->findByEmail($person->email);

        return null !== $row && NewsletterSubscriberRepository::STATUS_CONFIRMED === ($row['status'] ?? '');
    }

    /**
     * The 📰 Newsletter tap: on adds the verified address as confirmed (or
     * brings it back), off unsubscribes it. Then the menu is edited in place.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function toggleNewsletter(Person $person, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $on = $this->subscribed($person);

        if (null === $on || null === $this->subscribers) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        if ($on) {
            $row = $this->subscribers->findByEmail($person->email);
            $done = null !== $row && $this->subscribers->unsubscribe((int) $row['id']);
        } else {
            $first = DoorDesk::firstName($person->name());
            $done = $this->subscribers->addFromTicket($person->email, $first, (string) $person->language) > 0;
        }

        $this->telegram->answerCallbackQuery(
            $callbackId,
            $done ? __('Saved.', 'eventcrew') : __('Something went wrong. Please try again.', 'eventcrew')
        );

        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 !== $chatId && 0 !== $messageId) {
            $map = (new NotificationPreferences())->asMap($person);
            $this->telegram->editMessageText(
                $chatId,
                $messageId,
                $this->text(),
                ['inline_keyboard' => $this->keyboard($map, $this->subscribed($person))]
            );
        }
    }

    private function text(): string
    {
        // The comment sits outside __( so tools/make-pot.php still reads the literal.
        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        return __("🔔 Your notifications — tap to toggle 📱 Telegram, ✉ email or 🔔 app.\n\nSignup confirmations and task reminders always send on every channel.", 'eventcrew');
    }

    /**
     * Per type, a row with its name (a no-op, so a tap on the label never
     * changes a pref) and under it a row of its three toggles, each with its
     * channel's sign: three toggles beside a name left the name too little
     * room on a phone.
     *
     * $newsletter: whether the address gets the newsletter; null leaves the
     * newsletter row out.
     *
     * @param array<string, array{dm: bool, email: bool, push?: bool}> $map
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function keyboard(array $map, ?bool $newsletter = null): array
    {
        $rows = [];
        $mark = static fn (bool $on): string => $on ? '✅' : '❌';

        // The map holds only the rows this person sees (asMap), so the
        // organizer-only rows reach the organizers alone.
        $labels = NotificationPreferences::labels(true);

        foreach ($map as $type => $pref) {
            $label = $labels[$type] ?? '';

            if ('' === $label) {
                continue;
            }

            $signs = ['dm' => '📱 ', 'email' => '✉ ', 'push' => '🔔 '];
            $row = [];

            // Only the channels the type is sent on: the group chat is the app alone.
            foreach (NotificationPreferences::channelsFor($type) as $channel) {
                $row[] = [
                    'text' => $signs[$channel] . $mark((bool) ($pref[$channel] ?? false)),
                    'callback_data' => self::PREFIX . $type . ':' . $channel,
                ];
            }

            $rows[] = [['text' => $label, 'callback_data' => self::PREFIX . 'noop']];
            $rows[] = $row;
        }

        // The newsletter is email only.
        if (null !== $newsletter) {
            $rows[] = [['text' => '📰 ' . __('Newsletter', 'eventcrew'), 'callback_data' => self::PREFIX . 'noop']];
            $rows[] = [['text' => '✉ ' . $mark($newsletter), 'callback_data' => self::NEWSLETTER]];
        }

        // Back to Settings, where 🔔 Notifications is.
        $rows[] = MenuButtons::backRow(MenuButtons::SETTINGS);

        return $rows;
    }

    /**
     * @return array{0: string, 1: string} type, channel ('dm'|'email'|'push')
     */
    private function parse(string $data): array
    {
        if (1 !== preg_match('#^' . preg_quote(self::PREFIX, '#') . '([a-z_]+):(dm|email|push)$#', $data, $m)) {
            return ['', ''];
        }

        return [$m[1], $m[2]];
    }
}
