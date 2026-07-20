<?php

/**
 * EventCrew install verification.
 *
 * The unit suite fakes $wpdb, so it proves decision logic and query shape but
 * never SQL semantics. This script closes that gap: it runs every statement the
 * plugin actually issues against the real database on the real host, then
 * removes everything it created.
 *
 * Run it under WP-CLI, from the WordPress root:
 *
 *     wp eval-file wp-content/plugins/eventcrew/tools/verify-install.php
 *
 * Without WP-CLI, copy this file to the WordPress root, load it in a browser
 * while signed in as an administrator, and delete it afterwards. It refuses to
 * run for anyone without `manage_options`.
 *
 * Everything it writes is tagged with a run-specific marker and deleted in the
 * teardown phase, which then asserts the row counts are back where they started.
 * It never touches a row it did not create.
 *
 * @package EventCrew
 */

declare(strict_types=1);

use EventCrew\Database\Schema;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Roles;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if (! defined('ABSPATH')) {
    $root = __DIR__;

    // Walk up looking for wp-load.php, which covers both "copied to the
    // WordPress root" and "left in the plugin directory".
    for ($i = 0; $i < 8; $i++) {
        if (is_readable($root . '/wp-load.php')) {
            require_once $root . '/wp-load.php';
            break;
        }

        $root = dirname($root);
    }
}

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Could not locate wp-load.php. Run this from inside a WordPress install.\n");
    exit(1);
}

$isCli = ('cli' === PHP_SAPI);

if (! $isCli && ! current_user_can('manage_options')) {
    wp_die('EventCrew verification requires an administrator.');
}

if (! $isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

if (! class_exists(Schema::class)) {
    echo "FAIL: EventCrew classes are not loaded. Is the plugin activated?\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Worker mode - one concurrent join, spawned by the concurrency phase below
// ---------------------------------------------------------------------------

$workerJob = (string) getenv('EVENTCREW_VERIFY_JOIN');

if ('' !== $workerJob) {
    [$workerTaskId, $workerPersonId] = array_map('intval', explode(':', $workerJob) + [0, 0]);

    echo (new AssignmentRepository())->join($workerTaskId, $workerPersonId), "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

$results = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$failures = [];

$section = static function (string $title): void {
    echo "\n", $title, "\n", str_repeat('-', strlen($title)), "\n";
};

$check = static function (string $label, bool $ok, string $detail = '') use (&$results, &$failures): bool {
    if ($ok) {
        $results['pass']++;
        echo '  PASS  ', $label, "\n";

        return true;
    }

    $results['fail']++;
    $failures[] = $label . ('' === $detail ? '' : ' — ' . $detail);
    echo '  FAIL  ', $label, ('' === $detail ? '' : ' — ' . $detail), "\n";

    return false;
};

$skip = static function (string $label, string $why) use (&$results): void {
    $results['skip']++;
    echo '  SKIP  ', $label, ' — ', $why, "\n";
};

global $wpdb;

$wpdb->suppress_errors(true);

/** Runs a callable and reports whether MySQL raised an error doing it. */
$sqlRuns = static function (string $label, callable $fn) use ($check, $wpdb): mixed {
    $wpdb->last_error = '';

    try {
        $value = $fn();
    } catch (\Throwable $e) {
        $check($label, false, get_class($e) . ': ' . $e->getMessage());

        return null;
    }

    $check($label, '' === $wpdb->last_error, $wpdb->last_error);

    return $value;
};

echo "EventCrew install verification\n";
echo 'WordPress ', get_bloginfo('version'), ' | PHP ', PHP_VERSION, ' | ', $wpdb->db_version(), "\n";
echo 'Table prefix: ', $wpdb->prefix, "\n";

// ---------------------------------------------------------------------------
// Phase 1 - migration ran
// ---------------------------------------------------------------------------

$section('1. Migration');

$storedVersion = (string) get_option(Schema::VERSION_OPTION, '');

$check(
    'DB version option matches Schema::DB_VERSION',
    Schema::DB_VERSION === $storedVersion,
    sprintf('stored "%s", expected "%s"', $storedVersion, Schema::DB_VERSION)
);

$existingTables = [];

foreach (Schema::tableNames() as $name) {
    $table = Schema::table($name);
    $found = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    if ($check(sprintf('Table %s exists', $table), $table === $found, 'dbDelta did not create it')) {
        $existingTables[] = $table;
    }
}

// ---------------------------------------------------------------------------
// Phase 2 - the tables match what Schema declares
//
// Expectations are read out of Schema::statements() by reflection rather than
// restated here, so this phase cannot drift from the source of truth.
// ---------------------------------------------------------------------------

$section('2. Schema shape');

$statementsMethod = new ReflectionMethod(Schema::class, 'statements');
$statementsMethod->setAccessible(true);

/** @var array<int, string> $statements */
$statements = $statementsMethod->invoke(null, '');

/**
 * Pulls the declared columns and keys out of one CREATE TABLE string.
 *
 * @return array{table: string, columns: array<int, string>, keys: array<string, array{unique: bool, columns: array<int, string>}>}
 */
$parseCreate = static function (string $sql): array {
    $table = '';

    if (1 === preg_match('/CREATE TABLE\s+(\S+)\s*\(/i', $sql, $m)) {
        $table = $m[1];
    }

    $body = substr($sql, (int) strpos($sql, '(') + 1);
    $body = substr($body, 0, (int) strrpos($body, ')'));

    $columns = [];
    $keys = [];

    foreach (explode("\n", $body) as $line) {
        $line = trim(rtrim(trim($line), ','));

        if ('' === $line) {
            continue;
        }

        if (1 === preg_match('/^PRIMARY KEY\s+\(([^)]+)\)/i', $line, $m)) {
            $keys['PRIMARY'] = ['unique' => true, 'columns' => array_map('trim', explode(',', $m[1]))];
            continue;
        }

        if (1 === preg_match('/^(UNIQUE\s+)?KEY\s+(\w+)\s*\(([^)]+)\)/i', $line, $m)) {
            $keys[$m[2]] = [
                'unique' => '' !== trim($m[1]),
                'columns' => array_map('trim', explode(',', $m[3])),
            ];
            continue;
        }

        if (1 === preg_match('/^(\w+)\s+/', $line, $m)) {
            $columns[] = $m[1];
        }
    }

    return ['table' => $table, 'columns' => $columns, 'keys' => $keys];
};

foreach ($statements as $sql) {
    $expected = $parseCreate($sql);
    $table = $expected['table'];

    if (! in_array($table, $existingTables, true)) {
        $skip(sprintf('Columns and keys of %s', $table), 'table missing');
        continue;
    }

    $actualColumns = [];

    foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A) as $row) {
        $actualColumns[] = (string) $row['Field'];
    }

    $missingColumns = array_diff($expected['columns'], $actualColumns);

    $check(
        sprintf('%s has every declared column', $table),
        [] === $missingColumns,
        'missing: ' . implode(', ', $missingColumns)
    );

    $actualKeys = [];

    foreach ((array) $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A) as $row) {
        $keyName = (string) $row['Key_name'];
        $actualKeys[$keyName]['unique'] = ('0' === (string) $row['Non_unique']);
        $actualKeys[$keyName]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
    }

    foreach ($expected['keys'] as $keyName => $key) {
        if (! isset($actualKeys[$keyName])) {
            $check(sprintf('%s.%s exists', $table, $keyName), false, 'index missing');
            continue;
        }

        ksort($actualKeys[$keyName]['columns']);
        $actual = array_values($actualKeys[$keyName]['columns']);

        $check(
            sprintf('%s.%s matches (%s)', $table, $keyName, implode(',', $key['columns'])),
            $actual === $key['columns'] && $actualKeys[$keyName]['unique'] === $key['unique'],
            sprintf(
                'found (%s)%s',
                implode(',', $actual),
                $actualKeys[$keyName]['unique'] === $key['unique'] ? '' : ', uniqueness differs'
            )
        );
    }
}

// ---------------------------------------------------------------------------
// Phase 3 - host assumptions the schema depends on
// ---------------------------------------------------------------------------

$section('3. Host assumptions');

$peopleTable = Schema::table(Schema::PEOPLE);
$assignmentsTable = Schema::table(Schema::ASSIGNMENTS);

if (in_array($assignmentsTable, $existingTables, true)) {
    $engine = (string) $wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $assignmentsTable
    ));

    // Not for the capacity guard's sake - MyISAM's table-level write lock
    // serialises the conditional insert perfectly well. InnoDB is required
    // because MyISAM has no crash recovery for signup history and no
    // transaction to make v0.5's redemption atomic. The first install came up
    // MyISAM, so Schema::migrate() now converts as well as declares.
    $check(
        'Assignments table is InnoDB',
        'InnoDB' === $engine,
        'engine is ' . $engine . ' — re-run the migration, or ALTER TABLE ... ENGINE=InnoDB by hand'
    );
}

if (in_array(Schema::table(Schema::TASKS), $existingTables, true)) {
    $taskTable = Schema::table(Schema::TASKS);

    $columnTypes = [];

    foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM `{$taskTable}`", ARRAY_A) as $row) {
        $columnTypes[(string) $row['Field']] = strtolower((string) $row['Type']);
    }

    // A task that runs past midnight cannot be expressed as a bare time, and
    // the clean-up after every evening event does exactly that.
    $check(
        'tasks.starts_at is a datetime',
        'datetime' === ($columnTypes['starts_at'] ?? ''),
        'type is ' . ($columnTypes['starts_at'] ?? 'missing')
    );

    $check(
        'tasks.ends_at is a datetime',
        'datetime' === ($columnTypes['ends_at'] ?? ''),
        'type is ' . ($columnTypes['ends_at'] ?? 'missing')
    );

    // Deliberately still a DATE. It is the day the task is filed under, which
    // is not the day it starts.
    $check(
        'tasks.task_date is still a date',
        'date' === ($columnTypes['task_date'] ?? ''),
        'type is ' . ($columnTypes['task_date'] ?? 'missing')
    );
}

if (in_array($peopleTable, $existingTables, true)) {
    $emailLength = $wpdb->get_var($wpdb->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $peopleTable,
        'email'
    ));

    // 191 is the utf8mb4 index-length ceiling on MySQL 5.7; if dbDelta widened
    // or the host silently truncated, the unique index is the thing that breaks.
    $check('people.email is varchar(191)', 191 === (int) $emailLength, 'length is ' . var_export($emailLength, true));
}

$check(
    'Roles are readable and non-empty',
    [] !== Roles::all(),
    'Roles::all() returned nothing'
);

$check(
    'Occupying statuses are defined',
    [] !== AssignmentStatus::occupying(),
    'AssignmentStatus::occupying() returned nothing'
);

// ---------------------------------------------------------------------------
// Phase 4 - live SQL, on real data this script creates and then removes
// ---------------------------------------------------------------------------

$section('4. Live SQL');

$marker = 'verify-' . wp_generate_password(8, false, false);
$people = new PersonRepository();
$tasks = new TaskRepository();
$assignments = new AssignmentRepository();

$baselinePeople = $people->count();
$baselineTasks = $tasks->count();

$createdPeople = [];
$createdTasks = [];

$testDate = gmdate('Y-m-d', time() + (60 * 60 * 24 * 400));

$roles = Roles::all();
$roleSlug = (string) $roles[0]['slug'];

// Three people, one task with capacity 2 - the smallest arrangement that can
// show the capacity guard both admitting and refusing.
for ($i = 1; $i <= 3; $i++) {
    $id = $sqlRuns(
        sprintf('PersonRepository::create (%d of 3)', $i),
        static fn (): int => $people->create([
            'email' => sprintf('%s+%d@eventcrew.invalid', $marker, $i),
            'display_name' => 'Verification ' . $i,
            'notes' => $marker,
        ])
    );

    if (is_int($id) && $id > 0) {
        $createdPeople[] = $id;
    }
}

$check('Three people were created', 3 === count($createdPeople), count($createdPeople) . ' created');

$taskId = $sqlRuns(
    'TaskRepository::create',
    static fn (): int => $tasks->create([
        'event_label' => $marker,
        'task_date' => $testDate,
        'starts_at' => $testDate . ' 18:00:00',
        'ends_at' => $testDate . ' 20:00:00',
        'role_slug' => $roleSlug,
        'capacity' => 2,
        'notes' => $marker,
    ])
);

if (is_int($taskId) && $taskId > 0) {
    $createdTasks[] = $taskId;
}

// A second, overlapping task on the same date, so hasOverlapping() has
// something real to find rather than being exercised against an empty set.
$overlappingTaskId = $sqlRuns(
    'TaskRepository::create (overlapping)',
    static fn (): int => $tasks->create([
        'event_label' => $marker,
        'task_date' => $testDate,
        'starts_at' => $testDate . ' 19:00:00',
        'ends_at' => $testDate . ' 21:00:00',
        'role_slug' => $roleSlug,
        'capacity' => 1,
        'notes' => $marker,
    ])
);

if (is_int($overlappingTaskId) && $overlappingTaskId > 0) {
    $createdTasks[] = $overlappingTaskId;
}

if (3 !== count($createdPeople) || ! is_int($taskId) || $taskId <= 0) {
    echo "\nAborting: could not create the fixtures the remaining checks need.\n";
} else {
    // -- the capacity guard, sequentially ------------------------------------
    //
    // This does not prove the statement is race-proof; it proves the statement
    // is valid on this MySQL and enforces the limit at all. The derived-table
    // wrapper in AssignmentRepository::join() exists to satisfy MySQL's refusal
    // to read the insert target in an uncorrelated subquery, and that is
    // exactly the kind of thing a faked $wpdb cannot check.

    $first = $sqlRuns('join() #1 of 3', static fn (): string => $assignments->join($taskId, $createdPeople[0]));
    $check('First join succeeded', AssignmentRepository::JOIN_OK === $first, 'returned ' . var_export($first, true));

    $second = $sqlRuns('join() #2 of 3', static fn (): string => $assignments->join($taskId, $createdPeople[1]));
    $check('Second join succeeded', AssignmentRepository::JOIN_OK === $second, 'returned ' . var_export($second, true));

    $third = $sqlRuns('join() #3 of 3', static fn (): string => $assignments->join($taskId, $createdPeople[2]));
    $check(
        'Third join was refused (capacity 2)',
        AssignmentRepository::JOIN_FULL === $third,
        'returned ' . var_export($third, true) . ' — the capacity guard did not hold'
    );

    $duplicate = $sqlRuns('join() duplicate', static fn (): string => $assignments->join($taskId, $createdPeople[0]));
    $check(
        'Re-joining reports already_joined',
        AssignmentRepository::JOIN_DUPLICATE === $duplicate,
        'returned ' . var_export($duplicate, true)
    );

    $unknown = $sqlRuns('join() unknown task', static fn (): string => $assignments->join(0, $createdPeople[0]));
    $check(
        'Joining a missing task reports unknown_task',
        AssignmentRepository::JOIN_UNKNOWN_TASK === $unknown,
        'returned ' . var_export($unknown, true)
    );

    // -- occupancy and the reads built on it ---------------------------------

    $occupancy = $sqlRuns('TaskRepository::occupancyFor', static fn (): array => $tasks->occupancyFor([$taskId]));
    $check(
        'Occupancy reports 2 taken',
        2 === ($occupancy[$taskId] ?? -1),
        'reported ' . var_export($occupancy[$taskId] ?? null, true)
    );

    // The overlapping task is empty and has capacity 1, so the date as a whole
    // still has an open slot even though this task is full.
    $openOnDate = $sqlRuns(
        'TaskRepository::hasOpenSlotsOn (date with a free slot)',
        static fn (): bool => $tasks->hasOpenSlotsOn($testDate)
    );
    $check('A date with a free slot reports open', true === $openOnDate, 'returned ' . var_export($openOnDate, true));

    // A date with no tasks at all must report closed, not open - this is what
    // stops the 48h open-task call mailing people about nothing.
    $emptyDate = gmdate('Y-m-d', time() + (60 * 60 * 24 * 401));
    $openOnEmpty = $sqlRuns(
        'TaskRepository::hasOpenSlotsOn (date with no tasks)',
        static fn (): bool => $tasks->hasOpenSlotsOn($emptyDate)
    );
    $check('A date with no tasks reports closed', false === $openOnEmpty, 'returned ' . var_export($openOnEmpty, true));

    $assignedIds = $sqlRuns(
        'AssignmentRepository::personIdsAssignedOn',
        static fn (): array => $assignments->personIdsAssignedOn($testDate)
    );
    $check(
        'Assigned-on-date returns both joiners',
        is_array($assignedIds)
            && in_array($createdPeople[0], $assignedIds, true)
            && in_array($createdPeople[1], $assignedIds, true),
        'returned ' . implode(',', is_array($assignedIds) ? $assignedIds : [])
    );

    // -- overlap detection ---------------------------------------------------

    if (is_int($overlappingTaskId) && $overlappingTaskId > 0) {
        $overlaps = $sqlRuns(
            'AssignmentRepository::hasOverlapping',
            static fn (): bool => $assignments->hasOverlapping($createdPeople[0], $overlappingTaskId)
        );
        $check('Overlapping task is detected', true === $overlaps, 'returned ' . var_export($overlaps, true));

        $noOverlap = $sqlRuns(
            'AssignmentRepository::hasOverlapping (unassigned person)',
            static fn (): bool => $assignments->hasOverlapping($createdPeople[2], $overlappingTaskId)
        );
        $check('Unassigned person does not overlap', false === $noOverlap, 'returned ' . var_export($noOverlap, true));

        // The case bare times could not express. A task filed under one day
        // but running into the small hours of the next must be seen to clash
        // with an early task on that next day - the overlap query no longer
        // requires the two task_dates to match, and this is what proves the
        // datetime comparison actually does the work instead.
        $nextDay = gmdate('Y-m-d', strtotime($testDate . ' +1 day') ?: time());

        $lateTaskId = $sqlRuns(
            'TaskRepository::create (crossing midnight)',
            static fn (): int => $tasks->create([
                'event_label' => $marker,
                'task_date' => $testDate,
                'starts_at' => $testDate . ' 23:00:00',
                'ends_at' => $nextDay . ' 01:00:00',
                'role_slug' => $roleSlug,
                'capacity' => 1,
                'notes' => $marker,
            ])
        );

        $earlyTaskId = $sqlRuns(
            'TaskRepository::create (next morning)',
            static fn (): int => $tasks->create([
                'event_label' => $marker,
                'task_date' => $nextDay,
                'starts_at' => $nextDay . ' 00:30:00',
                'ends_at' => $nextDay . ' 02:00:00',
                'role_slug' => $roleSlug,
                'capacity' => 1,
                'notes' => $marker,
            ])
        );

        if (is_int($lateTaskId) && $lateTaskId > 0 && is_int($earlyTaskId) && $earlyTaskId > 0) {
            $createdTasks[] = $lateTaskId;
            $createdTasks[] = $earlyTaskId;

            $assignments->join($lateTaskId, $createdPeople[2]);

            $crossesMidnight = $sqlRuns(
                'AssignmentRepository::hasOverlapping (across midnight, different task_date)',
                static fn (): bool => $assignments->hasOverlapping($createdPeople[2], $earlyTaskId)
            );

            $check(
                'A task running past midnight clashes with one the next morning',
                true === $crossesMidnight,
                'returned ' . var_export($crossesMidnight, true) . ' — the overlap query is still date-bound'
            );
        }
    }

    // -- status transitions and the remaining reads --------------------------

    $assignment = $sqlRuns(
        'AssignmentRepository::findFor',
        static fn () => $assignments->findFor($taskId, $createdPeople[0])
    );

    if (null !== $assignment) {
        $sqlRuns(
            'AssignmentRepository::setStatus',
            static function () use ($assignments, $assignment): bool {
                $assignments->setStatus($assignment->id, AssignmentStatus::COMPLETED);

                return true;
            }
        );

        $completed = $sqlRuns(
            'AssignmentRepository::countCompletedFor',
            static fn (): int => $assignments->countCompletedFor($createdPeople[0])
        );
        $check('Completed count is 1', 1 === $completed, 'counted ' . var_export($completed, true));

        $sqlRuns(
            'AssignmentRepository::markReminded',
            static function () use ($assignments, $assignment): bool {
                $assignments->markReminded($assignment->id);

                return true;
            }
        );

        $sqlRuns('AssignmentRepository::find', static fn () => $assignments->find($assignment->id));
    }

    $history = $sqlRuns(
        'AssignmentRepository::historyFor',
        static fn (): array => $assignments->historyFor($createdPeople[0])
    );
    $check(
        'History carries the task date',
        is_array($history) && isset($history[0]['task_date']) && $testDate === $history[0]['task_date'],
        'got ' . var_export($history[0]['task_date'] ?? null, true)
    );

    $sqlRuns('AssignmentRepository::forTask', static fn (): array => $assignments->forTask($taskId));
    $sqlRuns('AssignmentRepository::forPerson', static fn (): array => $assignments->forPerson($createdPeople[0]));

    // -- list-table queries, including the whitelisted ORDER BY --------------

    $sqlRuns('TaskRepository::forDate', static fn (): array => $tasks->forDate($testDate));
    $sqlRuns('TaskRepository::upcoming', static fn (): array => $tasks->upcoming(10));
    $sqlRuns('TaskRepository::upcomingDates', static fn (): array => $tasks->upcomingDates(10));
    $sqlRuns('TaskRepository::all (default order)', static fn (): array => $tasks->all(['per_page' => 10]));
    $sqlRuns(
        'TaskRepository::all (upcoming, sorted)',
        static fn (): array => $tasks->all(['upcoming_only' => true, 'orderby' => 'role_slug', 'order' => 'ASC', 'per_page' => 10])
    );
    $sqlRuns('TaskRepository::count', static fn (): int => $tasks->count());
    $sqlRuns('TaskRepository::count (upcoming)', static fn (): int => $tasks->count(true));

    $sqlRuns('PersonRepository::all', static fn (): array => $people->all(['per_page' => 10]));
    $sqlRuns(
        'PersonRepository::all (search + sort)',
        static fn (): array => $people->all(['search' => $marker, 'orderby' => 'email_opt_in_at', 'order' => 'DESC'])
    );
    $sqlRuns('PersonRepository::count', static fn (): int => $people->count());
    $sqlRuns('PersonRepository::count (search)', static fn (): int => $people->count($marker));
    $sqlRuns('PersonRepository::findByEmail', static fn () => $people->findByEmail(sprintf('%s+1@eventcrew.invalid', $marker)));
    $sqlRuns('PersonRepository::optInStats', static fn (): array => $people->optInStats());

    // Case-insensitive lookup is a decision, not an accident - two rows
    // differing only in case would split one person's credit history.
    $upperCase = $sqlRuns(
        'PersonRepository::findByEmail (uppercased)',
        static fn () => $people->findByEmail(strtoupper(sprintf('%s+1@eventcrew.invalid', $marker)))
    );
    $check('Email lookup is case-insensitive', null !== $upperCase, 'uppercased address did not find the row');

    $sqlRuns(
        'PersonRepository::recordEmailOptIn',
        static function () use ($people, $createdPeople): bool {
            $people->recordEmailOptIn($createdPeople[0], 'verification');

            return true;
        }
    );

    $sqlRuns(
        'PersonRepository::markEmailVerified',
        static function () use ($people, $createdPeople): bool {
            $people->markEmailVerified($createdPeople[0]);

            return true;
        }
    );

    $sqlRuns(
        'PersonRepository::withdrawEmailOptIn',
        static function () use ($people, $createdPeople): bool {
            $people->withdrawEmailOptIn($createdPeople[0]);

            return true;
        }
    );

    $sqlRuns(
        'PersonRepository::update',
        static function () use ($people, $createdPeople): bool {
            $people->update($createdPeople[1], ['display_name' => 'Verification 2 updated']);

            return true;
        }
    );

    // The unique index on telegram_user_id is nullable on purpose: most people
    // have no Telegram link, and MySQL permits any number of NULLs in a unique
    // index. Three people saved above with the column left NULL is the proof -
    // on a host that disagreed, the second create would have failed.
    $nullTelegram = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$peopleTable} WHERE notes = %s AND telegram_user_id IS NULL",
        $marker
    ));
    $check(
        'The unique telegram_user_id index permits repeated NULLs',
        3 === $nullTelegram,
        sprintf('%d of 3 rows kept a NULL telegram id', $nullTelegram)
    );

    $telegramLink = $sqlRuns(
        'PersonRepository::findByTelegramUserId',
        static fn () => $people->findByTelegramUserId(PHP_INT_MAX)
    );
    $check('Unknown Telegram id returns null', null === $telegramLink, 'returned a row');

    // -- leave ---------------------------------------------------------------

    $left = $sqlRuns(
        'AssignmentRepository::leave',
        static fn (): bool => $assignments->leave($taskId, $createdPeople[1])
    );
    $check('Leaving frees the slot', true === $left, 'returned ' . var_export($left, true));

    $afterLeave = $sqlRuns('Occupancy after leave', static fn (): array => $tasks->occupancyFor([$taskId]));
    $check(
        'Occupancy dropped to 1',
        1 === ($afterLeave[$taskId] ?? -1),
        'reported ' . var_export($afterLeave[$taskId] ?? null, true)
    );

    $rejoin = $sqlRuns('join() into the freed slot', static fn (): string => $assignments->join($taskId, $createdPeople[2]));
    $check(
        'The freed slot can be claimed',
        AssignmentRepository::JOIN_OK === $rejoin,
        'returned ' . var_export($rejoin, true)
    );
}

// ---------------------------------------------------------------------------
// Phase 5 - genuine concurrency
//
// This is the one failure the group surface makes likely and that clicking
// around will never reproduce: N processes evaluating capacity at the same
// instant. It needs WP-CLI, because without it there is no way to get a second
// PHP process running the same code at the same time - the bot's HTTP endpoint,
// which would give another route, does not exist until v0.3's second half.
// ---------------------------------------------------------------------------

$section('5. Concurrency');

$wpCli = defined('WP_CLI') && WP_CLI;

if (! $isCli || ! $wpCli) {
    $skip('Simultaneous joins at one task', 'requires WP-CLI (wp eval-file)');
} elseif (! function_exists('proc_open')) {
    $skip('Simultaneous joins at one task', 'proc_open is disabled on this host');
} else {
    $racers = 6;
    $capacity = 2;

    $raceTaskId = $tasks->create([
        'event_label' => $marker,
        'task_date' => $testDate,
        'role_slug' => $roleSlug,
        'capacity' => $capacity,
        'notes' => $marker,
    ]);

    if ($raceTaskId > 0) {
        $createdTasks[] = $raceTaskId;
    }

    $racerIds = [];

    for ($i = 1; $i <= $racers; $i++) {
        $id = $people->create([
            'email' => sprintf('%s+race%d@eventcrew.invalid', $marker, $i),
            'display_name' => 'Race ' . $i,
            'notes' => $marker,
        ]);

        if ($id > 0) {
            $createdPeople[] = $id;
            $racerIds[] = $id;
        }
    }

    if ($raceTaskId <= 0 || count($racerIds) !== $racers) {
        $skip('Simultaneous joins at one task', 'could not create the racers');
    } else {
        $processes = [];
        $script = __FILE__;

        // Started back to back and only then waited on, so all six are inside
        // the conditional insert together rather than politely queued.
        foreach ($racerIds as $racerId) {
            $env = array_merge(getenv(), ['EVENTCREW_VERIFY_JOIN' => $raceTaskId . ':' . $racerId]);

            $process = proc_open(
                sprintf('wp eval-file %s --path=%s', escapeshellarg($script), escapeshellarg(ABSPATH)),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                ABSPATH,
                $env
            );

            if (is_resource($process)) {
                $processes[] = ['process' => $process, 'pipes' => $pipes];
            }
        }

        $outcomes = [];

        foreach ($processes as $entry) {
            $outcomes[] = trim((string) stream_get_contents($entry['pipes'][1]));
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            proc_close($entry['process']);
        }

        if (count($processes) !== $racers) {
            $skip('Simultaneous joins at one task', 'could not spawn WP-CLI (is `wp` on PATH?)');
        } else {
            $finalOccupancy = $tasks->occupancyFor([$raceTaskId])[$raceTaskId] ?? -1;

            $check(
                sprintf('%d simultaneous joins left exactly %d assignments', $racers, $capacity),
                $capacity === $finalOccupancy,
                sprintf('found %d — the task was overbooked; outcomes: %s', $finalOccupancy, implode(', ', $outcomes))
            );

            $joined = count(array_filter(
                $outcomes,
                static fn (string $o): bool => AssignmentRepository::JOIN_OK === $o
            ));

            $check(
                sprintf('Exactly %d processes reported success', $capacity),
                $capacity === $joined,
                sprintf('%d reported success; outcomes: %s', $joined, implode(', ', $outcomes))
            );
        }
    }
}

// ---------------------------------------------------------------------------
// Phase 6 - teardown
// ---------------------------------------------------------------------------

$section('6. Teardown');

foreach ($createdTasks as $id) {
    $tasks->delete($id);
}

foreach ($createdPeople as $id) {
    $assignments->deleteForPerson($id);
    $people->delete($id);
}

$check(
    'People count is back to baseline',
    $baselinePeople === $people->count(),
    sprintf('baseline %d, now %d', $baselinePeople, $people->count())
);

$check(
    'Task count is back to baseline',
    $baselineTasks === $tasks->count(),
    sprintf('baseline %d, now %d', $baselineTasks, $tasks->count())
);

$strays = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$peopleTable} WHERE notes = %s",
    $marker
));

$check('No rows from this run remain', 0 === $strays, $strays . ' left behind');

// ---------------------------------------------------------------------------

$section('Result');

printf("  %d passed, %d failed, %d skipped\n", $results['pass'], $results['fail'], $results['skip']);

if ([] !== $failures) {
    echo "\nFailures:\n";

    foreach ($failures as $failure) {
        echo '  - ', $failure, "\n";
    }
}

echo "\n", 0 === $results['fail'] ? "OK\n" : "FAILED\n";

if ($isCli) {
    exit(0 === $results['fail'] ? 0 : 1);
}
