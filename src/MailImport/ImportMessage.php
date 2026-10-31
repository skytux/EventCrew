<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

/**
 * The two checks the mail import makes on a message before it goes to the
 * site, ported from tools/mail-import/eventcrew-mail-import.py so both paths
 * accept and refuse the same mail:
 *
 * - the sender: the receiving mail server's Authentication-Results header
 *   must show dmarc=pass for the From domain, or spf=pass for it when no
 *   DMARC result is there at all;
 * - the list: a .txt or .csv attachment (first column of each row), or
 *   otherwise the plain-text body.
 */
final class ImportMessage
{
    /**
     * [ok, reason]. With $authservId set, only an Authentication-Results
     * header naming that server counts; otherwise the top one does, since the
     * receiving server puts its header above anything the sender wrote.
     *
     * @return array{0: bool, 1: string}
     */
    public static function senderCheck(MimeMessage $message, string $authservId = ''): array
    {
        $address = $message->from();
        $domain = self::domainOf($address);

        if ('' === $domain) {
            return [false, 'no From address'];
        }

        $header = self::resultsHeader($message, $authservId);

        if ('' === $header) {
            return [false, 'no Authentication-Results header from our mail server'];
        }

        $dmarc = self::results($header, 'dmarc');

        if ([] !== $dmarc) {
            foreach ($dmarc as [$result, $props]) {
                if ('pass' === $result && ($props['header.from'] ?? $domain) === $domain) {
                    return [true, 'dmarc=pass'];
                }
            }

            return [false, 'dmarc did not pass for ' . $domain];
        }

        foreach (self::results($header, 'spf') as [$result, $props]) {
            $mailFrom = $props['smtp.mailfrom'] ?? '';
            // smtp.mailfrom is a whole address or, for some servers, a domain.
            $mailFromDomain = '' !== self::domainOf($mailFrom) ? self::domainOf($mailFrom) : $mailFrom;

            if ('pass' === $result && $mailFromDomain === $domain) {
                return [true, 'spf=pass (no DMARC result)'];
            }
        }

        return [false, 'neither dmarc nor spf passed for ' . $domain];
    }

    /** The names: a .csv or .txt attachment if there is one, else the text body. */
    public static function listText(MimeMessage $message): string
    {
        foreach ($message->parts() as $part) {
            $filename = strtolower($part['filename']);

            if (str_ends_with($filename, '.csv')) {
                return self::firstColumn($part['text']);
            }

            if (str_ends_with($filename, '.txt')) {
                return $part['text'];
            }
        }

        foreach ($message->parts() as $part) {
            if ('text/plain' === $part['type'] && 'attachment' !== $part['disposition']) {
                return $part['text'];
            }
        }

        return '';
    }

    /**
     * The first column of a CSV, one value per line. Semicolons count too: a
     * spreadsheet saved as CSV with a Finnish locale uses them.
     */
    public static function firstColumn(string $text): string
    {
        $sample = substr($text, 0, 4096);
        $delimiter = substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');

        if (false === $stream) {
            return '';
        }

        fwrite($stream, $text);
        rewind($stream);
        $lines = [];

        while (false !== ($row = fgetcsv($stream, null, $delimiter, '"', ''))) {
            if (isset($row[0]) && null !== $row[0] && [null] !== $row) {
                $lines[] = (string) $row[0];
            }
        }

        fclose($stream);

        return implode("\n", $lines);
    }

    private static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return false === $at ? '' : strtolower(substr($address, $at + 1));
    }

    private static function resultsHeader(MimeMessage $message, string $authservId): string
    {
        foreach ($message->headers('Authentication-Results') as $header) {
            $text = trim((string) preg_replace('/\s+/', ' ', $header));

            if ('' === $authservId) {
                return $text;
            }

            $server = strtolower(trim(explode(';', $text, 2)[0]));

            if ($server === strtolower(trim($authservId))) {
                return $text;
            }
        }

        return '';
    }

    /**
     * [[result, [property => value]], ...] for each method=result clause.
     *
     * @return array<int, array{0: string, 1: array<string, string>}>
     */
    private static function results(string $header, string $method): array
    {
        $found = [];
        $clauses = array_slice(explode(';', $header), 1);

        foreach ($clauses as $clause) {
            $clause = trim($clause);

            if (1 !== preg_match('/^' . preg_quote($method, '/') . '\s*=\s*(\w+)(.*)$/is', $clause, $match)) {
                continue;
            }

            $props = [];
            preg_match_all('/([\w.]+)\s*=\s*"?([^\s";]+)"?/', $match[2], $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                $props[strtolower($pair[1])] = strtolower($pair[2]);
            }

            $found[] = [strtolower($match[1]), $props];
        }

        return $found;
    }
}
