<?php

namespace App\Tests\Recurrence;

use App\Entity\BankAccount;
use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Enum\TransactionKind;
use App\Recurrence\AmountChange;
use App\Recurrence\AmountChangeFinder;
use App\Tests\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AmountChangeFinderTest extends KernelTestCase
{
    use EntityFactoryTrait;

    private User $user;
    private BankAccount $account;
    private StatementImport $import;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
        $this->account = $this->createAccount($this->user);
        $this->import = new StatementImport($this->user, 'october.csv', null, str_repeat('0', 64), new \DateTimeImmutable());
        $this->import->complete(Bank::CreditAgricole, 1, 0, 0, new \DateTimeImmutable());
        $this->entityManager->persist($this->import);
    }

    public function testAfterAFixedPriceAnyChangeIsOne(): void
    {
        $this->createSeries('free mobile', ['2026-07-15' => -1999, '2026-08-15' => -1999, '2026-09-15' => -1999], ['2026-10-15' => -2199]);

        self::assertSame([[-2199, -1999, '2026-09-15']], $this->find());
    }

    public function testAVaryingAmountMayVaryALittle(): void
    {
        $this->createSeries('edf', ['2026-07-05' => -8500, '2026-08-05' => -8720, '2026-09-05' => -8500], ['2026-10-05' => -9100]);
        $this->createSeries('netflix', ['2026-07-20' => -1349, '2026-08-20' => -1349, '2026-09-20' => -1349], ['2026-10-20' => -1349]);
        $this->createSeries('salaire', ['2026-08-28' => 250000, '2026-09-28' => 250000], ['2026-10-28' => 251230]);

        self::assertSame([], $this->find());

        $this->createSeries('loyer', ['2026-08-01' => -90000, '2026-09-01' => -92000], ['2026-10-01' => -105000]);

        self::assertSame([[-105000, -92000, '2026-09-01']], $this->find());
    }

    /**
     * @param array<string, int> $known    by day
     * @param array<string, int> $imported by day
     */
    private function createSeries(string $counterparty, array $known, array $imported): void
    {
        $series = new RecurringSeries($this->account, $counterparty, array_sum($known) > 0, ucwords($counterparty), Periodicity::Monthly, (int) array_last($known), 90, new \DateTimeImmutable((string) array_key_last($imported)));
        $series->confirm(ucwords($counterparty), null);
        $this->entityManager->persist($series);

        foreach ([[$known, null], [$imported, $this->import]] as [$amounts, $import]) {
            foreach ($amounts as $date => $amount) {
                $this->createTransaction($this->account, $date, $counterparty, $amount, TransactionKind::Regular, $import)->attachTo($series);
            }
        }
        $this->entityManager->flush();
    }

    /**
     * @return list<array{int, int, string}> the new amount, the previous one and its day
     */
    private function find(): array
    {
        return array_map(
            static fn (AmountChange $change): array => [$change->transaction->getAmount(), $change->previousAmount, $change->previousDate->format('Y-m-d')],
            self::getContainer()->get(AmountChangeFinder::class)->find([$this->import]),
        );
    }
}
