<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Support\EventAiText;
use EventCrew\Support\MessageAiText;
use EventCrew\Tests\TestCase;

/**
 * The connection of the Messages page to the AI texts per event: which kinds
 * get the AI tick, the tick and prompt read from the form, the kinds given to
 * EventAiText, and the organizers' placeholders that may not take a built-in
 * placeholder's name. Options live in an array.
 */
final class MessageAiTextTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
    }

    public function testOnlyWiredKindsOfOneEventGetTheAiTick(): void
    {
        self::assertTrue(MessageAiText::offers('reminder'));
        self::assertTrue(MessageAiText::offers('free_ticket_dm'));
        // Free-entry credits are not about one event.
        self::assertFalse(MessageAiText::offers('credit_granted'));
        // Listed but not wired yet.
        self::assertFalse(MessageAiText::offers('ticket'));
        // Wired, but not given the AI option.
        self::assertFalse(MessageAiText::offers('followup_came'));
    }

    public function testTheTickAndPromptAreReadFromTheFormAndGivenToEventAiText(): void
    {
        $posted = [EventAiText::SETTING_AI_EACH_EVENT => '1', EventAiText::SETTING_AI_PROMPT => ' Write it. '];

        $settings = MessageAiText::readSettings(['hybrid' => true], 'reminder', $posted);

        self::assertSame(
            [
                'hybrid' => true,
                EventAiText::SETTING_AI_EACH_EVENT => true,
                EventAiText::SETTING_AI_PROMPT => 'Write it.',
            ],
            $settings
        );
        // A kind without the tick keeps its settings as they are.
        self::assertSame(['x' => 1], MessageAiText::readSettings(['x' => 1], 'credit_granted', $posted));
        // The tick off when it is not posted.
        self::assertFalse(MessageAiText::readSettings([], 'reminder', [])[EventAiText::SETTING_AI_EACH_EVENT]);

        $this->options['eventcrew_message_template_reminder'] = ['settings' => $settings];
        $templates = MessageAiText::templates([]);

        self::assertSame(MessageAiText::KINDS, array_keys($templates));
        self::assertTrue($templates['reminder'][EventAiText::SETTING_AI_EACH_EVENT]);
        self::assertSame('Task reminder (email)', $templates['reminder'][EventAiText::SETTING_LABEL]);
        self::assertSame([EventAiText::SETTING_LABEL => 'A spot opened (email)'], $templates['slot_freed']);
    }

    public function testAPlaceholderWithABuiltInNameIsNotSaved(): void
    {
        $type = 'success';
        $notice = (new AiPromptsPage())->applyPlaceholders(
            [
                'placeholders' => [
                    ['name' => '{Name}', 'prompt' => 'P.'],
                    ['name' => 'dj_intro', 'prompt' => 'Introduce the DJ.'],
                    ['name' => 'event', 'prompt' => 'P.'],
                ],
            ],
            $type
        );

        self::assertSame('error', $type);
        self::assertStringContainsString('Not saved: {name}, {event}.', $notice);
        self::assertSame(['dj_intro'], array_column(EventAiText::placeholders(), 'name'));

        // A name no message uses is saved as before.
        $type = 'success';
        (new AiPromptsPage())->applyPlaceholders(
            ['placeholders' => [['name' => 'dj_intro', 'prompt' => 'Introduce the DJ.']]],
            $type
        );
        self::assertSame('success', $type);
    }
}
