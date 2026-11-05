<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

/**
 * The two checks the mail import makes on a message before it goes to the
 * site (first written for the separate mail poller, since removed):
 *
 * - the sender: the receiving mail server's Authentication-Results header
 *   must show dmarc=pass for the From domain, or spf=pass for it when no
 *   DMARC result is there at all;
 * - the list: a ticket file (.xlsx or .csv attachment, see ticketFile()),
 *   or a .txt attachment, or otherwise the plain-text body.
 */
final class ImportMessage
{
    /**
     * [ok, reason]. Only the topmost Authentication-Results header counts,
     * and only when its authserv-id is $authservId (our own mail server): the
     * receiving server puts its header above anything the sender wrote, so a
     * header lower down, or a top one naming another server, may be forged.
     * An empty $authservId refuses every message.
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

        if ('' === trim($authservId)) {
            return [false, 'no trusted mail server is set in the mailbox settings'];
        }

        if ([] === $message->headers('Authentication-Results')) {
            return [false, 'no Authentication-Results header'];
        }

        $header = self::resultsHeader($message, $authservId);

        if ('' === $header) {
            return [false, 'the top Authentication-Results header is not from our mail server'];
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

    /**
     * The first .xlsx or .csv attachment, as a ticket file: its file name
     * and contents. An .xlsx file is its bytes as they are; a .csv file is
     * its text, already turned into UTF-8 from the charset the mail names.
     * null when the message carries neither.
     *
     * @return array{filename: string, bytes: string}|null
     */
    public static function ticketFile(MimeMessage $message): ?array
    {
        foreach ($message->parts() as $part) {
            $filename = strtolower($part['filename']);

            if (str_ends_with($filename, '.xlsx')) {
                return ['filename' => $part['filename'], 'bytes' => $part['bytes']];
            }

            if (str_ends_with($filename, '.csv')) {
                return ['filename' => $part['filename'], 'bytes' => $part['text']];
            }
        }

        return null;
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

    /** The topmost Authentication-Results header when our server wrote it, else ''. */
    private static function resultsHeader(MimeMessage $message, string $authservId): string
    {
        $headers = $message->headers('Authentication-Results');

        if ([] === $headers) {
            return '';
        }

        $text = trim((string) preg_replace('/\s+/', ' ', $headers[0]));
        $server = strtolower(trim(explode(';', $text, 2)[0]));

        return $server === strtolower(trim($authservId)) ? $text : '';
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
