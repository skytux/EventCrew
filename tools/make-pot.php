<?php

/**
 * Extracts the translatable strings from src/ and templates/ into
 * languages/eventcrew.pot.
 *
 * A small token-based extractor rather than WP-CLI's `i18n make-pot`, because
 * this project's dev box has no WP-CLI (see ROADMAP's dev-environment note) and
 * the plugin uses only a handful of gettext calls, all with the literal
 * 'eventcrew' domain. It handles the singular forms and _n()'s plural, carries
 * the `/* translators: *\/` hint that precedes a call, and records every file
 * and line a string appears on.
 *
 *     php tools/make-pot.php
 *
 * @package EventCrew
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$domain = 'eventcrew';

/** Single-message forms: function => index of the msgid argument (0-based). */
$singular = [
    '__' => 0,
    'esc_html__' => 0,
    'esc_attr__' => 0,
    'esc_html_e' => 0,
    'esc_attr_e' => 0,
    '_e' => 0,
    'esc_html_x' => 0,
    '_x' => 0,
];

/** Plural forms: function => [singular index, plural index]. */
$plural = [
    '_n' => [0, 1],
    '_nx' => [0, 1],
];

/**
 * @var array<string, array{comments: array<string, true>, refs: array<int, string>, plural: ?string}>
 */
$entries = [];

$addEntry = static function (
    string $msgid,
    ?string $msgidPlural,
    string $ref,
    string $comment
) use (&$entries): void {
    if ('' === $msgid) {
        return;
    }

    $key = (null === $msgidPlural ? '' : $msgidPlural . "\x00") . $msgid;

    if (! isset($entries[$key])) {
        $entries[$key] = ['comments' => [], 'refs' => [], 'plural' => $msgidPlural];
    }

    $entries[$key]['refs'][] = $ref;

    if ('' !== $comment) {
        $entries[$key]['comments'][$comment] = true;
    }
};

/**
 * Collects the string-literal arguments of a call starting at token $i (the
 * function-name token). Returns the literal values in order, with null for any
 * argument that is not a plain string (a variable, concatenation, etc.).
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 * @return array{0: array<int, ?string>, 1: int}
 */
$readArgs = static function (array $tokens, int $i): array {
    $count = count($tokens);
    $j = $i + 1;

    // Skip whitespace to the opening parenthesis.
    while ($j < $count && is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
        $j++;
    }

    if ($j >= $count || '(' !== $tokens[$j]) {
        return [[], $i];
    }

    $depth = 0;
    $args = [];
    $current = null;      // ?string literal for the current argument
    $currentClean = true; // whether the current arg is a lone string literal

    for (; $j < $count; $j++) {
        $token = $tokens[$j];

        if ('(' === $token) {
            $depth++;
            continue;
        }

        if (')' === $token) {
            $depth--;

            if (0 === $depth) {
                $args[] = $currentClean ? $current : null;
                break;
            }

            continue;
        }

        if (1 === $depth && ',' === $token) {
            $args[] = $currentClean ? $current : null;
            $current = null;
            $currentClean = true;
            continue;
        }

        if (is_array($token) && T_WHITESPACE === $token[0]) {
            continue;
        }

        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === $depth && null === $current) {
            // Decode a single- or double-quoted literal.
            $raw = $token[1];
            $quote = $raw[0];
            $inner = substr($raw, 1, -1);
            $current = "'" === $quote
                ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
                : stripcslashes($inner);
            continue;
        }

        // Anything else in this argument means it is not a lone literal.
        if (1 === $depth || $depth > 1) {
            $currentClean = false;
        }
    }

    return [$args, $j];
};

/**
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 * @param int $i Function-name token index.
 */
$precedingComment = static function (array $tokens, int $i): string {
    for ($k = $i - 1; $k >= 0 && $i - $k < 6; $k--) {
        $token = $tokens[$k];

        if (is_array($token) && T_WHITESPACE === $token[0]) {
            continue;
        }

        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            if (1 === preg_match('#translators:\s*(.+?)\s*(\*/)?$#is', $token[1], $m)) {
                return trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
            }
        }

        break;
    }

    return '';
};

$files = [];

foreach (['src', 'templates'] as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

foreach ($files as $path) {
    $tokens = token_get_all((string) file_get_contents($path));
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || T_STRING !== $token[0]) {
            continue;
        }

        $name = $token[1];
        $isSingular = isset($singular[$name]);
        $isPlural = isset($plural[$name]);

        if (! $isSingular && ! $isPlural) {
            continue;
        }

        // Skip a method call ($obj->__()) or a namespaced/defined function.
        $prev = $tokens[$i - 1] ?? '';
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }

        [$args] = $readArgs($tokens, $i);

        if ([] === $args) {
            continue;
        }

        $ref = $relative . ':' . $token[2];
        $comment = $precedingComment($tokens, $i);

        if ($isSingular) {
            $msgid = $args[$singular[$name]] ?? null;

            if (null !== $msgid) {
                $addEntry($msgid, null, $ref, $comment);
            }

            continue;
        }

        [$singularIdx, $pluralIdx] = $plural[$name];
        $msgid = $args[$singularIdx] ?? null;
        $msgidPlural = $args[$pluralIdx] ?? null;

        if (null !== $msgid && null !== $msgidPlural) {
            $addEntry($msgid, $msgidPlural, $ref, $comment);
        }
    }
}

ksort($entries, SORT_STRING);

/**
 * Escape a string for a POT msgid/msgstr value.
 */
$escape = static fn (string $value): string => str_replace(
    ["\\", "\"", "\n", "\t"],
    ["\\\\", "\\\"", "\\n", "\\t"],
    $value
);

$out = <<<POT
# Copyright (C) EventCrew contributors
# This file is distributed under the GPL-2.0 license.
msgid ""
msgstr ""
"Project-Id-Version: EventCrew\\n"
"Report-Msgid-Bugs-To: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: {$domain}\\n"

POT;

$out .= "\n";

foreach ($entries as $key => $entry) {
    // References, wrapped a few per line the way gettext tools do.
    $refs = array_values(array_unique($entry['refs']));
    foreach (array_chunk($refs, 4) as $chunk) {
        $out .= '#: ' . implode(' ', $chunk) . "\n";
    }

    foreach (array_keys($entry['comments']) as $comment) {
        $out .= '#. translators: ' . $comment . "\n";
    }

    // Recover the plain msgid: the key is optionally "plural\x00msgid".
    $msgid = null === $entry['plural'] ? (string) $key : substr((string) $key, strlen($entry['plural']) + 1);

    $out .= 'msgid "' . $escape($msgid) . "\"\n";

    if (null !== $entry['plural']) {
        $out .= 'msgid_plural "' . $escape($entry['plural']) . "\"\n";
        $out .= "msgstr[0] \"\"\n";
        $out .= "msgstr[1] \"\"\n\n";
        continue;
    }

    $out .= "msgstr \"\"\n\n";
}

$dir = $root . '/languages';

if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
    fwrite(STDERR, "Could not create languages/.\n");
    exit(1);
}

$target = $dir . '/eventcrew.pot';
file_put_contents($target, $out);

printf("%s\n%d strings\n", $target, count($entries));
