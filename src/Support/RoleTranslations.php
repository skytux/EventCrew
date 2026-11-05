<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The role names and descriptions in every site language, written by the
 * language model in the background, the way the event texts are (Q307).
 *
 * A role saved with a new name on the Crew page, or a role stored before its
 * texts were translated, has only its source text, so the board showed it in
 * English in every language. Each hourly heartbeat (Scheduler::HOOK), and a
 * WP-Cron run asked for as soon as the roles are saved, fills the empty
 * language texts of the roles' Translations page rows
 * (TranslationRegistry::translateRows()). A text edited by hand is never
 * overwritten, and a text that could not be translated stays empty, so
 * readers get the original (TranslatedText::forReader()) and the next run
 * tries again.
 */
final class RoleTranslations
{
    /** The WP-Cron event asked for when the roles are saved. */
    public const HOOK = 'eventcrew_translate_roles';

    /** The rows of the roles (Roles::translationRows()). */
    private const ROW_PREFIX = 'role.';

    public function __construct(
        private readonly TranslationRegistry $registry
    ) {
    }

    public function boot(): void
    {
        add_action(Scheduler::HOOK, [$this, 'run'], 25);
        add_action(self::HOOK, [$this, 'run']);
        add_action('update_option_' . Roles::OPTION_NAME, [$this, 'soon']);
        add_action('add_option_' . Roles::OPTION_NAME, [$this, 'soon']);
    }

    /** Asks WP-Cron for a run, unless one is already waiting. */
    public function soon(): void
    {
        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time(), self::HOOK);
        }
    }

    /**
     * Fills the roles' empty language texts. Does nothing outside a
     * background run, with AI translation off, or with no model set up.
     * Returns the ids of the rows that were stored.
     *
     * @return list<string>
     */
    public function run(): array
    {
        if (! self::inBackground() || ! TranslatedText::autoEnabled() || ! AssistantSettings::isConfigured()) {
            return [];
        }

        $ids = array_values(array_filter(
            $this->registry->missingIds(),
            static fn (string $id): bool => str_starts_with($id, self::ROW_PREFIX)
        ));

        if ([] === $ids) {
            return [];
        }

        return $this->registry->translateRows($ids)['done'];
    }

    /** Whether this run is in the background: WP-Cron or WP-CLI, not a visitor's request. */
    private static function inBackground(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return function_exists('wp_doing_cron') && wp_doing_cron();
    }
}
