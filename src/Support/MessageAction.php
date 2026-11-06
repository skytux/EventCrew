<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;

/**
 * One thing a message offers to do - show the ticket, cancel the task, see
 * the open tasks - declared once for both versions of the message, the email
 * and the Telegram DM (MessageTemplates::actions()). MessageActions draws it:
 *
 * - in the DM, one inline keyboard button: the callback when there is one
 *   (it does the thing at once), else the bot verb's button (it opens that
 *   part of the bot in the same chat), else the web link;
 * - in the email, the web link as a button, and the bot verb as an
 *   "Open in Telegram" link (t.me/<bot>?start=<verb>) when the bot is set
 *   up.
 *
 * - id: a short key, the same on both sides, so a test can check that the
 *   two versions offer the same actions.
 * - label: the email button's text; dmLabel the DM button's ('' = label).
 * - url: makes the web link, '' for none. Called only when the link is
 *   drawn, so a sign-in link is not issued for a DM that does not show it.
 * - callback: the DM button's callback data ('' = none), at most 64 bytes.
 * - verb: a bot verb (MenuButtons::VERBS), '' for none.
 * - start: the email's "Open in Telegram" link payload when it is not a bot
 *   verb ('' = the verb's), like task-12 (MessageActions::isPayload()).
 */
final class MessageAction
{
    /**
     * @param (Closure(): string)|null $url
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $dmLabel = '',
        public readonly ?Closure $url = null,
        public readonly string $callback = '',
        public readonly string $verb = '',
        public readonly string $start = ''
    ) {
    }

    public function dmText(): string
    {
        return '' === $this->dmLabel ? $this->label : $this->dmLabel;
    }

    /** The web link, '' for none. */
    public function webUrl(): string
    {
        return null === $this->url ? '' : trim((string) ($this->url)());
    }
}
