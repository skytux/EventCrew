<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * One line of the Diagnostics page: a status, a short label and a human
 * sentence saying what was found and, where it matters, what to do about it.
 *
 * A plain value object rather than an array so the template can lean on the
 * property names and the status constants, and so the health checks read as
 * `Diagnostic::ok(...)` at their call sites.
 */
final class Diagnostic
{
    /** A green, all-is-well result. */
    public const OK = 'ok';

    /** A neutral fact - not a problem, just worth showing (e.g. the bot is off). */
    public const INFO = 'info';

    /** Working, but something wants attention before it bites. */
    public const WARN = 'warn';

    /** Broken now - a feature will not work until it is fixed. */
    public const ERROR = 'error';

    public function __construct(
        public readonly string $status,
        public readonly string $label,
        public readonly string $detail
    ) {
    }

    public static function ok(string $label, string $detail): self
    {
        return new self(self::OK, $label, $detail);
    }

    public static function info(string $label, string $detail): self
    {
        return new self(self::INFO, $label, $detail);
    }

    public static function warn(string $label, string $detail): self
    {
        return new self(self::WARN, $label, $detail);
    }

    public static function error(string $label, string $detail): self
    {
        return new self(self::ERROR, $label, $detail);
    }
}
