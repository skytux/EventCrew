<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\AccessTextsMigration;
use EventCrew\Support\MessageTemplates;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * The stored texts of a role, a pass and the team's OK get {open_tasks}
 * once; the built-in ones have it already.
 */
final class AccessTextsMigrationTest extends TelegramTestCase
{
    public function testAStoredTextGetsTheOpenTasksOnceInEveryLanguage(): void
    {
        $option = MessageTemplates::OPTION_PREFIX . 'access_role_dm';
        $this->options[$option] = [
            MessageTemplates::SUBJECT => null,
            MessageTemplates::BODY => [
                'source' => 'You now have the role {role}. ',
                'lang' => 'en',
                'texts' => ['fi' => 'Sinulla on nyt rooli {role}.', 'sv' => ''],
            ],
            'settings' => ['x' => 1],
        ];
        $this->options[MessageTemplates::OPTION_PREFIX . 'newcomer_ok'] = [
            MessageTemplates::BODY => ['source' => 'OK.{open_tasks}', 'texts' => []],
        ];

        AccessTextsMigration::migrate();

        self::assertSame(
            [
                'source' => 'You now have the role {role}.{open_tasks}',
                'lang' => 'en',
                'texts' => ['fi' => 'Sinulla on nyt rooli {role}.{open_tasks}', 'sv' => ''],
            ],
            $this->options[$option][MessageTemplates::BODY]
        );
        self::assertSame(['x' => 1], $this->options[$option]['settings']);
        self::assertSame(
            'OK.{open_tasks}',
            $this->options[MessageTemplates::OPTION_PREFIX . 'newcomer_ok'][MessageTemplates::BODY]['source']
        );
        self::assertArrayNotHasKey(MessageTemplates::OPTION_PREFIX . 'access_pass', $this->options);
        self::assertSame(
            ['open_tasks', 'organizer_line', 'join_answers'],
            $this->options[AccessTextsMigration::OPTION]
        );

        // Done once: a text edited back afterwards is left alone.
        $this->options[$option][MessageTemplates::BODY]['source'] = 'Edited.';
        AccessTextsMigration::migrate();

        self::assertSame('Edited.', $this->options[$option][MessageTemplates::BODY]['source']);
    }

    /**
     * The organizer DM gets the Admin tab line in each language that has it;
     * a language with no translation keeps its text.
     */
    public function testTheOrganizerDmGetsTheAdminTabLine(): void
    {
        $option = MessageTemplates::OPTION_PREFIX . AccessTextsMigration::ORGANIZER_KIND;
        $this->options[$option] = [MessageTemplates::BODY => [
            'source' => '⭐ Welcome, organizer.',
            'lang' => 'en',
            'texts' => ['sv' => 'Välkommen.', 'en' => ''],
        ]];
        $this->options[AccessTextsMigration::OPTION] = ['open_tasks'];

        AccessTextsMigration::migrate();

        self::assertSame(
            [
                'source' => '⭐ Welcome, organizer. ' . MessageTemplates::adminTabLine(),
                'lang' => 'en',
                'texts' => ['sv' => 'Välkommen.', 'en' => ''],
            ],
            $this->options[$option][MessageTemplates::BODY]
        );
        self::assertStringEndsWith(
            MessageTemplates::adminTabLine(),
            MessageTemplates::find(AccessTextsMigration::ORGANIZER_KIND)->defaultBody()
        );
    }

    /**
     * The organizers' "wants to join" texts get {join_answers} once, on its
     * own line; a text that has it already is left alone.
     */
    public function testTheNewcomerRequestGetsTheJoinAnswersOnce(): void
    {
        $email = MessageTemplates::OPTION_PREFIX . 'newcomer_waiting';
        $dm = MessageTemplates::OPTION_PREFIX . 'newcomer_waiting_dm';
        $this->options[$email] = [MessageTemplates::BODY => [
            'source' => '{member} wants to join.',
            'lang' => 'en',
            'texts' => ['fi' => '{member} haluaa mukaan.', 'sv' => ''],
        ]];
        $this->options[$dm] = [MessageTemplates::BODY => [
            'source' => "New: {member}.\n{join_answers}",
            'texts' => [],
        ]];
        $this->options[AccessTextsMigration::OPTION] = ['open_tasks', 'organizer_line'];

        AccessTextsMigration::migrate();

        self::assertSame(
            [
                'source' => "{member} wants to join.\n\n{join_answers}",
                'lang' => 'en',
                'texts' => ['fi' => "{member} haluaa mukaan.\n\n{join_answers}", 'sv' => ''],
            ],
            $this->options[$email][MessageTemplates::BODY]
        );
        self::assertSame("New: {member}.\n{join_answers}", $this->options[$dm][MessageTemplates::BODY]['source']);
        self::assertSame(
            ['open_tasks', 'organizer_line', 'join_answers'],
            $this->options[AccessTextsMigration::OPTION]
        );
    }

    public function testTheBuiltInNewcomerRequestHasTheJoinAnswers(): void
    {
        foreach (array_keys(AccessTextsMigration::JOIN_ANSWERS_KINDS) as $id) {
            self::assertStringEndsWith('{join_answers}', MessageTemplates::find($id)->defaultBody(), $id);
        }
    }

    public function testEveryBuiltInTextHasTheOpenTasks(): void
    {
        foreach (AccessTextsMigration::OPEN_TASKS_KINDS as $id) {
            self::assertStringEndsWith('{open_tasks}', MessageTemplates::find($id)->defaultBody(), $id);
        }
    }
}
