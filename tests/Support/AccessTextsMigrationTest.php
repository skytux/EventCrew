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
            ['open_tasks', 'organizer_line', 'join_answers', 'organizer_access'],
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
        $this->options[AccessTextsMigration::OPTION] = ['open_tasks', 'join_answers', 'organizer_access'];

        AccessTextsMigration::migrate();

        self::assertSame(
            [
                'source' => '⭐ Welcome, organizer. ' . MessageTemplates::adminTabLine(),
                'lang' => 'en',
                'texts' => ['sv' => 'Välkommen.', 'en' => ''],
            ],
            $this->options[$option][MessageTemplates::BODY]
        );
        self::assertStringContainsString(
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
        $this->options[AccessTextsMigration::OPTION] = ['open_tasks', 'organizer_line', 'organizer_access'];

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
            ['open_tasks', 'organizer_line', 'organizer_access', 'join_answers'],
            $this->options[AccessTextsMigration::OPTION]
        );
    }

    /**
     * A stored organizer message, email and DM, gets the lines on the bot's
     * organizer commands, the organizer help and wp-admin, each after an
     * empty line, in each language that has them; a line the text mentions
     * already is not added again, and an empty text stays empty.
     */
    public function testTheOrganizerMessageGetsTheNewLinesOnce(): void
    {
        $email = MessageTemplates::OPTION_PREFIX . 'access_organizer';
        $dm = MessageTemplates::OPTION_PREFIX . 'access_organizer_dm';
        $this->options[$email] = [MessageTemplates::BODY => [
            'source' => 'Welcome, organizer.',
            'lang' => 'en',
            'texts' => ['fi' => 'Tervetuloa, järjestäjä.', 'en' => ''],
        ]];
        $this->options[$dm] = [MessageTemplates::BODY => [
            'source' => "Welcome. Ask Kim for wp-admin.\n\n",
            'texts' => [],
        ]];
        $this->options[AccessTextsMigration::OPTION] = ['open_tasks', 'organizer_line', 'join_answers'];

        AccessTextsMigration::migrate();

        $lines = MessageTemplates::organizerAccessLines();
        self::assertSame(
            "Welcome, organizer.\n\n" . implode("\n\n", $lines),
            $this->options[$email][MessageTemplates::BODY]['source']
        );
        self::assertSame('', $this->options[$email][MessageTemplates::BODY]['texts']['en']);
        $fi = $this->options[$email][MessageTemplates::BODY]['texts']['fi'];
        self::assertStringStartsWith("Tervetuloa, järjestäjä.\n\n🤖 Yksityisessä keskustelussa botin kanssa", $fi);
        self::assertStringEndsWith('Jos tarvitset niitä, pyydä: {contact}.', $fi);
        self::assertSame(
            "Welcome. Ask Kim for wp-admin.\n\n" . $lines['bot'] . "\n\n" . $lines['help'],
            $this->options[$dm][MessageTemplates::BODY]['source']
        );
        self::assertSame(
            ['open_tasks', 'organizer_line', 'join_answers', 'organizer_access'],
            $this->options[AccessTextsMigration::OPTION]
        );

        // The built-in texts have the lines already.
        foreach (AccessTextsMigration::ORGANIZER_ACCESS_KINDS as $id) {
            self::assertStringEndsWith($lines['wp_admin'], MessageTemplates::find($id)->defaultBody(), $id);
        }
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
