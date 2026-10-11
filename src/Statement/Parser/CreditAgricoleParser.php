<?php

namespace App\Statement\Parser;

use App\Enum\Bank;
use App\Enum\TransactionKind;
use App\Statement\AccountRef;
use App\Statement\Exception\UnreadableStatementException;
use App\Statement\ParsedTransaction;
use App\Statement\Reader\CsvReader;
use App\Statement\Reader\FrenchDateParser;
use App\Statement\Reader\MoneyParser;
use App\Statement\StatementSample;

/**
 * The CSV export of Crédit Agricole's website: Windows-1252, ";"-separated, with one table per account
 * ("Compte de Dépôt n° 12345678901"), then one table per monthly statement of each deferred debit card
 * ("carte n° 497010******1234", "Encours débité le 30 septembre 2026;-123.45 €").
 *
 * The purchases of a card statement are imported one by one, and the account's "Prélèvement carte" line that
 * debits their total is marked as their settlement. A settlement without its card statement (older than the
 * card statements the export holds) stays a regular transaction.
 */
final readonly class CreditAgricoleParser implements StatementParser
{
    private const string SEPARATOR = ';';
    private const string ENCODING = 'Windows-1252';
    private const string HEADER = 'Date;Libellé;Débit euros;Crédit euros;';

    public function __construct(
        private CsvReader $csv,
        private MoneyParser $money,
        private FrenchDateParser $dates,
    ) {
    }

    public function bank(): Bank
    {
        return Bank::CreditAgricole;
    }

    public function supports(StatementSample $sample): bool
    {
        return $sample->contains('Téléchargement du', self::HEADER);
    }

    public function parse(\SplFileInfo $file): iterable
    {
        $settlements = $this->cardStatementSettlements($file);

        $account = null;
        $inCardStatement = false;

        foreach ($this->csv->read($file, self::SEPARATOR, self::ENCODING) as $record) {
            $first = trim($record[0]);

            // "Compte de Dépôt carte n° 12345678901"
            if (preg_match('/^(?<label>.+?)\s+n°\s*(?<number>\d+)$/u', $first, $matches)) {
                $account = new AccountRef($matches['number'], $matches['label'], 'EUR');
                $inCardStatement = false;
                continue;
            }

            // "carte n° 497010******1234": the card statements of the last account
            if (preg_match('/^carte n°\s*\S*\*/u', $first)) {
                $inCardStatement = true;
                continue;
            }

            if (!preg_match('#^\d{2}/\d{2}/\d{4}$#', $first)) {
                continue;
            }

            if (null === $account) {
                throw new UnreadableStatementException('The statement lists transactions before naming their account.');
            }

            $date = $this->dates->parse($first);
            $amount = $this->amount($record[3] ?? '') - $this->amount($record[2] ?? '');
            $label = $this->label($record[1] ?? '');

            $kind = match (true) {
                $inCardStatement => TransactionKind::DeferredCardPurchase,
                str_starts_with($label, 'Prélèvement carte') && isset($settlements[self::settlementKey($date, $amount)]) => TransactionKind::DeferredCardSettlement,
                default => TransactionKind::Regular,
            };

            yield new ParsedTransaction($account, $date, $label, $amount, 'EUR', $kind);
        }
    }

    /**
     * The debits announced by the card statements ("Encours débité le 30 septembre 2026;-123.45 €").
     *
     * @return array<string, true>
     */
    private function cardStatementSettlements(\SplFileInfo $file): array
    {
        $settlements = [];
        foreach ($this->csv->read($file, self::SEPARATOR, self::ENCODING) as $record) {
            if (preg_match('/^Encours débité le (?<date>.+)$/u', trim($record[0]), $matches)) {
                $settlements[self::settlementKey($this->dates->parse($matches['date']), $this->money->parse($record[1] ?? '')->amount)] = true;
            }
        }

        return $settlements;
    }

    private static function settlementKey(\DateTimeImmutable $date, int $amount): string
    {
        return $date->format('Y-m-d').'|'.$amount;
    }

    private function amount(string $value): int
    {
        return '' === trim($value) ? 0 : abs($this->money->parse($value)->amount);
    }

    /**
     * Labels span several lines ("Prélèvement", the creditor, references...), padded with spaces and blank lines.
     */
    private function label(string $value): string
    {
        $lines = array_filter(array_map(trim(...), preg_split('/\R/u', $value) ?: []), static fn (string $line): bool => '' !== $line);

        return implode("\n", $lines);
    }
}
