<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\Language;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\RoleGuide;
use EventCrew\Tests\TestCase;

/**
 * The task reminder's {description}: the built-in texts, the paragraph that
 * goes when there is no description, and the one-time migration of the
 * stored texts.
 */
final class ReminderDescriptionTest extends TestCase
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
        Functions\when('get_the_title')->justReturn('');
    }

    private function task(string $notes = '', string $role = 'decorate'): Task
    {
        return new Task(5, '2026-07-21', $role, 2, null, 'Dance', '2026-07-21 08:00:00', null, $notes);
    }

    /** @return array<string, mixed> */
    private function context(string $description): array
    {
        return [
            'person' => Person::fromRow(['id' => 8, 'display_name' => 'Anna', 'telegram_chat_id' => 1]),
            'task' => $this->task(),
            'description' => $description,
        ];
    }

    public function testTheBuiltInTextsHaveTheDescriptionAfterTheWhenLine(): void
    {
        self::assertSame(
            "Hi {name},\n\nJust a reminder that you're on {role} at {event}, {when}.\n\n{description}\n\n{cancel_help}",
            MessageTemplates::find('reminder')->defaultBody()
        );
        self::assertSame(
            "⏰ Reminder: you’re on {role} at {event}, {when}. See you there!\n\n{description}",
            MessageTemplates::find('reminder_dm')->defaultBody()
        );

        $finnish = Language::run(
            Language::FINNISH,
            static fn (): string => MessageTemplates::find('reminder_dm')->defaultBody()
        );
        self::assertStringEndsWith("\n\n{description}", $finnish, 'the Finnish text keeps its translation');
        self::assertArrayHasKey('{description}', MessageTemplates::find('reminder')->placeholderList());
    }

    public function testThePlaceholderIsFilled(): void
    {
        $body = MessageTemplates::body('reminder', $this->context("Bring a torch.\nMeet at the back."));
        self::assertStringContainsString(".\n\nBring a torch.\nMeet at the back.\n\nCan't make it?", $body);

        $dm = MessageTemplates::body('reminder_dm', $this->context('Bring a torch.'));
        self::assertStringEndsWith("See you there!\n\nBring a torch.", $dm);

        // With no description in the context, the task's own comes first,
        // then its role's.
        self::assertSame('Own text.', RoleGuide::taskDescription($this->task('Own text.')));
        self::assertStringContainsString('LED candles', RoleGuide::taskDescription($this->task()));
        self::assertStringEndsWith(
            "See you there!\n\nOwn text.",
            MessageTemplates::body('reminder_dm', ['task' => $this->task('Own text.')])
        );
    }

    public function testAnEmptyDescriptionTakesItsParagraphWithIt(): void
    {
        $body = MessageTemplates::body('reminder', $this->context(''));
        self::assertStringNotContainsString("\n\n\n", $body);
        self::assertStringNotContainsString('{description}', $body);
        self::assertMatchesRegularExpression("/08:00\\.\n\nCan't make it\\?/", $body);

        $dm = MessageTemplates::body('reminder_dm', $this->context(''));
        self::assertStringEndsWith('See you there!', $dm);

        $template = MessageTemplates::find('reminder_dm');
        self::assertSame("A\n\nB", $template->fill("A\n\n{description}\n\nB", ['description' => '']));
        self::assertSame("A\r\n\r\nB", $template->fill("A\r\n\r\n{description}\r\n\r\nB", ['description' => '']));
        self::assertSame('B', $template->fill("{description}\n\nB", ['description' => '']));
        self::assertSame(
            'A  B',
            $template->fill('A {description} B', ['description' => '']),
            'inside a line it is only emptied'
        );
    }

    public function testTheMigrationAddsTheDescriptionOnceToTheStoredTexts(): void
    {
        $email = MessageTemplates::OPTION_PREFIX . 'reminder';
        $dm = MessageTemplates::OPTION_PREFIX . 'reminder_dm';
        $this->options[$email] = [
            'subject' => ['source' => 'Reminder {role}'],
            'body' => [
                'source' => "Hi {name},\n\nYou're on {role}, {when}.\nSee you.\n\n{cancel_help}",
                'lang' => 'en',
                'texts' => [
                    'fi' => "Hei {name},\n\nOlet {role}, {when}.\n\n{cancel_help}",
                    'sv' => '',
                    'en' => 'No when here.',
                ],
                'manual' => ['fi' => true],
            ],
            'settings' => ['ai' => true],
        ];
        $this->options[$dm] = [
            'body' => ['source' => "⏰ {role}, {when}!\n\n{description} extra", 'texts' => ['fi' => '⏰ {role}, {when}!']],
        ];

        MessageTemplates::migrateReminderDescription();

        $body = $this->options[$email]['body'];
        self::assertSame(
            "Hi {name},\n\nYou're on {role}, {when}.\n\n{description}\nSee you.\n\n{cancel_help}",
            $body['source']
        );
        self::assertSame("Hei {name},\n\nOlet {role}, {when}.\n\n{description}\n\n{cancel_help}", $body['texts']['fi']);
        self::assertSame('', $body['texts']['sv']);
        self::assertSame('No when here.', $body['texts']['en']);
        self::assertSame(['fi' => true], $body['manual']);
        self::assertSame(['source' => 'Reminder {role}'], $this->options[$email]['subject']);
        self::assertSame(['ai' => true], $this->options[$email]['settings']);
        self::assertSame("⏰ {role}, {when}!\n\n{description} extra", $this->options[$dm]['body']['source']);
        self::assertSame("⏰ {role}, {when}!\n\n{description}", $this->options[$dm]['body']['texts']['fi']);
        self::assertSame('1', $this->options[MessageTemplates::REMINDER_DESCRIPTION_OPTION]);

        // Run again, even with the guard gone: nothing changes.
        $after = $this->options;
        unset($this->options[MessageTemplates::REMINDER_DESCRIPTION_OPTION]);
        MessageTemplates::migrateReminderDescription();
        self::assertSame($after, $this->options);
    }

    public function testTheMigrationRunsOnlyOnce(): void
    {
        $this->options[MessageTemplates::REMINDER_DESCRIPTION_OPTION] = '1';
        $this->options[MessageTemplates::OPTION_PREFIX . 'reminder'] = ['body' => ['source' => 'On {role}, {when}.']];

        MessageTemplates::migrateReminderDescription();

        $stored = $this->options[MessageTemplates::OPTION_PREFIX . 'reminder'];
        self::assertSame('On {role}, {when}.', $stored['body']['source']);
    }

    public function testTheMigrationLeavesASiteWithNoStoredTextsAlone(): void
    {
        MessageTemplates::migrateReminderDescription();

        self::assertSame([MessageTemplates::REMINDER_DESCRIPTION_OPTION => '1'], $this->options);
    }
}
