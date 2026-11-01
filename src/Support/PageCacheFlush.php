<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Empties the site's page cache once after every plugin update.
 *
 * A page cache (W3 Total Cache on the live site) keeps serving pages built by
 * the old version, which still link the old stylesheet, until the cache is
 * emptied - so after a deploy visitors saw the new code with the old look, or
 * the reverse. Comparing a stored version on each request costs one autoloaded
 * option read (the same guard Schema::maybeMigrate uses) and catches every way
 * an update arrives: the updater, an upload, or files swapped over FTP.
 *
 * Only W3 Total Cache is handled, because it is the cache this plugin is run
 * with; on a site without it this only stamps the version.
 */
final class PageCacheFlush
{
    public const OPTION = 'eventcrew_cache_flushed_version';

    public static function boot(): void
    {
        // init, not earlier: W3 Total Cache defines its flush functions while
        // plugins load, and EventCrew may load before it.
        add_action('init', [self::class, 'maybeFlush']);
    }

    public static function maybeFlush(): void
    {
        if (EVENTCREW_VERSION === (string) get_option(self::OPTION, '')) {
            return;
        }

        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        update_option(self::OPTION, EVENTCREW_VERSION);
    }
}
