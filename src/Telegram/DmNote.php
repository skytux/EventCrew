<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Language;

/**
 * What GroupBreadcrumb's "📬 Sent you a DM." note needs beyond the using
 * service, which a trait cannot hold: the WP-Cron event that deletes the note
 * a typed group command left, about a minute later, and the group button tap
 * whose spinner is still waiting, so the note can be its toast instead of a
 * group message.
 *
 * The cron event runs through the live site's external runner, so the note
 * goes after one to two minutes. A note that cannot be deleted (already gone,
 * too old) is left alone without a warning.
 */
final class DmNote
{
    public const HOOK = 'eventcrew_delete_dm_note';

    /** Seconds the note stays in the group. */
    public const DELAY = 60;

    /** The id of a group button tap not answered yet, or ''. */
    private static string $tap = '';

    public function __construct(private readonly TelegramClient $telegram)
    {
    }

    /** The cron event (HOOK, wired in Kernel): deletes the note, quietly when it cannot. */
    public function delete(int $chatId, int $messageId): void
    {
        if (0 !== $chatId && $messageId > 0) {
            $this->telegram->deleteMessage($chatId, $messageId, true);
        }
    }

    /** Asks WP-Cron to delete the note $messageId in $chatId in a minute. */
    public static function scheduleDelete(int $chatId, int $messageId): void
    {
        if ($messageId <= 0 || ! function_exists('wp_schedule_single_event')) {
            return;
        }

        wp_schedule_single_event(time() + self::DELAY, self::HOOK, [$chatId, $messageId]);
    }

    /**
     * A group button tap whose answer waits: if what it runs answers by DM,
     * the note becomes its toast (takeTap()); otherwise the caller answers it
     * after.
     */
    public static function holdTap(string $callbackQueryId): void
    {
        self::$tap = $callbackQueryId;
    }

    /** The held tap's id, now no longer held, or '' when none is. */
    public static function takeTap(): string
    {
        $tap = self::$tap;
        self::$tap = '';

        return $tap;
    }

    /** The note's text (in English, as the group's notes are). */
    public static function text(): string
    {
        return Language::run(Language::ENGLISH, static fn (): string => __('📬 Sent you a DM.', 'eventcrew'));
    }
}
