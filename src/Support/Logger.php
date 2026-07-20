<?php

declare(strict_types=1);

namespace EventCrew\Support;

final class Logger
{
    private const OPTION_NAME = 'eventcrew_recent_logs';
    private const MAX_ENTRIES = 20;

    public function info(string $message): void
    {
        $this->log('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->log('WARNING', $message);
    }

    public function error(string $message): void
    {
        $this->log('ERROR', $message);
    }

    /**
     * @return array<int, array{level: string, message: string, timestamp: int}>
     */
    public function recent(): array
    {
        $logs = get_option(self::OPTION_NAME, []);

        if (! is_array($logs)) {
            return [];
        }

        $entries = [];

        foreach ($logs as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entries[] = [
                'level' => (string) ($entry['level'] ?? 'INFO'),
                'message' => (string) ($entry['message'] ?? ''),
                'timestamp' => (int) ($entry['timestamp'] ?? 0),
            ];
        }

        return array_slice($entries, -self::MAX_ENTRIES);
    }

    private function log(string $level, string $message): void
    {
        error_log(
            sprintf(
                '[EventCrew] [%s] %s',
                $level,
                $message
            )
        );

        $logs = $this->recent();
        $logs[] = [
            'level' => $level,
            'message' => $message,
            'timestamp' => time(),
        ];

        $trimmed = array_slice($logs, -self::MAX_ENTRIES);
        update_option(self::OPTION_NAME, $trimmed);
    }
}
