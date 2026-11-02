<?php

declare(strict_types=1);

namespace EventCrew\Support;

use RuntimeException;

/**
 * A ticket file that could not be read or matched to a ticket file format.
 * The message is translated and is sent to the organizers as it is.
 */
final class TicketFileException extends RuntimeException
{
}
