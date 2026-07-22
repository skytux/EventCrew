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
 * callback_query updates at the live webhook at once - with curl_multi where the
 * host has it, or raw sockets where it does not (some shared hosts disable the
 * curl_multi_* family) - against a capacity-2 task seeded with N verified test
 * people, then asserts exactly two of them ended up holding a slot. It aims at
 * whichever door the webhook is installed on, the admin-ajax fallback included,
 * so it tests the same path Telegram uses. Everything it creates is deleted
 * afterwards.
 *
 * Run it under WP-CLI from the WordPress root, after the bot's webhook has been
 * installed from Settings (it reads the same secret):
 *
 *     wp eval-file wp-content/plugins/eventcrew/tools/concurrency-check.php
 *
 * Without WP-CLI, copy it to the WordPress root, load it in a browser while
 * signed in as an administrator, and delete it afterwards. It refuses to run
 * for anyone without `manage_options`. It uses curl_multi when available and
 * falls back to raw sockets otherwise, so it needs no particular extension.
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

if (! function_exists('curl_multi_init') && ! function_exists('stream_socket_client')) {
    echo "FAIL: neither curl_multi nor stream_socket_client is available to send concurrent requests.\n";
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
// Fire every join at once at whichever door the webhook is installed on - the
// same URL Telegram is pointed at, so the admin-ajax fallback is exercised on
// hosts that block /wp-json rather than the REST route those hosts would reject.
// ---------------------------------------------------------------------------

/**
 * Fire all requests together with curl_multi.
 *
 * @param array<int, string> $payloads
 */
function eventcrew_fire_curl_multi(string $url, string $secret, array $payloads): void
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($payloads as $payload) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
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
}

/**
 * The curl_multi-free path, for hosts that disable the curl_multi_* family
 * (InfinityFree among them). Every socket is connected first - so the TLS
 * handshakes are done - then all the requests are written back to back, so they
 * reach the server as close to simultaneously as raw PHP allows. That overlap is
 * the whole point: it is what puts two joins on the last slot at the same instant.
 *
 * @param array<int, string> $payloads
 */
function eventcrew_fire_sockets(string $url, string $secret, array $payloads): void
{
    $parts = wp_parse_url($url);
    $scheme = (string) ($parts['scheme'] ?? 'https');
    $host = (string) ($parts['host'] ?? '');
    $port = (int) ($parts['port'] ?? ('https' === $scheme ? 443 : 80));
    $path = (string) ($parts['path'] ?? '/');

    if (isset($parts['query'])) {
        $path .= '?' . $parts['query'];
    }

    $remote = ('https' === $scheme ? 'ssl' : 'tcp') . '://' . $host . ':' . $port;

    /** @var array<int, resource> $sockets */
    $sockets = [];
    $requests = [];

    foreach ($payloads as $i => $payload) {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);

        if (false === $socket) {
            printf("  socket %d could not connect: %s (%d)\n", $i, $errstr, $errno);
            continue;
        }

        $sockets[$i] = $socket;
        $requests[$i] = sprintf(
            "POST %s HTTP/1.1\r\nHost: %s\r\nContent-Type: application/json\r\n"
            . "X-Telegram-Bot-Api-Secret-Token: %s\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $path,
            $host,
            $secret,
            strlen($payload),
            $payload
        );
    }

    // The connections are open; this loop is the moment the requests race.
    foreach ($sockets as $i => $socket) {
        fwrite($socket, $requests[$i]);
        fflush($socket);
    }

    // Drain each response so the server finishes every join before teardown
    // reads the count. The race already happened when the writes above landed.
    foreach ($sockets as $socket) {
        stream_set_timeout($socket, 30);

        while (! feof($socket)) {
            if ('' === (string) fread($socket, 8192)) {
                break;
            }
        }

        fclose($socket);
    }
}

$url = WebhookController::webhookUrl($secret);
$payloads = [];

for ($i = 0; $i < $contenders; $i++) {
    $telegramId = $telegramBase + $i;

    $payloads[] = (string) wp_json_encode([
        'update_id' => $telegramId,
        'callback_query' => [
            'id' => (string) $telegramId,
            'from' => ['id' => $telegramId, 'first_name' => 'Concurrency'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private']],
            'data' => 'j:' . $taskId,
        ],
    ]);
}

if (function_exists('curl_multi_init')) {
    eventcrew_fire_curl_multi($url, $secret, $payloads);
} else {
    eventcrew_fire_sockets($url, $secret, $payloads);
}

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
