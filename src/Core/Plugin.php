<?php

declare(strict_types=1);

namespace EventCrew\Core;

final class Plugin
{
    private static ?self $instance = null;

    private Kernel $kernel;

    public static function boot(): void
    {
        if (self::$instance instanceof self) {
            return;
        }

        self::$instance = new self();
    }

    private function __construct()
    {
        $this->kernel = new Kernel();

        add_action(
            'plugins_loaded',
            [$this, 'init']
        );
    }

    public function init(): void
    {
        $this->kernel->boot();
    }
}
