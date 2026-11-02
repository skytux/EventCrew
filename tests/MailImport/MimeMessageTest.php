<?php

declare(strict_types=1);

namespace EventCrew\Tests\MailImport;

use EventCrew\MailImport\ImportMessage;
use EventCrew\MailImport\MimeMessage;
use EventCrew\Tests\TestCase;

/**
 * The raw-message parser and the two checks made on a mailed list: the
 * sender (DMARC, or SPF without DMARC) and where the names are.
 */
final class MimeMessageTest extends TestCase
{
    private const AUTHSERV = 'mx.example.org';

    private static function message(string $authResults, string $from = 'Lou H <Lou@Example.com>'): string
    {
        return "Authentication-Results: {$authResults}\r\n"
            . "From: {$from}\r\n"
            . "To: import@example.org\r\n"
            . "Subject: =?UTF-8?Q?Lipun_ostajat?=\r\n"
            . "Message-ID: <abc@example.com>\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Anna\r\nBo\r\n";
    }

    // --- parsing -----------------------------------------------------------

    public function testReadsTheFromAddressTheMessageIdAndThePlainBody(): void
    {
        $message = MimeMessage::parse(self::message(self::AUTHSERV . '; dmarc=pass header.from=example.com'));

        self::assertSame('lou@example.com', $message->from());
        self::assertSame('<abc@example.com>', $message->messageId());
        self::assertSame("Anna\nBo\n", ImportMessage::listText($message));
    }

    public function testAMultipartMessageGivesItsCsvAttachmentDecodedToUtf8(): void
    {
        // Latin-1 "Jääskeläinen", base64, with an RFC 2231 file name.
        $csv = base64_encode((string) mb_convert_encoding("Name;Tickets\nJ\u{e4}\u{e4}skel\u{e4}inen;2\n\"Bo; Jr\";1\n", 'ISO-8859-1', 'UTF-8'));
        $raw = "From: lou@example.com\r\n"
            . "Content-Type: multipart/mixed; boundary=\"XYZ\"\r\n"
            . "\r\n"
            . "--XYZ\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n"
            . "\r\n"
            . "Hei, t=C3=A4ss=C3=A4 lista.\r\n"
            . "--XYZ\r\n"
            . "Content-Type: text/csv; charset=iso-8859-1\r\n"
            . "Content-Disposition: attachment; filename*=UTF-8''ostajat%20heinakuu.CSV\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . chunk_split($csv, 76, "\r\n")
            . "--XYZ--\r\n";

        $message = MimeMessage::parse($raw);
        $parts = $message->parts();

        self::assertCount(2, $parts);
        self::assertSame("Hei, t\u{e4}ss\u{e4} lista.", trim($parts[0]['text']));
        self::assertSame('ostajat heinakuu.CSV', $parts[1]['filename']);
        self::assertSame('attachment', $parts[1]['disposition']);
        // The attachment wins over the body; only its first column, quotes honoured.
        self::assertSame("Name\nJ\u{e4}\u{e4}skel\u{e4}inen\nBo; Jr", ImportMessage::listText($message));
    }

    public function testFirstColumnUsesCommasWhenThereAreMoreCommasThanSemicolons(): void
    {
        self::assertSame("Anna\nBo", ImportMessage::firstColumn("Anna,1\nBo,2\n"));
    }

    // --- the sender check --------------------------------------------------

    public function testDmarcPassForTheFromDomainIsAccepted(): void
    {
        $message = MimeMessage::parse(self::message(self::AUTHSERV . '; dkim=pass; dmarc=pass header.from=example.com'));

        self::assertSame([true, 'dmarc=pass'], ImportMessage::senderCheck($message, self::AUTHSERV));
    }

    public function testDmarcFailIsRefusedEvenWhenSpfPassed(): void
    {
        $message = MimeMessage::parse(
            self::message(self::AUTHSERV . '; spf=pass smtp.mailfrom=lou@example.com; dmarc=fail header.from=example.com')
        );

        self::assertFalse(ImportMessage::senderCheck($message, self::AUTHSERV)[0]);
    }

    public function testSpfPassCountsOnlyWhenThereIsNoDmarcResult(): void
    {
        $message = MimeMessage::parse(self::message(self::AUTHSERV . '; spf=pass smtp.mailfrom=lou@example.com'));

        self::assertSame([true, 'spf=pass (no DMARC result)'], ImportMessage::senderCheck($message, self::AUTHSERV));
    }

    public function testAHeaderWrittenBySomeoneElseIsIgnored(): void
    {
        // The sender wrote a passing header naming another server; ours is absent.
        $message = MimeMessage::parse(self::message('evil.example.net; dmarc=pass header.from=example.com'));

        self::assertSame(
            [false, 'no Authentication-Results header from our mail server'],
            ImportMessage::senderCheck($message, self::AUTHSERV)
        );
    }

    public function testDmarcPassForAnotherDomainDoesNotCover(): void
    {
        $message = MimeMessage::parse(self::message(self::AUTHSERV . '; dmarc=pass header.from=other.example'));

        self::assertFalse(ImportMessage::senderCheck($message, self::AUTHSERV)[0]);
    }

    public function testTicketFileGivesAnXlsxAttachmentAsItsRawBytes(): void
    {
        $bytes = "PK\x03\x04\x00\xff binary";
        $raw = "From: lou@example.com\r\n"
            . "Content-Type: multipart/mixed; boundary=\"XYZ\"\r\n"
            . "\r\n"
            . "--XYZ\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Anna\r\n"
            . "--XYZ\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Disposition: attachment; filename=\"Tickets.XLSX\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . chunk_split(base64_encode($bytes), 76, "\r\n")
            . "--XYZ--\r\n";

        self::assertSame(
            ['filename' => 'Tickets.XLSX', 'bytes' => $bytes],
            ImportMessage::ticketFile(MimeMessage::parse($raw))
        );
    }

    public function testTicketFileIsNullForAPlainTextMail(): void
    {
        self::assertNull(ImportMessage::ticketFile(MimeMessage::parse(self::message(self::AUTHSERV . '; dmarc=pass'))));
    }
}
