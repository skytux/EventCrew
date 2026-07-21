<?php

/**
 * EventCrew concurrency check - verification item 3.
 *
 * The unit suite fakes $wpdb, so it can prove that AssignmentRepository::join()
 * builds one conditional statement, but never that the statement actually holds
 * a capacity line under real, simultaneous load. That is the failure the
 * Telegram group surface makes likely - two people tapping [Join] on the last
 * slot in the same instant - and the one clicking around will never reproduce.
 *
 * This closes it over the real path the bot uses: it fires N synthetic
 * callback_query updates at the live webhook at once with curl_multi, against a
 * capacity-2 task seeded with N verified test people, then asserts exactly two
 * of them ended up holding a slot. Everything it creates is deleted afterwards.
 *
 * Run it under WP-CLI from the WordPress root, after the bot's webhook has been
 * installed from Settings (it reads the same secret):
 *
 *     wp eval-file wp-content/plugins/eventcrew/tools/concurrency-check.php
 *
 * Without WP-CLI, copy it to the WordPress root, load it in a browser while
 * signed in as an administrator, and delete it afterwards. It refuses to run
 * for anyone without `manage_options`, and needs the cURL extension.
 *
 * @package EventCrew
 */

declare(strict_types=1);

use EventCrew\Database\Schema;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Telegram\WebhookController;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if (! defined('ABSPATH')) {
    $root = __DIR__;

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
    wp_die('EventCrew concurrency check requires an administrator.');
}

if (! $isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

if (! function_exists('curl_multi_init')) {
    echo "FAIL: the cURL extension is required for the concurrent requests.\n";
    exit(1);
}

$secret = trim((string) get_option(WebhookController::SECRET_OPTION, ''));

if ('' === $secret) {
    echo "FAIL: no webhook secret is stored. Install the webhook from Settings first.\n";
    exit(1);
}

global $wpdb;

$capacity = 2;
$contenders = 6;
$taskId = 0;
$personIds = [];
$telegramBase = 900000000; // Well clear of any real Telegram user id in the data.

$peopleTable = Schema::table(Schema::PEOPLE);
$tasksTable = Schema::table(Schema::TASKS);
$assignmentsTable = Schema::table(Schema::ASSIGNMENTS);
$now = current_time('mysql');

// ---------------------------------------------------------------------------
// Seed: one capacity-2 task, and N verified people each linked to a fake
// Telegram id the webhook will resolve.
// ---------------------------------------------------------------------------

$wpdb->insert($tasksTable, [
    'event_label' => 'Concurrency check',
    'task_date' => current_time('Y-m-d'),
    'role_slug' => 'concurrency_check',
    'capacity' => $capacity,
    'notes' => 'temporary - safe to delete',
    'created_at' => $now,
]);
$taskId = (int) $wpdb->insert_id;

for ($i = 0; $i < $contenders; $i++) {
    $telegramId = $telegramBase + $i;

    $wpdb->insert($peopleTable, [
        'email' => sprintf('concurrency+%d@eventcrew.test', $telegramId),
        'email_verified_at' => $now,
        'display_name' => 'Concurrency ' . $i,
        'telegram_user_id' => $telegramId,
        'telegram_chat_id' => $telegramId,
        'notes' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $personIds[] = (int) $wpdb->insert_id;
}

// ---------------------------------------------------------------------------
// Fire every join at once at the real webhook.
// ---------------------------------------------------------------------------

$url = rest_url(WebhookController::ROUTE_NAMESPACE . WebhookController::ROUTE);
$multi = curl_multi_init();
$handles = [];

for ($i = 0; $i < $contenders; $i++) {
    $telegramId = $telegramBase + $i;

    $update = [
        'update_id' => $telegramId,
        'callback_query' => [
            'id' => (string) $telegramId,
            'from' => ['id' => $telegramId, 'first_name' => 'Concurrency'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private']],
            'data' => 'j:' . $taskId,
        ],
    ];

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => (string) wp_json_encode($update),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Telegram-Bot-Api-Secret-Token: ' . $secret,
        ],
    ]);

    curl_multi_add_handle($multi, $handle);
    $handles[] = $handle;
}

do {
    $status = curl_multi_exec($multi, $running);

    if ($running) {
        curl_multi_select($multi);
    }
} while ($running && CURLM_OK === $status);

foreach ($handles as $handle) {
    curl_multi_remove_handle($multi, $handle);
    curl_close($handle);
}

curl_multi_close($multi);

// ---------------------------------------------------------------------------
// Assert: exactly `capacity` slots were taken, no more.
// ---------------------------------------------------------------------------

$occupying = AssignmentStatus::occupying();
$statusPlaceholders = implode(',', array_fill(0, count($occupying), '%s'));

$taken = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$assignmentsTable}
        WHERE task_id = %d AND status IN ({$statusPlaceholders})",
        ...array_merge([$taskId], $occupying)
    )
);

$pass = ($taken === $capacity);

printf(
    "%s: %d of %d contenders claimed a capacity-%d task (expected exactly %d).\n",
    $pass ? 'PASS' : 'FAIL',
    $taken,
    $contenders,
    $capacity,
    $capacity
);

// ---------------------------------------------------------------------------
// Teardown: remove only what this run created.
// ---------------------------------------------------------------------------

$wpdb->delete($assignmentsTable, ['task_id' => $taskId]);
$wpdb->delete($tasksTable, ['id' => $taskId]);

foreach ($personIds as $personId) {
    $wpdb->delete($peopleTable, ['id' => $personId]);
}

echo "Cleaned up.\n";

exit($pass ? 0 : 1);
