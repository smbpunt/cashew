<?php

namespace App\Statement\Exception;

/**
 * The file isn't a statement that a parser can read: its message is safe to show to the user.
 */
final class UnreadableStatementException extends StatementException
{
}
