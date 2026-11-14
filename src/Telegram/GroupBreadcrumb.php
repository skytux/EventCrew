<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Language;

/**
 * The "📬 Sent you a DM." note a personal command leaves in a group after it has
 * answered privately, so the asker knows to look in their DM. Shared by every
 * command that answers by DM (/me, /roster, /door, /people) so
 * the wording and the "only in a group" rule live in one place.
 *
 * A typed command's note is a group message that deletes itself about a
 * minute later (DmNote). A group button tap gets no group message: the note
 * is the tap's toast, so only the tapper sees it.
 *
 * A trait rather than a collaborator because it needs the using service's own
 * TelegramClient; every service that uses it holds one as $this->telegram.
 *
 * The note lands in the group, which reads English, so it is English whatever
 * language the asker chose.
 */
trait GroupBreadcrumb
{
    /**
     * $callbackId: the button tap this answers, which this then answers (with
     * the note as a toast in a group, empty in a private chat). Without it, a
     * group tap held by the router (DmNote::holdTap()) is answered the same
     * way, and otherwise the note is posted and deleted a minute later.
     */
    private function sentDmNote(int $chatId, bool $isPrivate, string $callbackId = ''): void
    {
        if ($isPrivate) {
            if ('' !== $callbackId) {
                $this->telegram->answerCallbackQuery($callbackId);
            }

            return;
        }

        $text = DmNote::text();
        $tap = '' !== $callbackId ? $callbackId : DmNote::takeTap();

        if ('' !== $tap) {
            $this->telegram->answerCallbackQuery($tap, $text);

            return;
        }

        $sent = $this->telegram->sendMessage($chatId, $text);
        DmNote::scheduleDelete($chatId, (int) ($sent['message_id'] ?? 0));
    }
}
