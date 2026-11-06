<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\AiPrompts;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\EventAiText;
use EventCrew\Support\EventName;
use EventCrew\Support\EventSource;
use EventCrew\Support\EventTimes;
use EventCrew\Support\EventVenue;
use EventCrew\Support\VenueReader;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FollowupText;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TranslatedText;
use EventCrew\Web\CrewAdminFollowup;
use EventCrew\Web\CrewAdminNewsletter;

/**
 * What the language model is asked and what it wrote, shown on two tabs of
 * the Messages page (MessagesPage):
 * - AI: the built-in prompts (AiPrompts), each with "Reset to built-in", and
 *   the organizers' own placeholders (EventAiText::placeholders()), each a
 *   {name} with its prompt and a fallback text for when the AI fails;
 * - Coming events: one card per coming event with the texts the AI wrote
 *   for it (EventAiText), each with an edit box and "Write again", and
 *   "Write the AI texts now" for the whole event; above the cards, the
 *   one-time texts of the next post-event email (FollowupText) and the next
 *   announcement (NewsletterText), saved through the same code as the crew
 *   app's Admin tab (CrewAdminFollowup, CrewAdminNewsletter).
 *
 * These were the separate AI prompts page (PAGE_SLUG); its old address
 * redirects to the AI tab (redirectOldPage()).
 */
final class AiPromptsPage
{
    /** The old page's slug: its address redirects to the Messages page. */
    public const PAGE_SLUG = 'eventcrew-ai-prompts';

    public const PROMPTS_ACTION = 'eventcrew_ai_prompts_save';

    public const PLACEHOLDERS_ACTION = 'eventcrew_ai_placeholders_save';

    public const EVENT_TEXT_ACTION = 'eventcrew_ai_event_text_save';

    /** "Write the AI texts now" (one event) and "Write again" (one text). */
    public const REWRITE_ACTION = 'eventcrew_ai_event_text_rewrite';

    public const FOLLOWUP_TEXT_ACTION = 'eventcrew_admin_followup_text';

    public const NEWSLETTER_TEXT_ACTION = 'eventcrew_admin_newsletter_text';

    /** One event's own doors time (EventTimes), from its card on the Coming events tab. */
    public const DOORS_ACTION = 'eventcrew_event_doors_close';

    /** One event's own venue (EventVenue), from its card on the Coming events tab. */
    public const VENUE_ACTION = 'eventcrew_event_venue';

    /** admin-post (GET): read one event's venue again with the AI (VenueReader), from its card. */
    public const VENUE_AGAIN_ACTION = 'eventcrew_event_venue_again';

    public const NONCE_ACTION = 'eventcrew_ai_prompts';

    /** How many coming events get a card. */
    public const EVENT_CARDS = 8;

    /** The anchor of the one-time texts on the Coming events tab. */
    public const ONE_TIME_ANCHOR = 'ec-one-time';

    /** The BackgroundJobs kinds of the one-time texts, saved after their translation. */
    public const FOLLOWUP_JOB = 'followup_text';
    public const NEWSLETTER_JOB = 'newsletter_text';

    /** The BackgroundJobs target of the announcement text. */
    public const NEWSLETTER_TARGET = 'newsletter:text';

    /** The notice code of a one-time text queued for translation. */
    public const QUEUED = 'queued';

    public function __construct(
        private readonly ?EventAiText $eventAiText = null,
        private readonly ?CrewAdminFollowup $followup = null,
        private readonly ?CrewAdminNewsletter $newsletter = null,
        private readonly ?FollowupMail $followupMail = null
    ) {
    }

    /**
     * What the AI tab shows.
     *
     * @return array{prompts: list<array<string, mixed>>, placeholders: list<array<string, string>>}
     */
    public function promptsData(): array
    {
        $prompts = [];

        foreach (AiPrompts::labels() as $key => $label) {
            $prompts[] = [
                'key' => $key,
                'label' => $label['label'],
                'help' => $label['help'],
                'text' => AiPrompts::raw($key),
                'changed' => AiPrompts::isChanged($key),
            ];
        }

        return [
            'prompts' => $prompts,
            'placeholders' => EventAiText::placeholders(),
        ];
    }

    /**
     * What the Coming events tab shows: a card per coming event (the next
     * EVENT_CARDS, and any later one that has texts), the AI texts of each,
     * and the one-time texts.
     *
     * @param list<array{id: int, title: string, date: string}>|null $upcoming The coming events; null reads EventSource.
     * @return array<string, mixed>
     */
    public function eventsData(?array $upcoming = null): array
    {
        $today = (string) current_time('Y-m-d');
        $cards = [];

        foreach ($upcoming ?? EventSource::upcoming() as $event) {
            if ('' === $event['date'] || $event['date'] < $today || count($cards) >= self::EVENT_CARDS) {
                continue;
            }

            $cards[(int) $event['id']] = [
                'event_id' => (int) $event['id'],
                'date' => $event['date'],
                'title' => EventName::name((int) $event['id'], EventName::lang(), $event['title']),
                'items' => [],
            ];
        }

        foreach (EventAiText::overview() as $event) {
            $cards[$event['event_id']] = $event;
        }

        uasort($cards, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        foreach ($cards as $id => $card) {
            $cards[$id]['doors_own'] = EventTimes::ownDoorsClose((int) $card['event_id']);
            $cards[$id]['doors_close'] = EventTimes::doorsClose((int) $card['event_id']);
            $cards[$id]['venue_own'] = EventVenue::own((int) $card['event_id']);
            $cards[$id]['venue'] = EventVenue::forEvent((int) $card['event_id']);
            $cards[$id]['venue_ai'] = VenueReader::isSuggested((int) $card['event_id']);
            $cards[$id]['venue_again_url'] = VenueReader::canReadAgain((int) $card['event_id'])
                ? self::venueAgainUrl((int) $card['event_id'])
                : '';
        }

        $titles = array_column($cards, 'title', 'date');
        $night = null === $this->followupMail ? '' : $this->followupMail->nextNight(
            null,
            null === $upcoming ? null : array_column($upcoming, 'date')
        );

        return [
            'events' => array_values($cards),
            'can_write' => [] !== EventAiText::activeTemplates() || [] !== EventAiText::placeholders(),
            'followup' => null === $this->followup ? null : $this->followup->view() + [
                'night' => '' === $night ? '' : TicketMail::shortDate($night)
                    . (isset($titles[$night]) ? ' · ' . $titles[$night] : ''),
            ],
            'newsletter' => null === $this->newsletter ? null : $this->newsletter->view(),
        ];
    }

    /** Saves the prompts, or resets the one whose "Reset to built-in" was pressed. */
    public function savePrompts(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $result = $this->applyPrompts(wp_unslash($_POST));

        self::back(MessagesPage::TAB_AI, $result);
    }

    /**
     * The prompts form: a reset of one prompt, or a save of every prompt.
     * Returns the notice.
     *
     * @param array<string, mixed> $post
     */
    public function applyPrompts(array $post): string
    {
        $reset = sanitize_key((string) ($post['reset'] ?? ''));

        if ('' !== $reset && isset(AiPrompts::DEFAULTS[$reset])) {
            AiPrompts::reset($reset);

            return __('The prompt is back to the built-in text.', 'eventcrew');
        }

        $texts = is_array($post['prompts'] ?? null) ? $post['prompts'] : [];

        foreach (array_keys(AiPrompts::DEFAULTS) as $key) {
            if (isset($texts[$key]) && is_string($texts[$key])) {
                AiPrompts::save($key, sanitize_textarea_field($texts[$key]));
            }
        }

        return __('The prompts are saved.', 'eventcrew');
    }

    public function savePlaceholders(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        $type = 'success';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $notice = $this->applyPlaceholders(wp_unslash($_POST), $type);

        self::back(MessagesPage::TAB_AI, $notice, $type);
    }

    /**
     * The placeholders form. A row with an empty name is removed. A row whose
     * name is a built-in placeholder of a message kind ({name}, {event}, ...)
     * is not saved; the notice names it and $type becomes 'error'. Returns
     * the notice.
     *
     * @param array<string, mixed> $post
     */
    public function applyPlaceholders(array $post, string &$type = 'success'): string
    {
        $rows = [];
        $builtIn = self::builtInNames();
        $clashes = [];

        foreach (is_array($post['placeholders'] ?? null) ? $post['placeholders'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = sanitize_text_field((string) ($row['name'] ?? ''));

            // The name as EventAiText keeps it: small letters, no { }.
            if (isset($builtIn[strtolower(trim($name, " \t{}"))])) {
                $clashes[] = '{' . strtolower(trim($name, " \t{}")) . '}';

                continue;
            }

            $rows[] = [
                'name' => $name,
                'prompt' => sanitize_textarea_field((string) ($row['prompt'] ?? '')),
                'fallback' => sanitize_textarea_field((string) ($row['fallback'] ?? '')),
            ];
        }

        if ([] !== $clashes) {
            EventAiText::savePlaceholders($rows);
            $type = 'error';

            return sprintf(
                /* translators: %s: the placeholder names, like {name}, {event} */
                __('Not saved: %s. A message already uses this name for its own placeholder. Choose another name. The other placeholders are saved.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                implode(', ', array_unique($clashes))
            );
        }

        $given = count(array_filter($rows, static fn (array $row): bool => '' !== trim($row['name'])));
        $kept = count(EventAiText::savePlaceholders($rows));

        return $kept < $given
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? __('Saved. Some placeholders were left out: a name uses only small letters a-z, digits and _, starts with a letter, is used once, and has a prompt.', 'eventcrew')
            : __('The placeholders are saved.', 'eventcrew');
    }

    /**
     * The built-in placeholder names of every message kind, as keys.
     *
     * @return array<string, true>
     */
    private static function builtInNames(): array
    {
        $names = [];

        foreach (MessageTemplates::all() as $template) {
            foreach (array_keys($template->placeholders) as $name) {
                $names[(string) $name] = true;
            }
        }

        return $names;
    }

    public function saveEventText(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $post = wp_unslash($_POST);
        $code = $this->applyEventText($post);

        self::back(
            MessagesPage::TAB_EVENTS,
            self::noticeText($code),
            EventAiText::NOT_FOUND === $code ? 'error' : 'success',
            self::eventAnchor((int) ($post['event_id'] ?? 0))
        );
    }

    /**
     * One event text's form, the same as the crew app's: the text in each
     * language, or "Use the AI text again" (op=ai). Returns the result code
     * of EventAiText::saveForm().
     *
     * @param array<string, mixed> $post
     */
    public function applyEventText(array $post, int $personId = 0): string
    {
        $texts = [];

        foreach (is_array($post['texts'] ?? null) ? $post['texts'] : [] as $lang => $text) {
            $texts[(string) $lang] = sanitize_textarea_field((string) $text);
        }

        return EventAiText::saveForm(
            (int) ($post['event_id'] ?? 0),
            sanitize_text_field((string) ($post['key'] ?? '')),
            'ai' === sanitize_key((string) ($post['op'] ?? '')),
            $texts,
            $personId
        );
    }

    /** The sentence for a result code of EventAiText::saveForm(). */
    public static function noticeText(string $code): string
    {
        return match ($code) {
            EventAiText::SAVED => __('Saved. This text is sent as you wrote it.', 'eventcrew'),
            EventAiText::BACK_TO_AI => __('The AI text is used again.', 'eventcrew'),
            default => __('Nothing was saved: that text was not found.', 'eventcrew'),
        };
    }

    /**
     * admin-post: "Write the AI texts now" of one event (a form) or "Write
     * again" of one text (a link, so event_id and key may come in the URL).
     */
    public function rewriteEventText(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification -- verified in assertCanSave().
        $request = wp_unslash($_POST) + wp_unslash($_GET);
        // phpcs:enable WordPress.Security.NonceVerification

        $type = 'success';
        $eventId = (int) ($request['event_id'] ?? 0);
        $notice = $this->applyRewrite($request, $type, true);

        self::back(MessagesPage::TAB_EVENTS, $notice, $type, self::eventAnchor($eventId));
    }

    /**
     * Asks the AI for the texts of $request's event_id (and key, for one
     * text) again. Returns the notice; $type becomes 'error' when a text
     * could not be written.
     *
     * With $background, each text is a background job (BackgroundJobs, kind
     * EventAiText::JOB) and the page answers at once; the stored texts stay
     * until each job is done. An unknown event or text, or an AI that is not
     * set up, is answered at once as before.
     *
     * @param array<string, mixed> $request
     */
    public function applyRewrite(array $request, string &$type = 'success', bool $background = false): string
    {
        if (null === $this->eventAiText) {
            $type = 'error';

            return __('Nothing was written: that event or text was not found.', 'eventcrew');
        }

        $eventId = (int) ($request['event_id'] ?? 0);
        $key = sanitize_text_field((string) ($request['key'] ?? ''));
        $keys = EventAiText::keys();
        $keys = '' === $key ? $keys : array_values(array_intersect($keys, [$key]));

        if (
            $background
            && [] !== $keys
            && AssistantSettings::isConfigured()
            && null !== EventSource::describe($eventId)
        ) {
            $labels = EventAiText::labels();

            foreach ($keys as $one) {
                BackgroundJobs::enqueue(
                    EventAiText::JOB,
                    EventAiText::jobTarget($eventId, $one),
                    ['event_id' => $eventId, 'key' => $one],
                    $labels[$one] ?? $one
                );
            }

            $type = 'success';

            return sprintf(
                /* translators: %d: how many texts the AI is asked for */
                _n('The AI is writing %d text.', 'The AI is writing %d texts.', count($keys), 'eventcrew'),
                count($keys)
            );
        }

        $result = $this->eventAiText->rewrite($eventId, $key);

        return self::rewriteNotice($result, $type);
    }

    /**
     * The background job of "Write again" (EventAiText::JOB).
     *
     * @param array<string, mixed> $args event_id and key.
     * @return array{ok: bool, error: string}
     */
    public function rewriteJob(array $args): array
    {
        if (null === $this->eventAiText) {
            return ['ok' => false, 'error' => __('Nothing was written: that event or text was not found.', 'eventcrew')];
        }

        return $this->eventAiText->rewriteJob((int) ($args['event_id'] ?? 0), (string) ($args['key'] ?? ''));
    }

    /**
     * The sentence for EventAiText::rewrite()'s result; $type becomes
     * 'error' unless every text was written.
     *
     * @param array{found: bool, configured: bool, written: int, failed: int} $result
     */
    public static function rewriteNotice(array $result, string &$type = 'success'): string
    {
        $type = $result['found'] && $result['configured'] && 0 === $result['failed'] ? 'success' : 'error';

        if (! $result['found']) {
            return __('Nothing was written: that event or text was not found.', 'eventcrew');
        }

        if (! $result['configured']) {
            return __('Nothing was written: the AI is not set up. Set it up in EventCrew > Settings.', 'eventcrew');
        }

        $parts = [];

        if ($result['written'] > 0) {
            $parts[] = sprintf(
                /* translators: %d: how many texts the AI wrote */
                _n('The AI wrote %d new text.', 'The AI wrote %d new texts.', $result['written'], 'eventcrew'),
                $result['written']
            );
        }

        if ($result['failed'] > 0) {
            $parts[] = sprintf(
                /* translators: %d: how many texts the AI could not write */
                _n(
                    'The AI could not write %d text; it keeps its old text.',
                    'The AI could not write %d texts; they keep their old texts.',
                    $result['failed'],
                    'eventcrew'
                ),
                $result['failed']
            );
        }

        return implode(' ', $parts);
    }

    /** admin-post: one event's own doors time, from its card on the Coming events tab. */
    public function saveDoorsClose(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $post = wp_unslash($_POST);
        $eventId = (int) ($post['event_id'] ?? 0);
        $notice = $this->applyDoorsClose($post);

        self::back(MessagesPage::TAB_EVENTS, $notice, 'success', self::eventAnchor($eventId));
    }

    /**
     * The doors form of one event: event_id and doors_close (HH:MM, blank
     * goes back to the setting). Returns the notice.
     *
     * @param array<string, mixed> $post
     */
    public function applyDoorsClose(array $post): string
    {
        $eventId = (int) ($post['event_id'] ?? 0);

        if ($eventId <= 0 || null === EventSource::describe($eventId)) {
            return __('Nothing was saved: that event was not found.', 'eventcrew');
        }

        $time = EventTimes::saveOwnDoorsClose($eventId, sanitize_text_field((string) ($post['doors_close'] ?? '')));

        if ('' === $time) {
            return __('This event follows the doors setting again.', 'eventcrew');
        }

        /* translators: %s: a time like 19:30 */
        return sprintf(__('The doors of this event close at %s.', 'eventcrew'), $time);
    }

    /** admin-post: one event's own venue, from its card on the Coming events tab. */
    public function saveVenue(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $post = wp_unslash($_POST);
        $eventId = (int) ($post['event_id'] ?? 0);
        $notice = $this->applyVenue($post);

        self::back(MessagesPage::TAB_EVENTS, $notice, 'success', self::eventAnchor($eventId));
    }

    /**
     * The venue form of one event: event_id and venue (blank goes back to
     * the default venue, else EventMesh's). Returns the notice.
     *
     * @param array<string, mixed> $post
     */
    public function applyVenue(array $post): string
    {
        $eventId = (int) ($post['event_id'] ?? 0);

        if ($eventId <= 0 || null === EventSource::describe($eventId)) {
            return __('Nothing was saved: that event was not found.', 'eventcrew');
        }

        $venue = EventVenue::saveOwn($eventId, sanitize_text_field((string) ($post['venue'] ?? '')));

        if ('' === $venue) {
            return __('This event uses the default venue again, else the venue from EventMesh.', 'eventcrew');
        }

        /* translators: %s: the venue, like "Dance hall" */
        return sprintf(__('The venue of this event is now %s.', 'eventcrew'), $venue);
    }

    /** The "Read again" link of event $eventId's venue (VENUE_AGAIN_ACTION), nonce included. */
    public static function venueAgainUrl(int $eventId): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => self::VENUE_AGAIN_ACTION, 'event_id' => $eventId], admin_url('admin-post.php')),
            self::NONCE_ACTION
        );
    }

    /** admin-post (GET): "Read again" on one event's venue, from its card on the Coming events tab. */
    public function readVenueAgain(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in assertCanSave().
        $eventId = (int) ($_GET['event_id'] ?? 0);

        self::back(MessagesPage::TAB_EVENTS, $this->applyVenueAgain($eventId), 'success', self::eventAnchor($eventId));
    }

    /** "Read again" on event $eventId's venue. Returns the notice. */
    public function applyVenueAgain(int $eventId): string
    {
        if ($eventId <= 0 || null === EventSource::describe($eventId)) {
            return __('Nothing was saved: that event was not found.', 'eventcrew');
        }

        if (! VenueReader::readAgain($eventId)) {
            return __(
                'Nothing was changed: this event has a venue set by hand, or the AI venue setting is off.',
                'eventcrew'
            );
        }

        return __('The AI reads the venue from the description again within the hour.', 'eventcrew');
    }

    /** admin-post: the one-time text of the next post-event email, from the Coming events tab. */
    public function saveFollowupText(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $code = $this->applyFollowupText(wp_unslash($_POST), true);

        self::back(
            MessagesPage::TAB_EVENTS,
            self::QUEUED === $code ? BackgroundJobs::queuedNotice() : CrewAdminFollowup::noticeText($code),
            'admin_followup_unknown' === $code ? 'error' : 'success',
            self::ONE_TIME_ANCHOR
        );
    }

    /**
     * The post-event email's one-time text form: variant, op (save or clear)
     * and followup_text, saved the way the crew app saves it. Returns the
     * notice code of CrewAdminFollowup::saveText().
     *
     * With $background, a text to translate is saved by a background job
     * (FOLLOWUP_JOB, followupJob()) and QUEUED is answered; until then the
     * email keeps the text it had.
     *
     * @param array<string, mixed> $post
     */
    public function applyFollowupText(array $post, bool $background = false): string
    {
        if (null === $this->followup) {
            return 'admin_followup_unknown';
        }

        $variant = sanitize_key((string) ($post['variant'] ?? ''));
        $op = sanitize_key((string) ($post['op'] ?? ''));
        $text = sanitize_textarea_field((string) ($post['followup_text'] ?? ''));

        if (
            $background
            && 'save' === $op
            && '' !== trim($text)
            && FollowupText::isVariant($variant)
            && TranslatedText::modelReady()
        ) {
            BackgroundJobs::enqueue(
                self::FOLLOWUP_JOB,
                self::followupTarget($variant),
                ['variant' => $variant, 'text' => $text]
            );

            return self::QUEUED;
        }

        return $this->followup->saveText(0, $variant, $op, $text);
    }

    /**
     * The background job that saves a one-time text of the post-event email.
     *
     * @param array<string, mixed> $args variant, text and by (who saved it, 0 from wp-admin).
     * @return array{ok: bool, error: string}
     */
    public function followupJob(array $args): array
    {
        if (null === $this->followup) {
            return ['ok' => false, 'error' => __('This job can no longer be run.', 'eventcrew')];
        }

        $code = $this->followup->saveText(
            (int) ($args['by'] ?? 0),
            (string) ($args['variant'] ?? ''),
            'save',
            (string) ($args['text'] ?? '')
        );

        return 'admin_followup_saved' === $code
            ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => CrewAdminFollowup::noticeText($code)];
    }

    /** The BackgroundJobs target of the one-time text of $variant. */
    public static function followupTarget(string $variant): string
    {
        return 'followup:' . $variant;
    }

    /** admin-post: the text for the next announcement, from the Coming events tab. */
    public function saveNewsletterText(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $code = $this->applyNewsletterText(wp_unslash($_POST), true);

        self::back(
            MessagesPage::TAB_EVENTS,
            self::QUEUED === $code ? BackgroundJobs::queuedNotice() : CrewAdminNewsletter::noticeText($code),
            'success',
            self::ONE_TIME_ANCHOR
        );
    }

    /**
     * The announcement's text form: op (save or clear) and newsletter_text,
     * saved the way the crew app saves it. Returns the notice code of
     * CrewAdminNewsletter::saveText().
     *
     * With $background, a text to translate is saved by a background job
     * (NEWSLETTER_JOB, newsletterJob()) and QUEUED is answered; until then
     * the announcement keeps the text it had.
     *
     * @param array<string, mixed> $post
     */
    public function applyNewsletterText(array $post, bool $background = false): string
    {
        if (null === $this->newsletter) {
            return '';
        }

        $op = sanitize_key((string) ($post['op'] ?? ''));
        $text = sanitize_textarea_field((string) ($post['newsletter_text'] ?? ''));

        if ($background && 'save' === $op && '' !== trim($text) && TranslatedText::modelReady()) {
            BackgroundJobs::enqueue(self::NEWSLETTER_JOB, self::NEWSLETTER_TARGET, ['text' => $text]);

            return self::QUEUED;
        }

        return $this->newsletter->saveText(0, $op, $text);
    }

    /**
     * The background job that saves the announcement's text.
     *
     * @param array<string, mixed> $args text and by (who saved it, 0 from wp-admin).
     * @return array{ok: bool, error: string}
     */
    public function newsletterJob(array $args): array
    {
        if (null === $this->newsletter) {
            return ['ok' => false, 'error' => __('This job can no longer be run.', 'eventcrew')];
        }

        $code = $this->newsletter->saveText((int) ($args['by'] ?? 0), 'save', (string) ($args['text'] ?? ''));

        return 'admin_newsletter_saved' === $code
            ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => CrewAdminNewsletter::noticeText($code)];
    }

    /**
     * load- hook of the hidden old page (Admin::registerMenus()): the old
     * address of the AI prompts page goes to the AI tab of the Messages page;
     * with an event_id, to that event on the Coming events tab.
     */
    public static function redirectOldPage(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: where to go.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        if (self::PAGE_SLUG !== $page) {
            return;
        }

        $eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        wp_safe_redirect(
            0 === $eventId
                ? MessagesPage::url(MessagesPage::TAB_AI)
                : MessagesPage::url(MessagesPage::TAB_EVENTS, [], self::eventAnchor($eventId))
        );

        exit;
    }

    /** The anchor of $eventId's card on the Coming events tab. */
    public static function eventAnchor(int $eventId): string
    {
        return $eventId > 0 ? 'ec-event-' . $eventId : '';
    }

    /** Back to $tab of the Messages page with $notice. */
    private static function back(string $tab, string $notice, string $type = 'success', string $anchor = ''): never
    {
        Admin::redirectTo(MessagesPage::PAGE_SLUG, $notice, $type, ['tab' => $tab], $anchor);
    }
}
