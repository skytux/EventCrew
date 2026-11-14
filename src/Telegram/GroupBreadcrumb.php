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
 * Never a group message (Owner 2026-11-14): only a group button tap gets the
 * note, as the tap's toast, so only the tapper sees it. A typed command gets
 * nothing in the group.
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
     * way, and otherwise nothing is sent.
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

        // Only a button tap gets it, as a toast to the one who tapped. Never a
        // group message (Owner 2026-11-14).
        if ('' !== $tap) {
            $this->telegram->answerCallbackQuery($tap, $text);
        }
    }
}
