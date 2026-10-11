<?php

namespace App\Statement\Parser;

use App\Enum\Bank;
use App\Statement\Exception\UnreadableStatementException;
use App\Statement\ParsedTransaction;
use App\Statement\StatementSample;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Reads the statements of one bank, in one export format.
 *
 * Supporting a new bank (or format) only takes a new implementation: StatementParserResolver picks it up.
 */
#[AutoconfigureTag]
interface StatementParser
{
    public function bank(): Bank;

    /**
     * Whether the file is in this parser's format, judging from its beginning only.
     */
    public function supports(StatementSample $sample): bool;

    /**
     * Every transaction of the file, from all its accounts. Must not depend on the current date, nor on what's already imported.
     *
     * @return iterable<ParsedTransaction>
     *
     * @throws UnreadableStatementException
     */
    public function parse(\SplFileInfo $file): iterable;
}
