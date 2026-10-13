<?php

namespace App\Tests\MessageHandler;

use App\Entity\BankAccount;
use App\Entity\Counterparty;
use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\ImportStatus;
use App\Enum\TransactionKind;
use App\Message\ImportStatement;
use App\MessageHandler\ImportStatementHandler;
use App\Statement\Exception\DuplicateStatementException;
use App\Statement\StatementUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ImportStatementHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string FIXTURES = __DIR__.'/../Fixtures/Statement/';

    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = new User()->setLogin('alice')->setPassword('not-a-real-hash');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testImportsTheWholeMonthsOfAStatement(): void
    {
        self::mockTime('2026-10-11 15:00');

        $import = $this->uploadAndImport('credit_agricole.csv');

        self::assertSame(ImportStatus::Done, $import->getStatus());
        self::assertSame(Bank::CreditAgricole, $import->getBank());
        self::assertSame([7, 0, 0], [$import->getImportedCount(), $import->getSkippedCount(), $import->getAfterCutoffCount()]);
        self::assertSame('2026-10-11 15:00', $import->getFinishedAt()?->format('Y-m-d H:i'));

        $accounts = $this->entityManager->getRepository(BankAccount::class)->findBy(['owner' => $this->user]);
        self::assertCount(1, $accounts);
        self::assertSame(['12345678901', 'Compte de Dépôt carte', 'EUR'], [$accounts[0]->getExternalId(), $accounts[0]->getLabel(), $accounts[0]->getCurrency()]);

        self::assertSame([
            TransactionKind::DeferredCardSettlement->value => 1,
            TransactionKind::Regular->value => 3,
            TransactionKind::CardSettlement->value => 1,
            TransactionKind::DeferredCardPurchase->value => 2,
        ], $this->countKinds());
    }

    public function testSuggestsTheRecurringTransactionsOfTheStatement(): void
    {
        self::mockTime('2026-10-11');

        $import = $this->uploadAndImport('credit_agricole_recurring.csv');

        self::assertSame(26, $import->getImportedCount());
        $series = $this->entityManager->getRepository(RecurringSeries::class)->findAll();
        usort($series, static fn (RecurringSeries $a, RecurringSeries $b): int => $a->getName() <=> $b->getName());
        self::assertSame(
            [['Echeance Pret', -85000, 4], ['Edf Clients Particuliers', -8500, 4], ['Free Mobile', -1999, 5], ['Salaire Acme Sas', 250000, 4]],
            array_map(fn (RecurringSeries $series): array => [
                $series->getName(),
                $series->getTypicalAmount(),
                $this->entityManager->getRepository(Transaction::class)->count(['recurringSeries' => $series]),
            ], $series),
        );
        // Not the shopping
        self::assertSame(9, $this->entityManager->getRepository(Transaction::class)->count(['recurringSeries' => null]));

        // One counterparty for all the transactions of each, those of no series too
        $carrefour = $this->entityManager->getRepository(Counterparty::class)->findOneBy(['owner' => $this->user, 'key' => 'carrefour marseille']);
        self::assertNotNull($carrefour);
        self::assertSame(9, $this->entityManager->getRepository(Transaction::class)->count(['counterparty' => $carrefour]));
    }

    public function testEachUserHasCounterpartiesOfTheirOwn(): void
    {
        self::mockTime('2026-10-11');
        $counterparties = $this->entityManager->getRepository(Counterparty::class);
        $this->uploadAndImport('credit_agricole.csv');
        $count = $counterparties->count(['owner' => $this->user->getId()]);
        self::assertGreaterThan(0, $count);

        // The same transactions, in another file: the same counterparties
        $this->uploadAndImport('credit_agricole.csv', append: "\n");
        self::assertSame($count, $counterparties->count(['owner' => $this->user->getId()]));

        $this->user = new User()->setLogin('bob')->setPassword('not-a-real-hash');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
        $this->uploadAndImport('credit_agricole.csv');

        self::assertSame($count, $counterparties->count(['owner' => $this->user->getId()]));
        self::assertSame(2 * $count, $counterparties->count([]));
    }

    public function testLeavesTheMonthInProgressForLater(): void
    {
        self::mockTime('2026-09-20');

        $import = $this->uploadAndImport('credit_agricole.csv');

        // Only August 31st: the rest is from September
        self::assertSame([1, 0, 6], [$import->getImportedCount(), $import->getSkippedCount(), $import->getAfterCutoffCount()]);
    }

    public function testAnOverlappingStatementOnlyImportsWhatIsNew(): void
    {
        self::mockTime('2026-09-20');
        $this->uploadAndImport('credit_agricole.csv');

        self::mockTime('2026-10-11');
        // The same transactions, in another file
        $import = $this->uploadAndImport('credit_agricole.csv', append: "\n");

        self::assertSame([6, 1, 0], [$import->getImportedCount(), $import->getSkippedCount(), $import->getAfterCutoffCount()]);
        // Both identical "FREE MOBILE" debits of September 15th, plus everything else, once
        self::assertSame(7, $this->entityManager->getRepository(Transaction::class)->count([]));
    }

    public function testASettlementImportedWithoutItsCardStatementIsMarkedOnceTheCardStatementComes(): void
    {
        self::mockTime('2026-10-11');
        $full = (string) file_get_contents(self::FIXTURES.'credit_agricole.csv');
        // The account's transactions only: the card statement debited on September 30th is missing
        $this->uploadAndImport(contents: strstr($full, 'MONSIEUR JEAN DUPONT - Titulaire', true) ?: throw new \LogicException('The fixture changed.'));
        self::assertSame([TransactionKind::CardSettlement->value => 2, TransactionKind::Regular->value => 3], $this->countKinds());

        $import = $this->uploadAndImport(contents: $full);

        self::assertSame([2, 5], [$import->getImportedCount(), $import->getSkippedCount()]);
        self::assertSame([
            TransactionKind::DeferredCardSettlement->value => 1,
            TransactionKind::Regular->value => 3,
            TransactionKind::CardSettlement->value => 1,
            TransactionKind::DeferredCardPurchase->value => 2,
        ], $this->countKinds());
    }

    public function testResumesAnImportWhoseRunDied(): void
    {
        self::mockTime('2026-10-11');
        $import = $this->upload('credit_agricole.csv');
        // The worker died once the import started
        $import->start();
        $this->entityManager->flush();

        $this->handleQueuedMessages();

        $import = $this->reload($import);
        self::assertSame(ImportStatus::Done, $import->getStatus());
        self::assertSame(7, $import->getImportedCount());
    }

    public function testImportsEveryAccountOfAStatement(): void
    {
        self::mockTime('2026-10-11');

        $import = $this->uploadAndImport('revolut.csv');

        self::assertSame(Bank::Revolut, $import->getBank());
        self::assertSame(8, $import->getImportedCount());

        $accounts = $this->entityManager->getRepository(BankAccount::class)->findBy(['owner' => $this->user], ['id' => 'ASC']);
        self::assertSame(
            [['Compte personnel (EUR)', 'EUR'], ['Compte personnel (AUD)', 'AUD'], ['Vacances (EUR)', 'EUR']],
            array_map(static fn (BankAccount $account): array => [$account->getExternalId(), $account->getCurrency()], $accounts),
        );
    }

    public function testFilesTheTransfersOfTheStatement(): void
    {
        self::mockTime('2026-10-11');
        $statement = (string) file_get_contents(self::FIXTURES.'revolut.csv');
        // The other side of the money put on the pocket
        $row = '"14 août 2026","Frais de livraison de carte"';
        $statement = str_replace($row, '"1 août 2026","Transfert vers Vacances",,"-100,00€","1 385,00€","0,00€","0,00€","0,00€",,,,,'."\n".$row, $statement);

        $this->uploadAndImport(contents: $statement);

        $transactions = $this->entityManager->getRepository(Transaction::class);
        foreach (['Transfert vers Vacances', 'Transfert depuis Compte personnel'] as $label) {
            $side = $transactions->findOneBy(['label' => $label]);
            self::assertSame(TransactionKind::InternalTransfer, $side?->getKind(), $label);
            self::assertSame('Transfers', $side->getCategory()?->getName());
        }
        // Received from someone else: earned all the same
        $received = $transactions->findOneBy(['label' => 'Virement de : JANE DOE']);
        self::assertSame(TransactionKind::Regular, $received?->getKind());
        self::assertSame('Transfers', $received->getCategory()?->getName());
    }

    public function testTheSameFileCanOnlyBeUploadedAgainAfterItsImportFailed(): void
    {
        self::mockTime('2026-10-11');
        $this->uploadAndImport('credit_agricole.csv');

        $this->expectException(DuplicateStatementException::class);
        $this->upload('credit_agricole.csv');
    }

    public function testAnUnreadableFileFailsAndCanBeUploadedAgain(): void
    {
        $import = $this->uploadAndImport(contents: "Date,Amount\n2026-09-01,12.00\n");

        self::assertSame(ImportStatus::Failed, $import->getStatus());
        self::assertSame('statement.error.unsupported_bank', $import->getError()?->getMessage());
        self::assertNull($import->getStoredPath());
        self::assertSame(0, $this->entityManager->getRepository(Transaction::class)->count([]));

        self::assertSame(ImportStatus::Pending, $this->upload(contents: "Date,Amount\n2026-09-01,12.00\n")->getStatus());
    }

    public function testDeletesTheFileOnceImported(): void
    {
        $import = $this->upload('credit_agricole.csv');
        $storedFile = self::getContainer()->getParameter('kernel.share_dir').'/statements/'.$import->getStoredPath();
        self::assertFileExists($storedFile);

        $this->handleQueuedMessages();

        self::assertFileDoesNotExist($storedFile);
        self::assertNull($this->reload($import)->getStoredPath());
    }

    public function testPublishesEachStatusChangeToTheOwnerOnly(): void
    {
        $this->uploadAndImport('credit_agricole.csv');

        // Only registered in the test environment, while PHPStan reads the dev container
        $hub = self::getContainer()->get('mercure.test_http_client'); // @phpstan-ignore symfonyContainer.serviceNotFound
        \assert($hub instanceof MockHttpClient);

        // "processing", then "done"
        self::assertSame(2, $hub->getRequestsCount());
    }

    public function testARedeliveredMessageChangesNothing(): void
    {
        self::mockTime('2026-10-11');
        $import = $this->uploadAndImport('credit_agricole.csv');

        $this->handler()(new ImportStatement((int) $import->getId()));

        self::assertSame(7, $this->reload($import)->getImportedCount());
        self::assertSame(7, $this->entityManager->getRepository(Transaction::class)->count([]));
    }

    private function uploadAndImport(?string $fixture = null, string $append = '', ?string $contents = null): StatementImport
    {
        $import = $this->upload($fixture, $append, $contents);
        $this->handleQueuedMessages();

        return $this->reload($import);
    }

    private function upload(?string $fixture = null, string $append = '', ?string $contents = null): StatementImport
    {
        $path = tempnam(sys_get_temp_dir(), 'statement');
        file_put_contents($path, ($contents ?? file_get_contents(self::FIXTURES.$fixture)).$append);

        $uploader = self::getContainer()->get(StatementUploader::class);
        \assert($uploader instanceof StatementUploader);

        // The user is detached once reload() clears the entity manager
        $owner = $this->entityManager->find(User::class, $this->user->getId()) ?? throw new \LogicException('The user is gone.');

        return $uploader->upload($owner, new UploadedFile($path, $fixture ?? 'statement.csv', 'text/csv', test: true));
    }

    private function handleQueuedMessages(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            \assert($message instanceof ImportStatement);
            $this->handler()($message);
        }
        $transport->reset();
    }

    private function handler(): ImportStatementHandler
    {
        $handler = self::getContainer()->get(ImportStatementHandler::class);
        \assert($handler instanceof ImportStatementHandler);

        return $handler;
    }

    private function reload(StatementImport $import): StatementImport
    {
        $this->entityManager->clear();

        return $this->entityManager->find(StatementImport::class, $import->getId()) ?? throw new \LogicException('The import is gone.');
    }

    /**
     * @return array<string, int>
     */
    private function countKinds(): array
    {
        $counts = [];
        foreach ($this->entityManager->getRepository(Transaction::class)->findBy([], ['id' => 'ASC']) as $transaction) {
            $counts[$transaction->getKind()->value] = ($counts[$transaction->getKind()->value] ?? 0) + 1;
        }

        return $counts;
    }
}
