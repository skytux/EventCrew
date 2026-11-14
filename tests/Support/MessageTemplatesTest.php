<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\Organization;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Support\TranslationRow;
use EventCrew\Tests\TestCase;

/**
 * The message templates: the model, filling the placeholders, the fallback
 * to the built-in text, and saving and resetting what the Messages page
 * posts. Options live in an array; no language model is set up, so a save
 * never translates.
 */
final class MessageTemplatesTest extends TestCase
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
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('sanitize_text_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
    }

    private function templates(): MessageTemplates
    {
        return new MessageTemplates(new TranslatedText(new LlmClient(new Logger()), new Logger()));
    }

    private function person(string $name = 'Anna'): Person
    {
        return Person::fromRow(['id' => 8, 'display_name' => $name]);
    }

    private function option(string $id): string
    {
        return MessageTemplates::OPTION_PREFIX . $id;
    }

    // ---- The model --------------------------------------------------------

    public function testTheKindsAreBuiltOncePerLanguage(): void
    {
        $english = MessageTemplates::all();

        self::assertSame($english['reminder'], MessageTemplates::all()['reminder']);
        self::assertSame($english['reminder'], MessageTemplates::find('reminder'));

        $finnish = Language::run(Language::FINNISH, static fn (): array => MessageTemplates::all());

        self::assertNotSame($english['reminder'], $finnish['reminder']);
        self::assertNotSame($english['reminder']->label, $finnish['reminder']->label);
        self::assertSame(
            $finnish['reminder'],
            Language::run(Language::FINNISH, static fn (): ?MessageTemplate => MessageTemplates::find('reminder'))
        );
        self::assertSame($english['reminder'], MessageTemplates::find('reminder'));

        MessageTemplates::flushCache();

        self::assertNotSame($english['reminder'], MessageTemplates::find('reminder'));
        self::assertSame($english['reminder']->label, MessageTemplates::find('reminder')?->label);
    }

    public function testEveryKindHasAnIdALabelAChannelAndABuiltInText(): void
    {
        $all = MessageTemplates::all();

        self::assertArrayHasKey('reminder', $all);
        self::assertArrayHasKey('free_ticket_dm', $all);
        self::assertArrayHasKey('ticket', $all);
        self::assertArrayHasKey('newsletter', $all);

        foreach ($all as $id => $template) {
            self::assertSame($id, $template->id);
            self::assertNotSame('', $template->label, $id);
            self::assertContains(
                $template->channel,
                [MessageTemplate::CHANNEL_EMAIL, MessageTemplate::CHANNEL_TELEGRAM],
                $id
            );
            self::assertNotSame('', $template->defaultBody(), $id);
            self::assertSame(
                MessageTemplate::CHANNEL_EMAIL === $template->channel,
                $template->hasSubject(),
                $id . ': an email has a subject, a Telegram message does not'
            );

            foreach ($template->placeholders as $placeholder) {
                self::assertNotSame('', $placeholder['description'], $id);
                self::assertInstanceOf(\Closure::class, $placeholder['resolve'], $id);
            }

            foreach (['volunteer', 'shift', 'vuoro', 'vapaaehtoinen'] as $banned) {
                self::assertStringNotContainsStringIgnoringCase(
                    $banned,
                    $template->label . $template->description,
                    $id
                );
            }
        }

        self::assertNull(MessageTemplates::find('nope'));
        self::assertFalse(MessageTemplates::find('ticket')->wired, 'TicketMail does not read its template yet');
        self::assertTrue(MessageTemplates::find('reminder')->wired);
    }

    public function testThePlaceholderListNamesEachTagInCurlyBrackets(): void
    {
        $list = MessageTemplates::find('credit_granted')->placeholderList();

        self::assertSame(
            ['{name}', '{credits}', '{credit_help}', '{balance}', '{nights}', '{organization}'],
            array_keys($list)
        );
        self::assertSame('How many free-entry credits were given.', $list['{credits}']);
    }

    public function testEveryKindNamesTheOrganization(): void
    {
        foreach (MessageTemplates::all() as $template) {
            self::assertArrayHasKey('{organization}', $template->placeholderList(), $template->id);
        }

        self::assertSame('You are now an organizer of {organization}', MessageTemplates::find('access_organizer')->defaultSubject());
        self::assertSame('You are now an organizer of Test Site', MessageTemplates::subject('access_organizer'));

        $this->options[Organization::OPTION] = 'Ecstatic Dance Helsinki';

        self::assertSame('You are now an organizer of Ecstatic Dance Helsinki', MessageTemplates::subject('access_organizer'));
        self::assertStringStartsWith("⭐ You’re now an organizer of Ecstatic Dance Helsinki.\n\n", MessageTemplates::body('access_organizer_dm'));
        self::assertSame(
            'Olet nyt järjestäjä: Ecstatic Dance Helsinki',
            Language::run(Language::FINNISH, static fn (): string => MessageTemplates::subject('access_organizer'))
        );
    }

    public function testTheBuiltInTextKeepsTheOldWording(): void
    {
        self::assertSame(
            "Hi {name},\n\nAn organizer has given you {credits} free-entry credits. You can spend them on upcoming"
                . " events — {credit_help}\n{balance}\n{nights}",
            MessageTemplates::find('credit_granted')->defaultBody()
        );
        self::assertSame(
            "Hi Anna,\n\nAn organizer has given you 2 free-entry credits. You can spend them on upcoming"
                . ' events — tap 🎟 Free-entry ticket below.',
            MessageTemplates::body('credit_granted', ['person' => $this->person(), 'credits' => 2])
        );
        self::assertStringContainsString(
            'a free-entry credit.',
            MessageTemplates::find('credit_granted')->defaultBody(['credits' => 1]),
            'one credit picks the singular sentence'
        );
        self::assertSame('Hi %1$s, 100% sure', MessageTemplates::tag('Hi %1$s, 100%% sure', []));
        self::assertSame('Hi {name}', MessageTemplates::tag('Hi %1$s', ['%1$s' => '{name}']));
    }

    /**
     * The free-entry email gives a person with no Telegram chat the Me tab of
     * the crew page; a person with one keeps the bot text, in English and in
     * Finnish.
     */
    public function testTheCreditEmailGivesAWebOnlyPersonTheCrewPage(): void
    {
        $this->options['eventcrew_signup_page_id'] = 7;
        Functions\when('get_permalink')->alias(
            static fn (int $id): string|false => 7 === $id ? 'https://site.test/crew/' : false
        );
        $web = ['person' => $this->person(), 'credits' => 1];
        $bot = ['person' => Person::fromRow(['id' => 8, 'display_name' => 'Anna', 'telegram_chat_id' => 4242]), 'credits' => 1];

        self::assertSame(
            "Hi Anna,\n\nAn organizer has given you a free-entry credit. You can spend it on any upcoming event —"
                . ' open the Tickets tab of the crew page (https://site.test/crew/) and pick a night to get your'
                . ' free ticket.',
            MessageTemplates::body('credit_granted', $web)
        );
        self::assertSame(
            "Hi Anna,\n\nAn organizer has given you a free-entry credit. You can spend it on any upcoming event —"
                . ' tap 🎟 Free-entry ticket below.',
            MessageTemplates::body('credit_granted', $bot)
        );
        self::assertSame(
            "Hei Anna,\n\njärjestäjä antoi sinulle 3 vapaalippua. Voit käyttää ne tuleviin tapahtumiin —"
                . ' napauta alta 🎟 Vapaalippu.',
            Language::run(
                Language::FINNISH,
                static fn (): string => MessageTemplates::body('credit_granted', ['credits' => 3] + $bot)
            ),
            'the split Finnish recombines to the old text'
        );
        self::assertSame(
            "Hei Anna,\n\njärjestäjä antoi sinulle vapaalipun. Voit käyttää sen mihin tahansa tulevaan tapahtumaan —"
                . ' avaa crew-sivun Liput-välilehti (https://site.test/crew/) ja valitse ilta, niin saat ilmaisen'
                . ' lippusi.',
            Language::run(Language::FINNISH, static fn (): string => MessageTemplates::body('credit_granted', $web))
        );
    }

    /**
     * The Cancel button is on the person's own task row in the Board tab; My
     * tasks in the Me tab has none. The web cancel texts name the Board tab.
     */
    public function testTheWebCancelTextsNameTheBoardTab(): void
    {
        $this->crewPage();
        $task = new Task(5, '2026-08-01', 'decorate', 2, null, 'Party', '18:00:00', '20:00:00');
        $context = ['person' => $this->person(), 'task' => $task];

        foreach (['reminder', 'late_cancel'] as $kind) {
            $text = MessageTemplates::body($kind, $context);

            self::assertMatchesRegularExpression(
                '~[Tt]ap Cancel on your task in the Board tab of the crew page \(https://site\.test/crew/\)~',
                $text,
                $kind
            );
            self::assertStringNotContainsString('Me tab', $text, $kind);

            $finnish = Language::run(
                Language::FINNISH,
                static fn (): string => MessageTemplates::body($kind, $context)
            );

            self::assertStringContainsString('Peru tehtävässäsi crew-sivun Taulu-välilehdellä', $finnish, $kind);
            self::assertStringNotContainsString('Minä-välilehdellä', $finnish, $kind);
        }
    }

    /**
     * The Open sales desk button at the top of the crew page opens the sales
     * desk for the sales-desk crew and organizers alike (Owner 2026-11-13),
     * so a web-only person's door help names it for both.
     */
    public function testTheWebDoorHelpNamesOpenSalesDesk(): void
    {
        $this->crewPage();
        $organizer = [
            'person' => Person::fromRow(['id' => 1, 'display_name' => 'Olli', 'is_organizer' => 1]),
            'day' => 'Sat 1 Aug',
        ];
        $crew = ['person' => $this->person(), 'day' => 'Sat 1 Aug'];
        $help = 'Tap Open sales desk at the top of the crew page (https://site.test/crew/).';

        foreach (['organizer_notice', 'sales_desk_link'] as $kind) {
            foreach ([$organizer, $crew] as $context) {
                self::assertStringContainsString($help, MessageTemplates::body($kind, $context), $kind);
                self::assertStringNotContainsString('Me tab', MessageTemplates::body($kind, $context), $kind);
                self::assertStringContainsString(
                    'Napauta Avaa myyntipiste crew-sivun yläosassa (https://site.test/crew/).',
                    Language::run(Language::FINNISH, static fn (): string => MessageTemplates::body($kind, $context)),
                    $kind
                );
            }
        }
    }

    private function crewPage(): void
    {
        $this->options['eventcrew_signup_page_id'] = 7;
        Functions\when('get_permalink')->alias(
            static fn (int $id): string|false => 7 === $id ? 'https://site.test/crew/' : false
        );
    }

    // ---- Filling the placeholders -----------------------------------------

    public function testFillReplacesKnownPlaceholdersAndLeavesOthers(): void
    {
        $template = MessageTemplates::find('credit_granted');

        self::assertSame(
            'Anna got 3 {unknown} {name',
            $template->fill('{name} got {credits} {unknown} {name', ['person' => $this->person(), 'credits' => 3])
        );
        self::assertSame(
            'Hi , here is 1',
            $template->fill('Hi {name}, here is {credits}', []),
            'a missing context value becomes empty, a missing count becomes 1'
        );
    }

    public function testFillDoesNotReplaceInsideAFilledValue(): void
    {
        $template = MessageTemplates::find('credit_granted');

        self::assertSame(
            'Hi {credits}!',
            $template->fill('Hi {name}!', ['person' => $this->person('{credits}'), 'credits' => 4])
        );
    }

    // ---- The fallback -----------------------------------------------------

    public function testWithNothingStoredTheBuiltInTextIsSent(): void
    {
        $context = ['person' => $this->person(), 'credits' => 2];

        self::assertSame('You’ve been given free entry', MessageTemplates::subject('credit_granted', $context));
        self::assertStringStartsWith(
            "Hi Anna,\n\nAn organizer has given you 2 free-entry credits.",
            MessageTemplates::body('credit_granted', $context)
        );

        $finnish = Language::run(
            Language::FINNISH,
            static fn (): string => MessageTemplates::subject('credit_granted')
        );
        self::assertSame(
            Language::run(Language::FINNISH, static fn (): string => __('You’ve been given free entry', 'eventcrew')),
            $finnish
        );
        self::assertNotSame('You’ve been given free entry', $finnish, 'the built-in text keeps its translation');

        self::assertSame('', MessageTemplates::subject('credit_granted_dm'), 'a Telegram message has no subject');
        self::assertSame('', MessageTemplates::body('nope'));
    }

    public function testTheStoredTextIsSentInTheReadersLanguage(): void
    {
        $this->options[$this->option('credit_granted')] = [
            MessageTemplates::BODY => [
                'source' => 'Gift for {name}: {credits}',
                'texts' => ['fi' => 'Lahja: {credits}, {name}', 'en' => 'Gift for {name}: {credits}'],
                'manual' => ['fi' => false, 'en' => false],
            ],
        ];
        $context = ['person' => $this->person(), 'credits' => 5];

        self::assertSame(
            'Lahja: 5, Anna',
            Language::run(Language::FINNISH, static fn (): string => MessageTemplates::body('credit_granted', $context))
        );
        self::assertSame(
            'Gift for Anna: 5',
            Language::run(Language::ENGLISH, static fn (): string => MessageTemplates::body('credit_granted', $context))
        );
        self::assertTrue(MessageTemplates::isChanged('credit_granted'));
        self::assertFalse(MessageTemplates::isChanged('credit_granted_dm'));
        self::assertSame(
            'You’ve been given free entry',
            Language::run(Language::ENGLISH, static fn (): string => MessageTemplates::subject('credit_granted')),
            'the subject was not changed and stays the built-in one'
        );
    }

    public function testAnEmptyStoredTextFallsBackToTheBuiltInText(): void
    {
        $this->options[$this->option('credit_granted')] = [
            MessageTemplates::SUBJECT => [
                'source' => 'Free entry!',
                'texts' => ['fi' => '', 'en' => 'Free entry!'],
                'manual' => ['fi' => false, 'en' => false],
            ],
        ];

        self::assertSame(
            'Free entry!',
            Language::run(Language::FINNISH, static fn (): string => MessageTemplates::subject('credit_granted')),
            'an empty language uses the stored source, as TranslatedText::text() does'
        );

        $this->options[$this->option('credit_granted')][MessageTemplates::SUBJECT]['source'] = '';
        $this->options[$this->option('credit_granted')][MessageTemplates::SUBJECT]['texts']['fi'] = ' ';
        self::assertSame(
            Language::run(Language::FINNISH, static fn (): string => __('You’ve been given free entry', 'eventcrew')),
            Language::run(Language::FINNISH, static fn (): string => MessageTemplates::subject('credit_granted'))
        );
    }

    public function testTheBuiltInValueHasEachLanguage(): void
    {
        $value = MessageTemplates::defaultValue('credit_granted', MessageTemplates::SUBJECT);

        self::assertSame('You’ve been given free entry', $value['source']);
        self::assertSame('You’ve been given free entry', $value['texts'][Language::ENGLISH]);
        self::assertSame(
            Language::run(Language::FINNISH, static fn (): string => __('You’ve been given free entry', 'eventcrew')),
            $value['texts'][Language::FINNISH]
        );
        self::assertNotContains(true, $value['manual']);
        self::assertSame($value, MessageTemplates::value('credit_granted', MessageTemplates::SUBJECT));
    }

    public function testTheBuiltInValueCoversEverySiteLanguage(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'da'];

        $value = MessageTemplates::defaultValue('credit_granted', MessageTemplates::SUBJECT);

        self::assertSame(Language::ENGLISH, $value['lang']);
        self::assertSame(['fi', 'en', 'da'], array_keys($value['texts']));
        self::assertSame('', $value['texts']['da'], 'no Danish translation file: left for the Translations page');
        self::assertNotSame('', $value['texts'][Language::FINNISH]);
    }

    public function testATextStoredInAThirdLanguageIsSentInIt(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $this->options[$this->option('credit_granted')] = [
            MessageTemplates::SUBJECT => [
                'source' => 'Free entry for {name}',
                'lang' => 'en',
                'texts' => ['en' => 'Free entry for {name}', 'fi' => 'Vapaa sisäänpääsy: {name}', 'sv' => 'Fritt inträde: {name}'],
            ],
        ];

        self::assertSame(
            'Fritt inträde: Anna',
            Language::run('sv', fn (): string => MessageTemplates::subject('credit_granted', ['person' => $this->person()]))
        );
    }

    // ---- The Translations page --------------------------------------------

    public function testEveryKindHasItsRowsOnTheTranslationsPage(): void
    {
        $rows = [];

        foreach (MessageTemplates::translationRows() as $row) {
            $rows[$row->id] = $row;
        }

        self::assertArrayHasKey('message.credit_granted.subject', $rows);
        self::assertArrayHasKey('message.credit_granted.body', $rows);
        self::assertArrayHasKey('message.reminder_dm.body', $rows);
        self::assertArrayNotHasKey('message.reminder_dm.subject', $rows, 'a Telegram message has no subject');
        self::assertSame(TranslationRow::GROUP_EMAILS, $rows['message.credit_granted.subject']->group);
        self::assertSame(TranslationRow::GROUP_TELEGRAM, $rows['message.reminder_dm.body']->group);
        self::assertSame(
            MessageTemplates::defaultValue('credit_granted', MessageTemplates::SUBJECT),
            $rows['message.credit_granted.subject']->value
        );
    }

    /** The Translations page row of a text keeps the kind's required placeholders; a subject row has none. */
    public function testTheTranslationsPageRowKnowsTheRequiredPlaceholders(): void
    {
        $rows = [];

        foreach (MessageTemplates::translationRows() as $row) {
            $rows[$row->id] = $row;
        }

        self::assertSame(['{email}'], $rows['message.email_changed.body']->required);
        self::assertSame([], $rows['message.email_changed.subject']->required);
        self::assertSame([], $rows['message.credit_granted.body']->required);
    }

    public function testARowSavedOnTheTranslationsPageIsTheMessagesPageText(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $registry = new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
        $registry->add([MessageTemplates::class, 'translationRows']);
        $default = MessageTemplates::defaultValue('credit_granted', MessageTemplates::SUBJECT);

        $result = $registry->saveRow('message.credit_granted.subject', $default['source'], ['sv' => 'Fritt inträde']);

        self::assertTrue($result['found']);
        $stored = MessageTemplates::stored('credit_granted')[MessageTemplates::SUBJECT];
        self::assertSame('Fritt inträde', $stored['texts']['sv']);
        self::assertTrue($stored['manual']['sv']);
        self::assertSame($stored, MessageTemplates::value('credit_granted', MessageTemplates::SUBJECT));

        // The built-in value stored again: nothing is kept.
        MessageTemplates::storePart('credit_granted', MessageTemplates::SUBJECT, $default);
        self::assertArrayNotHasKey($this->option('credit_granted'), $this->options);
    }

    // ---- Saving and resetting ---------------------------------------------

    public function testSavingTheBuiltInTextStoresNothing(): void
    {
        $subject = MessageTemplates::defaultValue('credit_granted', MessageTemplates::SUBJECT);
        $body = MessageTemplates::defaultValue('credit_granted', MessageTemplates::BODY);

        $error = $this->templates()->save('credit_granted', [
            MessageTemplates::SUBJECT => ['source' => $subject['source'], 'texts' => $subject['texts']],
            MessageTemplates::BODY => ['source' => $body['source'], 'texts' => $body['texts']],
        ]);

        self::assertSame('', $error);
        self::assertArrayNotHasKey($this->option('credit_granted'), $this->options);
        self::assertFalse(MessageTemplates::isChanged('credit_granted'));
    }

    /**
     * The built-in English with machine texts only (a Translations page
     * save) is not stored: it would freeze the built-in text (Owner 2026-11-07).
     */
    public function testAMachineCopyOfTheBuiltInTextIsNotStored(): void
    {
        $body = MessageTemplates::defaultValue('credit_granted', MessageTemplates::BODY);
        $body['texts'][Language::FINNISH] = 'Konekäännös {name}';
        $body['manual'] = array_fill_keys(array_keys($body['texts']), false);

        MessageTemplates::storePart('credit_granted', MessageTemplates::BODY, $body);

        self::assertArrayNotHasKey($this->option('credit_granted'), $this->options);

        // Written by hand: kept.
        $body['manual'][Language::FINNISH] = true;
        MessageTemplates::storePart('credit_granted', MessageTemplates::BODY, $body);

        self::assertSame('Konekäännös {name}', MessageTemplates::stored('credit_granted')[MessageTemplates::BODY]['texts'][Language::FINNISH]);
    }

    public function testSavingAChangedTextStoresItAndSendsIt(): void
    {
        $subject = MessageTemplates::defaultValue('credit_granted', MessageTemplates::SUBJECT);

        $this->templates()->save('credit_granted', [
            MessageTemplates::SUBJECT => [
                'source' => 'Free entry for {name}',
                'texts' => ['en' => 'Free entry for {name}', 'fi' => 'Vapaa sisäänpääsy: {name}'],
            ],
        ]);

        $stored = MessageTemplates::stored('credit_granted');
        self::assertSame('Free entry for {name}', $stored[MessageTemplates::SUBJECT]['source']);
        self::assertTrue($stored[MessageTemplates::SUBJECT]['manual'][Language::FINNISH]);
        self::assertNull($stored[MessageTemplates::BODY], 'a part that was not posted stays the built-in text');
        self::assertNotSame($subject, $stored[MessageTemplates::SUBJECT]);

        self::assertSame(
            'Vapaa sisäänpääsy: Anna',
            Language::run(
                Language::FINNISH,
                fn (): string => MessageTemplates::subject('credit_granted', ['person' => $this->person()])
            )
        );
    }

    public function testSettingsPassThroughTheFilterAndSurviveAReset(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value, string $id = '', mixed $posted = null): mixed {
                if (MessageTemplates::SETTINGS_FILTER !== $hook) {
                    return $value;
                }

                return ['ai' => '1' === ($posted['ai'] ?? ''), 'id' => $id];
            }
        );

        $this->templates()->save('reminder_dm', [
            MessageTemplates::BODY => ['source' => 'See you, {role}!', 'texts' => ['en' => 'See you, {role}!']],
            'ai' => '1',
        ]);

        self::assertSame(['ai' => true, 'id' => 'reminder_dm'], MessageTemplates::settings('reminder_dm'));
        self::assertTrue(MessageTemplates::isChanged('reminder_dm'));

        MessageTemplates::reset('reminder_dm');

        self::assertFalse(MessageTemplates::isChanged('reminder_dm'));
        self::assertSame(['ai' => true, 'id' => 'reminder_dm'], MessageTemplates::settings('reminder_dm'));
        self::assertSame(
            ['settings' => ['ai' => true, 'id' => 'reminder_dm']],
            $this->options[$this->option('reminder_dm')]
        );
    }

    public function testResetWithNoSettingsDeletesTheOption(): void
    {
        $this->options[$this->option('slot_freed')] = [
            MessageTemplates::SUBJECT => ['source' => 'Open!', 'texts' => ['en' => 'Open!'], 'manual' => []],
        ];

        MessageTemplates::reset('slot_freed');

        self::assertArrayNotHasKey($this->option('slot_freed'), $this->options);
    }

    public function testSavingAnUnknownKindDoesNothing(): void
    {
        self::assertSame('', $this->templates()->save('nope', [MessageTemplates::BODY => ['source' => 'x']]));
        self::assertSame([], $this->options);
    }

    /**
     * The task in the late-cancel and no-show texts ("Door at Party, Sat 1
     * Aug 18:00–20:00") is translated too: the Finnish texts have no English
     * " at " in them.
     */
    public function testTheFinnishLateCancelAndNoShowTextsHaveNoEnglishAt(): void
    {
        $task = new Task(5, '2026-08-01', 'decorate', 2, null, 'Party', '18:00:00', '20:00:00');
        $context = ['person' => $this->person(), 'task' => $task];

        self::assertStringContainsString(
            ' at Party, ',
            Language::run(Language::ENGLISH, static fn (): string => MessageTemplates::body('late_cancel', $context))
        );

        foreach (['late_cancel', 'late_cancel_dm', 'no_show', 'no_show_dm'] as $kind) {
            $text = Language::run(
                Language::FINNISH,
                static fn (): string => MessageTemplates::body($kind, $context)
            );

            self::assertStringContainsString('Party, ', $text, $kind);
            self::assertStringNotContainsString(' at ', $text, $kind);
        }
    }
}
