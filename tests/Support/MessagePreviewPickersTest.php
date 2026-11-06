<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EventAiText;
use EventCrew\Support\Logger;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageTemplates;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\TestCase;

/**
 * The preview's event and task pickers: which events are offered, which
 * task a preview is made with, and that the text is the one a real send for
 * that event uses (its AI text when the kind's AI tick is on). Today is
 * 2026-07-20 (TestCase).
 */
final class MessagePreviewPickersTest extends TestCase
{
    use FakeEvents;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('network_home_url')->justReturn('https://site.test/');
        Functions\when('wp_parse_url')->alias(
            static fn (string $url, int $component = -1): mixed => parse_url($url, $component)
        );
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_transient')->justReturn(false);
    }

    public function testTheEventPickerOffersTheLastThreePastEventsAndEveryComingOne(): void
    {
        $this->event(1, '2026-06-01', 'June one');
        $this->event(2, '2026-06-20', 'June two');
        $this->event(3, '2026-07-01', 'July one');
        $this->event(4, '2026-07-10', 'July two');
        $this->event(5, '2026-07-25', 'Coming one');
        $this->event(6, '2026-08-08', 'Coming two');

        $events = $this->preview()->events();

        self::assertSame([2, 3, 4, 5, 6], array_column($events, 'id'));
        self::assertSame([true, true, true, false, false], array_column($events, 'past'));
        self::assertSame('2026-07-25', $events[3]['date']);
    }

    public function testATaskOfTheEventIsUsedAsPicked(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow(31, 12, 'bar');

        $task = $this->preview()->resolveTask(12, 31);

        self::assertSame(31, $task->id);
        self::assertSame(12, $task->eventPostId);
    }

    public function testATaskOfAnotherEventGivesTheFirstTaskOfTheEventPicked(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow(31, 99, 'bar');
        $this->wpdb->nextResults[] = [$this->taskRow(40, 12, 'door'), $this->taskRow(41, 12, 'bar')];

        $task = $this->preview()->resolveTask(12, 31);

        self::assertSame(40, $task->id);
        self::assertSame('door', $task->roleSlug);
    }

    public function testAnEventWithoutTasksGetsATaskMadeUpOnItsDate(): void
    {
        $this->event(12, '2026-07-25', 'Dance night');

        $task = $this->preview()->resolveTask(12, 0);

        self::assertSame(0, $task->id);
        self::assertSame(12, $task->eventPostId);
        self::assertSame('2026-07-25', $task->taskDate);
    }

    public function testNoEventPickedGivesTheNextTask(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(50, 7, 'door')];

        self::assertSame(50, $this->preview()->resolveTask(0, 0)->id);
    }

    public function testOnlyTheKindsAboutATaskOfferTheTaskPicker(): void
    {
        foreach (['reminder', 'reminder_dm', 'signup_confirmed', 'takeover_ask', 'no_show', 'late_cancel'] as $id) {
            self::assertTrue(MessagePreview::aboutTask(MessageTemplates::find($id)), $id);
        }

        foreach (['sign_in_link', 'credit_granted', 'access_role', 'ticket', 'newsletter'] as $id) {
            self::assertFalse(MessagePreview::aboutTask(MessageTemplates::find($id)), $id);
        }
    }

    public function testThePreviewUsesTheAiTextOfTheEventPickedAsASendWould(): void
    {
        $this->event(12, '2026-07-25', 'Dance night');
        $this->aiTick('reminder_dm');
        $this->options[EventAiText::TEXTS_OPTION] = [
            12 => ['date' => '2026-07-25', 'title' => 'Dance night', 'items' => [
                'reminder_dm' => ['ai' => ['en' => 'AI wrote this for {role}.'], 'edited' => [], 'tries' => 1],
            ]],
        ];
        $this->wpdb->nextResults[] = [$this->taskRow(40, 12, 'door')];

        $message = $this->preview()->render('reminder_dm', null, 12);

        self::assertNotNull($message);
        self::assertSame('', $message['error']);
        self::assertSame(12, $message['event_id']);
        self::assertSame(40, $message['task_id']);
        self::assertTrue($message['about_task']);
        self::assertSame(MessagePreview::AI_WRITTEN, $message['ai']);
        self::assertStringContainsString('AI wrote this for', $message['text']);
        self::assertStringNotContainsString('{role}', $message['text']);
    }

    public function testATextChangedByHandForTheEventWinsInThePreview(): void
    {
        $this->event(12, '2026-07-25', 'Dance night');
        $this->aiTick('reminder_dm');
        $this->options[EventAiText::TEXTS_OPTION] = [
            12 => ['date' => '2026-07-25', 'title' => 'Dance night', 'items' => [
                'reminder_dm' => ['ai' => ['en' => 'AI wrote this.'], 'edited' => ['en' => 'An organizer wrote this.']],
            ]],
        ];
        $this->wpdb->nextResults[] = [$this->taskRow(40, 12, 'door')];

        $message = $this->preview()->render('reminder_dm', null, 12);

        self::assertStringContainsString('An organizer wrote this.', (string) $message['text']);
        self::assertStringNotContainsString('AI wrote this.', (string) $message['text']);
    }

    public function testWithoutAnAiTextYetThePreviewShowsTheTemplateTextAndSaysSo(): void
    {
        $this->event(12, '2026-07-25', 'Dance night');
        $this->aiTick('reminder_dm');
        $this->wpdb->nextResults[] = [$this->taskRow(40, 12, 'door')];

        $message = $this->preview()->render('reminder_dm', null, 12);

        self::assertSame(MessagePreview::AI_MISSING, $message['ai']);
        self::assertSame('', $message['error']);
        self::assertNotSame('', trim((string) $message['text']));
    }

    public function testTheAiStateOfAKindWithoutTheTickOrWithoutAiTexts(): void
    {
        self::assertSame(MessagePreview::AI_OFF, MessagePreview::aiState('reminder_dm', 12));
        self::assertSame(MessagePreview::AI_NONE, MessagePreview::aiState('reminder_dm', 0));
        self::assertSame(MessagePreview::AI_NONE, MessagePreview::aiState('sign_in_link', 12));
    }

    private function aiTick(string $id): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . $id] = ['settings' => [
            EventAiText::SETTING_AI_EACH_EVENT => true,
            EventAiText::SETTING_AI_PROMPT => 'Write the reminder.',
        ]];
    }

    /** @return array<string, mixed> */
    private function taskRow(int $id, int $eventId, string $role): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-07-25',
            'role_slug' => $role,
            'capacity' => 2,
            'event_post_id' => $eventId,
            'starts_at' => '2026-07-25 18:00:00',
            'ends_at' => '2026-07-25 20:00:00',
        ];
    }

    private function preview(): MessagePreview
    {
        return new MessagePreview(new Logger(), new TaskRepository());
    }
}
