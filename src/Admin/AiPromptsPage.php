<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\AiPrompts;
use EventCrew\Support\EventAiText;

/**
 * The AI prompts page (EventCrew > AI prompts), the one place for what the
 * language model is asked:
 * - the built-in prompts (AiPrompts), each with "Reset to built-in";
 * - the organizers' own placeholders (EventAiText::placeholders()), each a
 *   {name} with its prompt and a fallback text for when the AI fails;
 * - the texts the AI wrote for the coming events (EventAiText::overview()),
 *   each with an edit box. A changed text is sent as changed; an empty box
 *   puts the AI's text back.
 */
final class AiPromptsPage
{
    public const PAGE_SLUG = 'eventcrew-ai-prompts';

    public const PROMPTS_ACTION = 'eventcrew_ai_prompts_save';

    public const PLACEHOLDERS_ACTION = 'eventcrew_ai_placeholders_save';

    public const EVENT_TEXT_ACTION = 'eventcrew_ai_event_text_save';

    public const NONCE_ACTION = 'eventcrew_ai_prompts';

    public function __construct(private readonly View $view)
    {
    }

    public function render(): void
    {
        $this->view->render('ai-prompts', $this->data());
    }

    /**
     * What the page shows.
     *
     * @return array<string, mixed>
     */
    public function data(): array
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
            'events' => EventAiText::overview(),
            'templates' => EventAiText::activeTemplates(),
        ];
    }

    /** Saves the prompts, or resets the one whose "Reset to built-in" was pressed. */
    public function savePrompts(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $result = $this->applyPrompts(wp_unslash($_POST));

        Admin::redirectTo(self::PAGE_SLUG, $result);
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        Admin::redirectTo(self::PAGE_SLUG, $this->applyPlaceholders(wp_unslash($_POST)));
    }

    /**
     * The placeholders form. A row with an empty name is removed. Returns the
     * notice.
     *
     * @param array<string, mixed> $post
     */
    public function applyPlaceholders(array $post): string
    {
        $rows = [];

        foreach (is_array($post['placeholders'] ?? null) ? $post['placeholders'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                'name' => sanitize_text_field((string) ($row['name'] ?? '')),
                'prompt' => sanitize_textarea_field((string) ($row['prompt'] ?? '')),
                'fallback' => sanitize_textarea_field((string) ($row['fallback'] ?? '')),
            ];
        }

        $given = count(array_filter($rows, static fn (array $row): bool => '' !== trim($row['name'])));
        $kept = count(EventAiText::savePlaceholders($rows));

        return $kept < $given
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? __('Saved. Some placeholders were left out: a name uses only small letters a-z, digits and _, starts with a letter, is used once, and has a prompt.', 'eventcrew')
            : __('The placeholders are saved.', 'eventcrew');
    }

    public function saveEventText(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        Admin::redirectTo(self::PAGE_SLUG, self::noticeText($this->applyEventText(wp_unslash($_POST))));
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
}
