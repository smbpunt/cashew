<?php

namespace App\Statement\Parser;

use App\Statement\Exception\UnreadableStatementException;
use App\Statement\StatementSample;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the parser of a statement file among all the StatementParser services.
 */
final readonly class StatementParserResolver
{
    /**
     * @param iterable<StatementParser> $parsers
     */
    public function __construct(
        #[AutowireIterator(StatementParser::class)]
        private iterable $parsers,
    ) {
    }

    /**
     * @throws UnreadableStatementException when no parser supports the file
     */
    public function resolve(StatementSample $sample): StatementParser
    {
        $supporting = [];
        foreach ($this->parsers as $parser) {
            if ($parser->supports($sample)) {
                $supporting[] = $parser;
            }
        }

        // Two parsers claiming the same file is a bug in their supports() methods, not a user error:
        // picking one by priority would silently hide it
        if (\count($supporting) > 1) {
            throw new \LogicException(\sprintf('Several parsers support the same statement: "%s".', implode('", "', array_map(get_debug_type(...), $supporting))));
        }

        return $supporting[0] ?? throw new UnreadableStatementException('statement.error.unsupported_bank');
    }
}
