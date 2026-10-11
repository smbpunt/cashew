<?php

namespace App\Statement\Parser;

use App\Enum\Bank;
use App\Statement\AccountRef;
use App\Statement\Exception\UnreadableStatementException;
use App\Statement\ParsedTransaction;
use App\Statement\Reader\CsvReader;
use App\Statement\Reader\FrenchDateParser;
use App\Statement\Reader\MoneyParser;
use App\Statement\StatementSample;

/**
 * The statement ("relevé") that Revolut's app exports in French: UTF-8, ","-separated, with a summary of every
 * account ("Comptes courants Récapitulatifs"), then a table per account ("Comptes courants Relevés de
 * transactions", "Compte personnel (EUR)", "Relevé des transactions"), each ending with a "Total" row.
 *
 * Accounts are told apart by their name, pockets included ("Vacances (EUR)"), as pockets have no account number.
 * A foreign currency account has each amount twice, in its currency then in euros: the first one is kept.
 */
final readonly class RevolutParser implements StatementParser
{
    private const string SEPARATOR = ',';

    public function __construct(
        private CsvReader $csv,
        private MoneyParser $money,
        private FrenchDateParser $dates,
    ) {
    }

    public function bank(): Bank
    {
        return Bank::Revolut;
    }

    public function supports(StatementSample $sample): bool
    {
        return str_starts_with($sample->text, '"Comptes courants Récapitulatifs"') && $sample->contains('Revolut');
    }

    public function parse(\SplFileInfo $file): iterable
    {
        $inTransactions = false;
        $account = null;
        $columns = null;

        foreach ($this->csv->read($file, self::SEPARATOR) as $record) {
            $first = trim($record[0]);

            if ($this->isTitle($record)) {
                // "Comptes courants Récapitulatifs" / "Comptes courants Relevés de transactions"
                if (str_ends_with($first, 'Récapitulatifs') || str_ends_with($first, 'Relevés de transactions')) {
                    $inTransactions = str_ends_with($first, 'Relevés de transactions');
                    $account = null;
                } elseif ($inTransactions && preg_match('/^(?<label>.+?)\s*\((?<currency>[A-Z]{3})\)$/u', $first, $matches)) {
                    $account = new AccountRef($first, $matches['label'], $matches['currency']);
                }
                $columns = null;
                continue;
            }

            if (null === $account) {
                continue;
            }

            if ('Date' === $first) {
                $columns = $this->columns($record);
                continue;
            }

            if ('Total' === $first) {
                $columns = null;
                continue;
            }

            if (null === $columns || '' === $first) {
                continue;
            }

            $money = $this->money->parse($record[$columns['amount']] ?? '');
            if (null !== $money->currency && $money->currency !== $account->currency) {
                throw new UnreadableStatementException(\sprintf('An amount of the "%s" account is in %s.', $account->externalId, $money->currency));
            }

            $category = trim($record[$columns['category']] ?? '');

            yield new ParsedTransaction(
                $account,
                $this->dates->parse($first),
                trim($record[$columns['description']] ?? ''),
                $money->amount,
                $account->currency,
                bankCategory: '' === $category ? null : $category,
            );
        }
    }

    /**
     * A record with only its first cell filled: a section or account name, or a "---------" separator.
     *
     * @param list<string> $record
     */
    private function isTitle(array $record): bool
    {
        return '' !== trim($record[0]) && '' === trim(implode('', \array_slice($record, 1)));
    }

    /**
     * @param list<string> $header
     *
     * @return array{description: int, category: int, amount: int}
     */
    private function columns(array $header): array
    {
        $index = static fn (string $name): int => false !== ($position = array_search($name, $header, true))
            ? $position
            : throw new UnreadableStatementException(\sprintf('The transactions have no "%s" column.', $name));

        return [
            'description' => $index('Description'),
            'category' => $index('Catégorie'),
            // The first one is in the account's currency
            'amount' => $index('Argent entrant/sortant'),
        ];
    }
}
