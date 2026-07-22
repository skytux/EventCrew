<?php

/**
 * Builds an installable plugin zip in dist/.
 *
 * A script rather than a one-off zip because the interesting part is the
 * exclusion list: the repository holds a test suite, a Composer tree and this
 * tooling, none of which belongs on a web server. Getting that wrong ships
 * either a broken plugin or several megabytes of dev dependencies into a
 * public directory.
 *
 * The zip contains a single top-level eventcrew/ directory, which is what
 * WordPress's Plugins → Add New → Upload Plugin expects.
 *
 *     php tools/build-zip.php
 *
 * Needs the zip extension; see the dev-environment note in ROADMAP.md for the
 * ini this project runs PHP with.
 *
 * @package EventCrew
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$slug = 'eventcrew';

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The zip extension is not loaded. Add extension=zip to the dev ini.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// What ships
// ---------------------------------------------------------------------------

/**
 * Named explicitly rather than derived by excluding things, so a new top-level
 * directory has to be considered before it can end up on someone's server.
 *
 * The `tools/` verification kit (VERIFY.md, verify-install.php,
 * concurrency-check.php) used to ship so it would land on the real host with the
 * plugin. As of v1.0 every owed host check has passed - install, webhook,
 * concurrency, cron and mail - so the kit no longer ships; it stays in the repo
 * for re-running against a future host. Re-add the three files here if a new
 * host ever needs the on-site checks bundled again.
 */
$include = [
    'eventcrew.php',
    'uninstall.php',
    'LICENSE',
    'README.md',
    'src',
    'templates',
    'blocks',
    'languages',
];

/** Never ships, at any point, whatever else changes above. */
$excludePatterns = [
    '#(^|/)\.#',            // dotfiles and .git
    '#(^|/)vendor(/|$)#',
    '#(^|/)tests?(/|$)#',
    '#(^|/)dist(/|$)#',
    '#\.dist$#',
    '#(^|/)composer\.(json|lock|phar)$#',
];

// ---------------------------------------------------------------------------
// Version, read from the plugin header so it cannot disagree with the zip name
// ---------------------------------------------------------------------------

$header = (string) file_get_contents($root . '/eventcrew.php');

if (1 !== preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $header, $m)) {
    fwrite(STDERR, "Could not read Version from the plugin header.\n");
    exit(1);
}

$version = trim($m[1]);

// The constant is used at runtime while the header drives the updater, so a
// mismatch between them is a real bug that only shows up after release.
if (1 === preg_match("/define\('EVENTCREW_VERSION',\s*'([^']+)'\)/", $header, $c) && $c[1] !== $version) {
    fwrite(STDERR, sprintf(
        "Version mismatch: header says %s, EVENTCREW_VERSION says %s.\n",
        $version,
        $c[1]
    ));
    exit(1);
}

// ---------------------------------------------------------------------------
// Collect
// ---------------------------------------------------------------------------

$shouldExclude = static function (string $relative) use ($excludePatterns): bool {
    foreach ($excludePatterns as $pattern) {
        if (1 === preg_match($pattern, $relative)) {
            return true;
        }
    }

    return false;
};

/** @var array<int, string> $files */
$files = [];

foreach ($include as $entry) {
    $path = $root . '/' . $entry;

    if (is_file($path)) {
        $files[] = $entry;
        continue;
    }

    if (! is_dir($path)) {
        fwrite(STDERR, sprintf("Missing: %s\n", $entry));
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if ($shouldExclude($relative)) {
            continue;
        }

        $files[] = $relative;
    }
}

sort($files);

$stray = array_filter($files, $shouldExclude);

if ([] !== $stray) {
    fwrite(STDERR, "Excluded files reached the archive list: " . implode(', ', $stray) . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

if (! is_dir($root . '/dist') && ! mkdir($root . '/dist', 0o755, true)) {
    fwrite(STDERR, "Could not create dist/.\n");
    exit(1);
}

$zipPath = sprintf('%s/dist/%s-%s.zip', $root, $slug, $version);

if (is_file($zipPath) && ! unlink($zipPath)) {
    fwrite(STDERR, sprintf("Could not replace %s.\n", $zipPath));
    exit(1);
}

$zip = new ZipArchive();

if (true !== $zip->open($zipPath, ZipArchive::CREATE)) {
    fwrite(STDERR, sprintf("Could not open %s for writing.\n", $zipPath));
    exit(1);
}

foreach ($files as $relative) {
    $zip->addFile($root . '/' . $relative, $slug . '/' . $relative);
}

$zip->close();

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

printf("%s\n\n", $zipPath);

foreach ($files as $relative) {
    printf("  %s/%s\n", $slug, $relative);
}

printf(
    "\n%d files, %s\n",
    count($files),
    number_format(round(filesize($zipPath) / 1024, 1), 1) . ' KB'
);
