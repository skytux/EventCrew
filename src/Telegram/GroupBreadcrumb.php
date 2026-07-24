<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * The "📬 Sent you a DM." note a personal command leaves in a group after it has
 * answered privately, so the asker knows to look in their DM. Shared by every
 * command that answers by DM (/me, /roster, /ticket, /replace, /gift, /stop) so
 * the wording and the "only in a group" rule live in one place.
 *
 * A trait rather than a collaborator because it needs the using service's own
 * TelegramClient; every service that uses it holds one as $this->telegram.
 */
trait GroupBreadcrumb
{
    private function sentDmNote(int $chatId, bool $isPrivate): void
    {
        if (! $isPrivate) {
            $this->telegram->sendMessage($chatId, __('📬 Sent you a DM.', 'eventcrew'));
        }
    }
}
