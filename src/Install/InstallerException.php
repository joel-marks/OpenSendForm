<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use RuntimeException;

/**
 * A recoverable installer error whose message is safe to show to the person
 * running the wizard: it is always plain language ("Could not connect to the
 * database…"), never a raw driver string or stack detail. The controller
 * renders getMessage() straight into the step it came from.
 */
final class InstallerException extends RuntimeException
{
}
