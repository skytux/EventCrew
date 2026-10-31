<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

/**
 * A mailbox the import reads: IMAP or JMAP. Both give the raw message, so
 * one parser serves both, and both file a message away the same way: marked
 * read and moved to the Imported or the Rejected folder.
 *
 * Every method throws MailboxException when the server cannot be reached or
 * refuses; a message left where it was is tried again next run.
 */
interface Mailbox
{
    /**
     * Unread messages in the folder being read, oldest first.
     *
     * @return array<string, string> message id => raw message
     */
    public function unread(int $limit): array;

    /** Marks the message read and moves it to the Imported folder. */
    public function moveToImported(string $id): void;

    /** Marks the message read and moves it to the Rejected folder. */
    public function moveToRejected(string $id): void;

    /** Ends the session. Never throws. */
    public function close(): void;
}
