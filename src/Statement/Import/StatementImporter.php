<?php

namespace App\Statement\Import;

use App\Entity\BankAccount;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use App\Statement\Exception\UnreadableStatementException;
use App\Statement\Parser\StatementParserResolver;
use App\Statement\StatementSample;
use App\Statement\StatementStorage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves the transactions of an uploaded statement: those of whole months only, and only those not already known.
 */
final readonly class StatementImporter
{
    public function __construct(
        private StatementStorage $storage,
        private StatementParserResolver $parsers,
        private CutoffPolicy $cutoff,
        private TransactionFingerprinter $fingerprinter,
        private AccountResolver $accounts,
        private TransactionRepository $transactions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Persists the new transactions and accounts, without flushing them.
     *
     * @throws UnreadableStatementException
     */
    public function import(StatementImport $import): ImportResult
    {
        $path = $import->getStoredPath() ?? throw new \LogicException(\sprintf('The file of statement import #%d is gone.', $import->getId()));
        $file = $this->storage->file($path);
        $parser = $this->parsers->resolve(StatementSample::fromFile($file));

        /** @var array<string, int> $occurrences */
        $occurrences = [];
        /** @var array<string, array<string, true>> $known fingerprints by account external id */
        $known = [];
        $imported = $skipped = $afterCutoff = 0;

        foreach ($parser->parse($file) as $parsed) {
            $externalId = $parsed->account->externalId;

            // Counted before the cutoff, for the ranks to match those of a statement ending earlier
            $identity = $externalId."\0".$this->fingerprinter->identity($parsed);
            $occurrences[$identity] = ($occurrences[$identity] ?? -1) + 1;

            if (!$this->cutoff->allows($parsed->date)) {
                ++$afterCutoff;
                continue;
            }

            $account = $this->accounts->resolve($import->getOwner(), $parser->bank(), $parsed->account);
            $fingerprint = $this->fingerprinter->fingerprint($parsed, $occurrences[$identity]);
            $known[$externalId] ??= $this->knownFingerprints($account);

            if (isset($known[$externalId][$fingerprint])) {
                ++$skipped;
                continue;
            }

            $this->entityManager->persist(new Transaction(
                $account,
                $import,
                $parsed->date,
                $parsed->label,
                $parsed->amount,
                $parsed->currency,
                $parsed->kind,
                $parsed->bankCategory,
                $fingerprint,
            ));
            $known[$externalId][$fingerprint] = true;
            ++$imported;
        }

        return new ImportResult($parser->bank(), $imported, $skipped, $afterCutoff);
    }

    /**
     * @return array<string, true>
     */
    private function knownFingerprints(BankAccount $account): array
    {
        if (null === $account->getId()) {
            return [];
        }

        return array_fill_keys($this->transactions->findFingerprints($account), true);
    }
}
