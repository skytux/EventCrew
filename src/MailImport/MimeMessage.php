<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

/**
 * One email, parsed from its raw RFC 5322 bytes: the headers, and the leaf
 * parts of its MIME tree with their content decoded to UTF-8 text.
 *
 * Both mailbox readers hand over the raw message (IMAP's BODY[], JMAP's
 * downloaded blob), so this is the one parser for both, and it needs no PHP
 * extension beyond what WordPress itself requires.
 *
 * It reads only what the import needs: the From address, Message-ID, the
 * Authentication-Results headers, and the text of text parts and .txt/.csv
 * attachments. It is not a general mail library.
 */
final class MimeMessage
{
    /** A nested message deeper than this is not looked into. */
    private const MAX_DEPTH = 10;

    /**
     * @param array<int, array{0: string, 1: string}> $headers [lower-case name, value] in order
     * @param array<int, array{type: string, filename: string, disposition: string, text: string}> $parts
     */
    private function __construct(
        private readonly array $headers,
        private readonly array $parts
    ) {
    }

    public static function parse(string $raw): self
    {
        [$headers, $body] = self::split(str_replace(["\r\n", "\r"], "\n", $raw));
        $parts = [];
        self::collect($headers, $body, $parts, 0);

        return new self($headers, $parts);
    }

    /** The first header with this name, unfolded; '' when absent. */
    public function header(string $name): string
    {
        return $this->headers($name)[0] ?? '';
    }

    /**
     * Every header with this name, top first.
     *
     * @return array<int, string>
     */
    public function headers(string $name): array
    {
        $name = strtolower($name);
        $found = [];

        foreach ($this->headers as [$key, $value]) {
            if ($key === $name) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /** The From address, lower-case; '' when there is none. */
    public function from(): string
    {
        $value = self::decodeWords($this->header('From'));

        if (1 === preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $matches)) {
            return strtolower(trim($matches[1]));
        }

        if (1 === preg_match('/[^\s<>",;:()]+@[^\s<>",;:()]+/', $value, $matches)) {
            return strtolower(trim($matches[0]));
        }

        return '';
    }

    public function messageId(): string
    {
        return trim($this->header('Message-ID'));
    }

    /**
     * The leaf parts in document order.
     *
     * @return array<int, array{type: string, filename: string, disposition: string, text: string}>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    /**
     * @return array{0: array<int, array{0: string, 1: string}>, 1: string}
     */
    private static function split(string $text): array
    {
        $end = strpos($text, "\n\n");
        $head = false === $end ? $text : substr($text, 0, $end);
        $body = false === $end ? '' : substr($text, $end + 2);

        // Unfold: a line starting with white space continues the one above.
        $head = (string) preg_replace('/\n[ \t]+/', ' ', $head);
        $headers = [];

        foreach (explode("\n", $head) as $line) {
            $colon = strpos($line, ':');

            if (false === $colon || 0 === $colon) {
                continue;
            }

            $headers[] = [strtolower(trim(substr($line, 0, $colon))), trim(substr($line, $colon + 1))];
        }

        return [$headers, $body];
    }

    /**
     * @param array<int, array{0: string, 1: string}> $headers
     * @param array<int, array{type: string, filename: string, disposition: string, text: string}> $parts
     */
    private static function collect(array $headers, string $body, array &$parts, int $depth): void
    {
        $find = static function (string $name) use ($headers): string {
            foreach ($headers as [$key, $value]) {
                if ($key === $name) {
                    return $value;
                }
            }

            return '';
        };

        [$type, $typeParams] = self::valueAndParams($find('content-type'));
        $type = '' === $type ? 'text/plain' : $type;
        [$disposition, $dispositionParams] = self::valueAndParams($find('content-disposition'));

        if (str_starts_with($type, 'multipart/') && $depth < self::MAX_DEPTH) {
            $boundary = $typeParams['boundary'] ?? '';

            if ('' !== $boundary) {
                foreach (self::multipartBodies($body, $boundary) as $partText) {
                    [$partHeaders, $partBody] = self::split($partText);
                    self::collect($partHeaders, $partBody, $parts, $depth + 1);
                }

                return;
            }
        }

        if ('message/rfc822' === $type && $depth < self::MAX_DEPTH) {
            [$innerHeaders, $innerBody] = self::split(self::decodeTransfer($body, $find('content-transfer-encoding')));
            self::collect($innerHeaders, $innerBody, $parts, $depth + 1);

            return;
        }

        $filename = $dispositionParams['filename'] ?? ($typeParams['name'] ?? '');
        $bytes = self::decodeTransfer($body, $find('content-transfer-encoding'));

        $parts[] = [
            'type' => $type,
            'filename' => self::decodeWords($filename),
            'disposition' => $disposition,
            'text' => self::toUtf8($bytes, $typeParams['charset'] ?? 'utf-8'),
        ];
    }

    /**
     * The bodies between the boundary lines, preamble and epilogue left out.
     *
     * @return array<int, string>
     */
    private static function multipartBodies(string $body, string $boundary): array
    {
        $lines = explode("\n", $body);
        $bodies = [];
        $current = null;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if ('--' . $boundary . '--' === $trimmed) {
                if (null !== $current) {
                    $bodies[] = implode("\n", $current);
                }

                return $bodies;
            }

            if ('--' . $boundary === $trimmed) {
                if (null !== $current) {
                    $bodies[] = implode("\n", $current);
                }

                $current = [];
                continue;
            }

            if (null !== $current) {
                $current[] = $line;
            }
        }

        // No closing boundary: keep what was there.
        if (null !== $current) {
            $bodies[] = implode("\n", $current);
        }

        return $bodies;
    }

    /**
     * "text/plain; charset=utf-8; name=x" -> ['text/plain', ['charset' => 'utf-8', 'name' => 'x']].
     * Understands quoted values and RFC 2231 (name*=utf-8''..., name*0=...).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function valueAndParams(string $header): array
    {
        if ('' === trim($header)) {
            return ['', []];
        }

        $semicolon = strpos($header, ';');
        $value = strtolower(trim(false === $semicolon ? $header : substr($header, 0, $semicolon)));
        $params = [];
        $continued = [];

        if (false !== $semicolon) {
            preg_match_all(
                '/;\s*([A-Za-z0-9_\-*]+)\s*=\s*("((?:[^"\\\\]|\\\\.)*)"|[^;]*)/',
                substr($header, $semicolon),
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                $raw = isset($match[3]) && '' !== $match[2] && '"' === $match[2][0]
                    ? stripcslashes($match[3])
                    : trim($match[2]);

                if (1 === preg_match('/^(.+?)\*(\d+)(\*?)$/', $name, $piece)) {
                    $continued[$piece[1]][(int) $piece[2]] = [$raw, '*' === $piece[3]];
                    continue;
                }

                if (str_ends_with($name, '*')) {
                    $params[substr($name, 0, -1)] = self::decode2231($raw, true);
                    continue;
                }

                $params[$name] = $raw;
            }
        }

        foreach ($continued as $name => $pieces) {
            ksort($pieces);
            $joined = '';
            $first = true;

            foreach ($pieces as [$raw, $encoded]) {
                $joined .= $encoded ? self::decode2231($raw, $first) : $raw;
                $first = false;
            }

            $params[$name] = $joined;
        }

        return [$value, $params];
    }

    private static function decode2231(string $raw, bool $hasCharset): string
    {
        $charset = 'utf-8';

        if ($hasCharset && 2 === substr_count($raw, "'")) {
            [$charset, , $raw] = explode("'", $raw, 3);
        }

        return self::toUtf8(rawurldecode($raw), '' === $charset ? 'utf-8' : $charset);
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        $encoding = strtolower(trim($encoding));

        if ('base64' === $encoding) {
            return (string) base64_decode((string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $body), false);
        }

        if ('quoted-printable' === $encoding) {
            return quoted_printable_decode($body);
        }

        return $body;
    }

    /** "=?utf-8?q?...?=" encoded words in a header value, decoded. */
    private static function decodeWords(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

            if (false !== $decoded) {
                return $decoded;
            }
        }

        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($value);
        }

        return $value;
    }

    private static function toUtf8(string $bytes, string $charset): string
    {
        $charset = strtolower(trim($charset, " \t\"'"));

        if (in_array($charset, ['', 'utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return self::cleanUtf8($bytes);
        }

        if (function_exists('mb_convert_encoding')) {
            try {
                $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);

                if (is_string($converted)) {
                    return $converted;
                }
            } catch (\ValueError) {
                // An unknown charset name: try iconv, then treat it as UTF-8.
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);

            if (false !== $converted) {
                return $converted;
            }
        }

        return self::cleanUtf8($bytes);
    }

    /** Drops bytes that are not valid UTF-8, the way Python's errors='replace' keeps text usable. */
    private static function cleanUtf8(string $bytes): string
    {
        if (function_exists('mb_check_encoding') && mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        }

        return $bytes;
    }
}
