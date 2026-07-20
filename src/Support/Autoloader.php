<?php

declare(strict_types=1);

namespace EventCrew\Support;

final class Autoloader
{
    private const PREFIX = 'EventCrew\\';

    public static function register(): void
    {
        spl_autoload_register(
            [self::class, 'autoload']
        );
    }

    private static function autoload(string $class): void
    {
        if (! str_starts_with($class, self::PREFIX)) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));

        $file = EVENTCREW_PLUGIN_DIR .
            'src/' .
            str_replace('\\', DIRECTORY_SEPARATOR, $relative) .
            '.php';

        if (is_readable($file)) {
            require $file;
        }
    }
}
