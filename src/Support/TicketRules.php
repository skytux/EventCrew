<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The rules for tickets and credits, written once for every place that
 * explains them: the crew help and welcome message (WelcomeSender), the
 * organizer guide (OrganizerGuide), the assistant's facts
 * (AssistantKnowledge) and the privacy policy (PrivacyPolicy). The lines
 * follow the live settings: giving tickets away (TicketTransferService) and
 * the credit for extra tasks on the same night (ExtraTaskCredits).
 */
final class TicketRules
{
    /** The "no cash value" sentence, shared by the crew help and the privacy policy. */
    public static function policyLine(): string
    {
        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        return __('Free-entry credits and tickets have no cash value. They can\'t be refunded, sold or paid out in any form; they can only be used to get a ticket for an eligible event.', 'eventcrew');
    }

    /**
     * The member's "Tickets and credits" lines, as "- " list items.
     *
     * @return array<int, string>
     */
    public static function memberLines(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $lines = [
            '- 🎫 ' . __('Your working ticket covers all your tasks on a night. It is personal: it can\'t be given away. Its code stays the same, so a screenshot or a saved QR code keeps working.', 'eventcrew'),
            '- 🎟️ ' . (TicketTransferService::isOn()
                ? __('A free-entry ticket can\'t be cancelled, but you can give it to someone else.', 'eventcrew')
                : __('A free-entry ticket can’t be cancelled.', 'eventcrew')),
            '- 💳 ' . __('A bought ticket never turns into a credit.', 'eventcrew'),
            '- 🔁 ' . __('Taking a task on a night you already have a ticket for changes nothing about that ticket.', 'eventcrew'),
        ];

        if (ExtraTaskCredits::isOn()) {
            $lines[] = '- ➕ ' . __('Each extra task on the same night earns one more credit. It shows as pending and is added after the event, if you did the task.', 'eventcrew');
        }
        // phpcs:enable Generic.Files.LineLength.TooLong

        $lines[] = '- 🔹 ' . self::policyLine();

        return $lines;
    }

    /**
     * The organizer guide's lines: each rule with why it is so.
     *
     * @return array<int, string>
     */
    public static function organizerLines(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $lines = [
            '- 🎫 ' . __('One working ticket per person per night, for all their tasks. Why: one entry per person, and the door sees all their tasks at once.', 'eventcrew'),
            '- 🔒 ' . __('The working ticket\'s code never changes. Why: screenshots and saved QR codes keep working.', 'eventcrew'),
            '- 🎟️ ' . __('Members can\'t cancel a free-entry ticket; organizers still can (/unticket). Why: one simple rule, and nobody can win a credit back by changing plans late.', 'eventcrew'),
            '- 💳 ' . __('A bought ticket never becomes a credit, and taking a task on that night changes nothing. Why: it was paid for, and credits have no cash value.', 'eventcrew'),
        ];

        if (ExtraTaskCredits::isOn()) {
            $lines[] = '- ➕ ' . __('An extra task on the same night earns one more credit, pending until the after-event run adds it for a task marked completed or arrived late. Why: credit only for work actually done.', 'eventcrew');
        }
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $lines;
    }

    /**
     * The same rules as plain facts for the assistant's prompt, in English
     * like the rest of it (AssistantKnowledge).
     *
     * @return array<int, string>
     */
    public static function assistantFacts(): array
    {
        $facts = [
            '- A crew member has one working ticket per night covering all their tasks; it is personal, cannot be given away, and its code never changes.',
            TicketTransferService::isOn()
                ? '- Members cannot cancel a free-entry ticket; they can give it to someone else. Only organizers can cancel one (/unticket).'
                : '- Members cannot cancel a free-entry ticket. Only organizers can cancel one (/unticket).',
            '- A bought (Holvi) ticket never turns into a credit; taking a task on a night someone already has a ticket for converts nothing.',
        ];

        if (ExtraTaskCredits::isOn()) {
            $facts[] = '- Each extra task on the same night earns one more free-entry credit. It is pending until after the event and added only if the task was completed; cancelled or no-show tasks give nothing.';
        }

        $facts[] = '- Free-entry credits and tickets have no cash value: they cannot be refunded, sold or paid out, only used to get a ticket for an eligible event.';

        return $facts;
    }
}
