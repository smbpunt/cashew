<?php

namespace App\Statement\Exception;

/**
 * The same file was already uploaded: its message is safe to show to the user.
 */
final class DuplicateStatementException extends \RuntimeException
{
}
