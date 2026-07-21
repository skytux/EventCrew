<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (! defined('EVENTCREW_PLUGIN_DIR')) {
    define('EVENTCREW_PLUGIN_DIR', dirname(__DIR__) . '/');
}

// Every template in this plugin opens with the standard
// `if (! defined('ABSPATH')) { exit; }` guard against direct browser access.
// Without a real WordPress bootstrap that `exit` would silently kill the whole
// PHPUnit process the moment a test includes one of them.
if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (! defined('EVENTCREW_VERSION')) {
    define('EVENTCREW_VERSION', '0.0.0-test');
}

if (! defined('EVENTCREW_PLUGIN_URL')) {
    define('EVENTCREW_PLUGIN_URL', 'https://example.test/wp-content/plugins/eventcrew/');
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

/**
 * Minimal stand-ins for the two REST classes the webhook and verification
 * endpoints are typed against. WordPress is not loaded here, so the real ones
 * are absent; these cover only the handful of methods the controllers call.
 */
if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, string> */
        public array $headers = [];

        /** @var array<string, mixed>|null */
        public ?array $body_params = null;

        /** @var array<string, mixed> */
        public array $params = [];

        public string $method = 'GET';

        public function get_method(): string
        {
            return $this->method;
        }

        public function set_header(string $name, string $value): void
        {
            $this->headers[$this->key($name)] = $value;
        }

        public function get_header(string $name): string
        {
            return $this->headers[$this->key($name)] ?? '';
        }

        /**
         * @return array<string, mixed>|null
         */
        public function get_json_params(): ?array
        {
            return $this->body_params;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        private function key(string $name): string
        {
            return strtolower(str_replace('-', '_', $name));
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public mixed $data = null, public int $status = 200)
        {
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

/**
 * Programmable stand-in for $wpdb.
 *
 * Records every statement it is handed and returns queued results, so the
 * decision logic in the repositories - especially which branch a conditional
 * INSERT falls down - can be tested without a MySQL server. It is not a query
 * engine: it never interprets the SQL, only reports it back for assertions.
 */
class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public int $insert_id = 0;

    /** @var array<int, string> */
    public array $queries = [];

    /** @var array<int, array{table: string, data: array<string, mixed>}> */
    public array $inserts = [];

    /** @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}> */
    public array $updates = [];

    /** @var array<int, array{table: string, where: array<string, mixed>}> */
    public array $deletes = [];

    /** Values returned, in order, by successive get_row() calls. */
    public array $nextRows = [];

    /** Values returned, in order, by successive get_results() calls. */
    public array $nextResults = [];

    /** Values returned, in order, by successive get_var() calls. */
    public array $nextVars = [];

    /** Values returned, in order, by successive get_col() calls. */
    public array $nextCols = [];

    /** Values returned, in order, by successive query() calls. */
    public array $nextQueryResults = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        // Flatten the single-array call style wpdb also accepts.
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }

        foreach ($args as $arg) {
            $replacement = is_int($arg) || is_float($arg)
                ? (string) $arg
                : "'" . addslashes((string) $arg) . "'";

            $query = preg_replace('/%[dsf]/', $replacement, $query, 1) ?? $query;
        }

        return $query;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;

        return array_shift($this->nextQueryResults) ?? 0;
    }

    public function get_row(string $sql, string $output = 'OBJECT'): mixed
    {
        $this->queries[] = $sql;

        return array_shift($this->nextRows);
    }

    public function get_results(string $sql, string $output = 'OBJECT'): mixed
    {
        $this->queries[] = $sql;

        return array_shift($this->nextResults) ?? [];
    }

    /**
     * Returns null rather than a zero default when nothing is queued, because
     * that is what the real wpdb does for a query matching no rows - and code
     * that distinguishes "no such record" from "a count of zero" would
     * otherwise be untestable.
     */
    public function get_var(string $sql): mixed
    {
        $this->queries[] = $sql;

        return array_shift($this->nextVars);
    }

    public function get_col(string $sql): mixed
    {
        $this->queries[] = $sql;

        return array_shift($this->nextCols) ?? [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id = count($this->inserts);

        return 1;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];

        return 1;
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $this->deletes[] = ['table' => $table, 'where' => $where];

        return 1;
    }

    /**
     * The most recent statement, for tests asserting on query shape.
     */
    public function lastQuery(): string
    {
        return $this->queries[array_key_last($this->queries)] ?? '';
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();
