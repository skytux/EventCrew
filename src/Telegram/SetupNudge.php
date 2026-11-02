<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Language;

/**
 * The one "you are not set up yet" answer every bot command gives to someone
 * with no confirmed email, so each of them names /start the same way.
 *
 * Sent into the group it is English, because the group reads English; in a
 * private chat or a button popup it is in the person's own language.
 */
final class SetupNudge
{
    public static function text(bool $inGroup = false): string
    {
        $text = static fn (): string => __('Set yourself up first: send /start to the bot.', 'eventcrew');

        return $inGroup ? Language::run(Language::ENGLISH, $text) : $text();
    }
}
