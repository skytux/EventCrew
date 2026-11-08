<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * Routes the typed private-chat commands of PersonCommands, NightCommands,
 * TellCommand and MemberCommands, and the taps on their buttons, so
 * UpdateRouter needs one hook for all of them.
 */
final class ChatCommands
{
    public function __construct(
        private readonly PersonCommands $person,
        private readonly NightCommands $night,
        private readonly TellCommand $tell,
        private readonly MemberCommands $member
    ) {
    }

    /** The member commands, for Settings' buttons (MenuButtons::SIGN_OUT, DELETE). */
    public function member(): MemberCommands
    {
        return $this->member;
    }

    /**
     * Every command word handled here.
     *
     * @return list<string>
     */
    public static function commands(): array
    {
        return array_merge(
            PersonCommands::COMMANDS,
            NightCommands::COMMANDS,
            [TellCommand::COMMAND],
            MemberCommands::COMMANDS
        );
    }

    /**
     * The command word of $text when it is one of ours ("/mark@bot x" → "mark"), else ''.
     */
    public static function commandOf(string $text): string
    {
        if (1 !== preg_match('#^/([a-z]+)(@\w+)?($|\s)#i', $text, $m)) {
            return '';
        }

        $command = strtolower($m[1]);

        return in_array($command, self::commands(), true) ? $command : '';
    }

    /**
     * Handles $text and returns true when it is one of these commands.
     */
    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate): bool
    {
        $command = self::commandOf($text);

        if ('' === $command) {
            return false;
        }

        if (in_array($command, PersonCommands::COMMANDS, true)) {
            $this->person->onCommand($chatId, $fromId, $text, $isPrivate, $command);
        } elseif (in_array($command, NightCommands::COMMANDS, true)) {
            $this->night->onCommand($chatId, $fromId, $text, $isPrivate, $command);
        } elseif (TellCommand::COMMAND === $command) {
            $this->tell->onCommand($chatId, $fromId, $text, $isPrivate);
        } else {
            $this->member->onCommand($chatId, $fromId, $text, $isPrivate, $command);
        }

        return true;
    }

    public static function handlesCallback(string $data): bool
    {
        foreach ([PersonCommands::PREFIX, NightCommands::PREFIX, TellCommand::PREFIX, MemberCommands::PREFIX] as $prefix) {
            if (str_starts_with($data, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function onCallback(array $callbackQuery): void
    {
        $data = (string) ($callbackQuery['data'] ?? '');

        if (str_starts_with($data, PersonCommands::PREFIX)) {
            $this->person->onCallback($callbackQuery);
        } elseif (str_starts_with($data, NightCommands::PREFIX)) {
            $this->night->onCallback($callbackQuery);
        } elseif (str_starts_with($data, TellCommand::PREFIX)) {
            $this->tell->onCallback($callbackQuery);
        } elseif (str_starts_with($data, MemberCommands::PREFIX)) {
            $this->member->onCallback($callbackQuery);
        }
    }
}
