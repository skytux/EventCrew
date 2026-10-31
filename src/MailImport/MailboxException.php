<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

use RuntimeException;

/** The mailbox could not be reached, refused the login, or refused a command. */
final class MailboxException extends RuntimeException
{
}
