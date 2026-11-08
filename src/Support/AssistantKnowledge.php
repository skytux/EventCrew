<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;

/**
 * What the bot's language-model helper may answer from: today's date, the
 * official email address (the contact in the privacy settings), the
 * roles, the open tasks of the coming nights, the coming EventMesh events
 * when EventMesh is active, and the text of some published pages.
 *
 * Which pages: the ones chosen in Settings > Telegram bot. When none are
 * chosen, the pages in the site's menus (classic menu locations); when the
 * menus hold no pages, every published page. Password-protected pages are
 * never used. The page text is cut to a size limit and cached in a transient
 * that any post save, delete or menu change drops.
 *
 * The tasks and events are read fresh for every message: they change by the
 * minute on an event night, and they are a couple of queries.
 */
final class AssistantKnowledge
{
    public const CACHE_KEY = 'eventcrew_assistant_pages_text';

    /** Characters of one page's text. */
    public const PAGE_CHARS = 4000;

    /** Characters of all pages together. */
    public const TOTAL_PAGE_CHARS = 12000;

    /** How many pages at most are read when no page is chosen. */
    private const DEFAULT_PAGE_LIMIT = 30;

    /** How many open tasks are listed. */
    private const TASK_LIMIT = 60;

    /** How many EventMesh events are listed. */
    private const EVENT_LIMIT = 15;

    /** Characters of one event's own text. */
    private const EVENT_TEXT_CHARS = 600;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ?AssistantStatus $status = null
    ) {
    }

    public function boot(): void
    {
        add_action('save_post', [self::class, 'flush']);
        add_action('deleted_post', [self::class, 'flush']);
        add_action('wp_update_nav_menu', [self::class, 'flush']);
    }

    /** Drops the cached page text, so the next message reads the pages again. */
    public static function flush(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Everything at once, as the "facts" part of the instructions.
     *
     * $roleSlugs are the person's roles: the coming tasks of their
     * restricted roles (DJ, VJ, ...) are listed in a section of their own,
     * since they are never on the board (Owner 2026-11-07: the helper told a
     * VJ there were no VJ tasks). An organizer ($organizer) sees every role
     * and every role task, since they can put anyone on any of them.
     * $member: a known person in a private chat, whose board also shows the
     * tasks of the roles ticked Members display (BoardVisibility); otherwise
     * only the guest board's tasks are offered.
     *
     * The site's live state (AssistantStatus) goes in as "Current status" for
     * an organizer, and $personId's own tasks, tickets and credits as "This
     * person" (0 = none: a group chat or an unknown person).
     *
     * @param array<int, string> $roleSlugs
     * @return array{facts: string, tasks: array<int, int>}
     *     tasks: the ids of the board tasks listed, which are the only ones
     *     the helper may offer to sign someone up for with /join.
     */
    public function build(
        array $roleSlugs = [],
        bool $organizer = false,
        bool $member = false,
        int $personId = 0
    ): array
    {
        [$taskText, $taskIds] = $this->tasksText($member, $organizer);

        $parts = [
            '## Today' . "\n" . $this->todayLine(),
        ];

        // The contact from the privacy settings is the official address.
        $contact = PrivacyPolicy::contact();

        if ('' !== $contact) {
            $parts[] = '## Contact' . "\n" . 'The official email address: ' . $contact;
        }

        $parts = [
            ...$parts,
            '## Roles' . "\n" . $this->rolesText($roleSlugs, $organizer),
            '## Open tasks (the board)' . "\n" . $taskText,
        ];

        $roleTasks = $this->roleTasksText($roleSlugs, $organizer);

        if ('' !== $roleTasks) {
            $parts[] = ($organizer ? '## Role tasks' : '## This person\'s role tasks') . "\n" . $roleTasks;
        }

        $events = $this->eventsText();

        if ('' !== $events) {
            $parts[] = '## Coming events' . "\n" . $events;
        }

        $pages = $this->pagesText();

        if ('' !== $pages) {
            $parts[] = '## Site pages' . "\n" . $pages;
        }

        $parts = [...$parts, ...$this->statusParts($organizer, $personId)];

        // Private-chat commands for organizers and members (ag-people).
        $parts[] = '## Private-chat commands' . "\n" . self::chatCommandsText($organizer);

        return ['facts' => implode("\n\n", $parts), 'tasks' => $taskIds];
    }

    /**
     * "## Current status" (organizers) and "## This person", read fresh.
     *
     * @return list<string>
     */
    private function statusParts(bool $organizer, int $personId): array
    {
        $parts = [];
        $person = null === $this->status ? '' : $this->status->personText($personId);

        if ('' !== $person) {
            $parts[] = '## This person' . "\n" . $person;
        }

        if ($organizer && null !== $this->status) {
            $parts[] = '## Current status' . "\n" . $this->status->text();
        }

        return $parts;
    }

    /**
     * What the private-chat commands of ChatCommands do, for the helper. The
     * organizer ones only for an organizer in a private chat.
     */
    public static function chatCommandsText(bool $organizer): string
    {
        $lines = [
            '- /crew: the person\'s next nights and who works each task with them (names and roles only).',
            '- /name new name and /email new address: change their own name or email. A new email changes'
                . ' only after they open the link sent to it.',
            '- These work only in a private chat with the bot.',
            '- The newsletter (the announcement email) is turned on or off in Settings → Notifications, last row.',
        ];

        if ($organizer) {
            $lines = [
                ...$lines,
                '- Organizers, for one person: /mark name ; arrived|late|noshow|done, /standing name,'
                    . ' /newcomer [name], /ticket name and /unticket name (a free-entry ticket for a credit),'
                    . ' /unban name.',
                '- Organizers, for a night: /freeentry close|open, /stop announcement, /stop email,'
                    . ' /newboard, /sendtickets (or /sendtickets cancel).',
                '- Organizers: /tell task ; message sends the message to everyone on that task, by Telegram'
                    . ' in their own language, or by email without Telegram; at most 5 an hour.',
                '- Organizers: /subscribe email [fi|en|...] adds someone to the newsletter; they get a'
                    . ' confirmation email and are on the list once they open it.',
                '- A date first (/mark 14.11. Milla ; late) picks another night. Every change is confirmed'
                    . ' with a button first.',
            ];
        }

        return implode("\n", $lines);
    }

    /**
     * The ids of the tasks the helper may offer, without building the text.
     *
     * @return array<int, int>
     */
    public function offeredTaskIds(bool $member = false, bool $organizer = false): array
    {
        return array_map(static fn (Task $task): int => $task->id, $this->offeredTasks($member, $organizer));
    }

    /**
     * The pages used when none are chosen in Settings: the pages in the
     * site's menus, else every published page.
     *
     * @return array<int, int>
     */
    public static function defaultPageIds(): array
    {
        $ids = [];
        $locations = function_exists('get_nav_menu_locations') ? get_nav_menu_locations() : [];

        foreach (is_array($locations) ? $locations : [] as $menuId) {
            $items = wp_get_nav_menu_items((int) $menuId);

            foreach (is_array($items) ? $items : [] as $item) {
                if ('page' === ($item->object ?? '') && (int) ($item->object_id ?? 0) > 0) {
                    $ids[] = (int) $item->object_id;
                }
            }
        }

        $ids = array_values(array_unique($ids));

        if ([] !== $ids) {
            return $ids;
        }

        $posts = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => self::DEFAULT_PAGE_LIMIT,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        return array_values(array_map('intval', is_array($posts) ? $posts : []));
    }

    /**
     * The pages in use: those chosen in Settings, else the default.
     *
     * @return array<int, int>
     */
    public static function pageIds(): array
    {
        $chosen = AssistantSettings::pageIds();

        return [] !== $chosen ? $chosen : self::defaultPageIds();
    }

    private function todayLine(): string
    {
        $today = (string) current_time('Y-m-d');

        return sprintf('%s (%s), time now %s', $today, $this->weekday($today), (string) current_time('H:i'));
    }

    /**
     * @param array<int, string> $roleSlugs
     */
    private function rolesText(array $roleSlugs = [], bool $organizer = false): string
    {
        $lines = [];

        foreach (Roles::active() as $role) {
            if (! $organizer && Roles::isRestricted($role['slug']) && ! in_array($role['slug'], $roleSlugs, true)) {
                continue;
            }

            $description = trim((string) $role['description']);
            $lines[] = '- ' . $role['label'] . ('' !== $description ? ': ' . $description : '');
        }

        $lines[] = sprintf(
            '- Every %d completed tasks earn one free-entry credit.',
            Credits::TASKS_PER_CREDIT
        );
        // The ticket and credit rules, in the same English as the rest.
        array_push($lines, ...TicketRules::assistantFacts());

        return implode("\n", $lines);
    }

    /**
     * The tasks anyone may take: the ones on the guest board, and for a
     * member ($member) also those of the roles ticked Members display; an
     * organizer ($organizer) sees every role's (BoardVisibility). Never an
     * archived role's.
     *
     * @return array<int, Task>
     */
    private function offeredTasks(bool $member = false, bool $organizer = false): array
    {
        return array_values(array_filter(
            $this->tasks->upcoming(self::TASK_LIMIT),
            static fn (Task $task): bool => ! $task->isRestricted() && ! $task->hasStarted()
                && match (true) {
                    $organizer => ! Roles::isArchived($task->roleSlug),
                    $member => BoardVisibility::onMembersBoard($task),
                    default => BoardVisibility::onGuestBoard($task),
                }
        ));
    }

    /**
     * The coming tasks of the restricted roles in $roleSlugs; '' when the
     * person has none. Signing up for these goes through the role task
     * buttons /board sends in the bot, which ask about credit first.
     *
     * @param array<int, string> $roleSlugs
     */
    private function roleTasksText(array $roleSlugs, bool $organizer = false): string
    {
        if ([] === $roleSlugs && ! $organizer) {
            return '';
        }

        $tasks = array_values(array_filter(
            $this->tasks->upcoming(self::TASK_LIMIT),
            static fn (Task $task): bool => $task->isRestricted()
                && ! $task->hasStarted()
                && ! Roles::isArchived($task->roleSlug)
                && ($organizer || in_array($task->roleSlug, $roleSlugs, true))
        ));

        if ([] === $tasks) {
            return $organizer ? 'No coming role tasks yet.' : 'No coming tasks of this person\'s roles yet.';
        }

        $taken = $this->tasks->occupancyFor(array_map(static fn (Task $task): int => $task->id, $tasks));
        $lines = [
            'These are not on the group board. The person signs up for them with the role task buttons'
                . ' that /board sends in this private chat, so for these give the action /board, never /join.',
        ];

        if ($organizer) {
            $lines = [
                'Every coming role task, of every role: this person is an organizer. They are not on the group'
                    . ' board. The organizer signs themselves up for one of their own roles with /board. To put'
                    . ' anyone on any task, also one for a role that person does not hold, give the action'
                    . ' /assign <task> ; <person> (or /board, ➕ Add someone); Organize takes only someone who is'
                    . ' an organizer, which the bot checks. /unassign takes someone off. To'
                    . ' change a task give /edittask <task> ; <field> <value>; /addtask, /deletetask, /maketasks'
                    . ' and /mailtasks add one, delete one, create a night\'s tasks and send the open-task email.',
            ];
        }

        foreach ($tasks as $task) {
            $time = $task->timeRange();
            $lines[] = sprintf(
                '- %s (%s), %s: %s%s, %d signed up',
                $task->taskDate,
                $this->weekday($task->taskDate),
                $task->eventName(),
                $task->roleLabel(),
                '' !== $time ? ' ' . $time : '',
                $taken[$task->id] ?? 0
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{0: string, 1: array<int, int>}
     */
    private function tasksText(bool $member = false, bool $organizer = false): array
    {
        $tasks = $this->offeredTasks($member, $organizer);

        if ([] === $tasks) {
            return ['No open tasks are listed.', []];
        }

        $taken = $this->tasks->occupancyFor(array_map(static fn (Task $task): int => $task->id, $tasks));
        $lines = [];
        $ids = [];
        $date = '';

        foreach ($tasks as $task) {
            if ($task->taskDate !== $date) {
                $date = $task->taskDate;
                $lines[] = sprintf('%s (%s), %s:', $date, $this->weekday($date), $task->eventName());
            }

            $time = $task->timeRange();
            $lines[] = sprintf(
                '- task %d: %s%s, %d of %d places taken',
                $task->id,
                $task->roleLabel(),
                '' !== $time ? ' ' . $time : '',
                $taken[$task->id] ?? 0,
                $task->capacity
            );
            $ids[] = $task->id;
        }

        return [implode("\n", $lines), $ids];
    }

    /** The coming published EventMesh events; '' when EventMesh is not active. */
    private function eventsText(): string
    {
        if (! EventSource::isAvailable() || ! function_exists('get_posts')) {
            return '';
        }

        $today = (string) current_time('Y-m-d');
        $lines = [];

        foreach (EventSource::upcoming(self::EVENT_LIMIT * 3) as $event) {
            if ('' === $event['date'] || $event['date'] < $today) {
                continue;
            }

            $closed = EventSource::closedReason($event['id']);

            if (in_array($closed, ['unpublished', 'disabled', 'hidden'], true)) {
                continue;
            }

            $line = sprintf(
                '- %s: %s, starts %s%s',
                $event['date'],
                $event['title'],
                substr($event['starts_at'], 11, 5),
                '' !== $event['ends_at'] ? ', ends ' . $event['ends_at'] : ''
            );

            // The same price the event card shows ("🎟 22 €"); none, no words.
            // A sold-out event gives no price, so the bot does not offer a
            // ticket that cannot be bought.
            $price = NewsletterLayout::price(EventSource::card($event['id'])['price']);

            if ('sold_out' === $closed) {
                $line .= ', tickets sold out';
            } elseif ('' !== $price) {
                $line .= ', ticket ' . $price;
            }

            $link = (string) get_permalink($event['id']);

            if ('' !== $link) {
                $line .= ', ' . $link;
            }

            $text = $this->plainText((string) get_post_field('post_content', $event['id']), self::EVENT_TEXT_CHARS);

            if ('' !== $text) {
                $line .= "\n  " . $text;
            }

            $lines[] = $line;

            if (count($lines) >= self::EVENT_LIMIT) {
                break;
            }
        }

        return implode("\n", $lines);
    }

    private function pagesText(): string
    {
        $cached = get_transient(self::CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        $parts = [];
        $total = 0;

        foreach (self::pageIds() as $id) {
            $post = get_post($id);

            if (null === $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password) {
                continue;
            }

            $text = $this->plainText((string) $post->post_content, self::PAGE_CHARS);

            if ('' === $text) {
                continue;
            }

            $part = '### ' . $post->post_title . ' (' . get_permalink($id) . ")\n" . $text;
            $room = self::TOTAL_PAGE_CHARS - $total;

            if ($room <= 0) {
                break;
            }

            $part = mb_substr($part, 0, $room);
            $parts[] = $part;
            $total += mb_strlen($part);
        }

        $text = implode("\n\n", $parts);
        set_transient(self::CACHE_KEY, $text, DAY_IN_SECONDS);

        return $text;
    }

    private function plainText(string $content, int $limit): string
    {
        $text = wp_strip_all_tags(strip_shortcodes($content));
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES, 'UTF-8')));

        return mb_substr($text, 0, $limit);
    }

    private function weekday(string $date): string
    {
        $time = strtotime($date . ' 12:00:00 UTC');

        return false === $time ? '' : gmdate('l', $time);
    }
}
