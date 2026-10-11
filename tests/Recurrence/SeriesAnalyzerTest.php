<?php

namespace App\Tests\Recurrence;

use App\Entity\BankAccount;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Enum\TransactionKind;
use App\Recurrence\SeriesAnalyzer;
use PHPUnit\Framework\TestCase;

final class SeriesAnalyzerTest extends TestCase
{
    public function testAFixedMonthlyDebitIsSure(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions(['2026-06-15' => -1999, '2026-07-15' => -1999, '2026-08-17' => -1999, '2026-09-15' => -1999]));

        self::assertNotNull($analysis);
        self::assertSame(Periodicity::Monthly, $analysis->periodicity);
        self::assertSame(-1999, $analysis->typicalAmount);
        self::assertSame(90, $analysis->confidence);
        self::assertSame('2026-09-15', $analysis->lastSeenOn->format('Y-m-d'));
    }

    public function testASalaryVaryingALittleIsSureToo(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions(['2026-06-26' => 250000, '2026-07-28' => 251230, '2026-08-28' => 249800]));

        self::assertSame(Periodicity::Monthly, $analysis?->periodicity);
        self::assertSame(250000, $analysis->typicalAmount);
        self::assertSame(85, $analysis->confidence);
    }

    public function testTwoLinesOfTheSameDayAreOneOccurrence(): void
    {
        $transactions = $this->transactions(['2026-07-15' => -1999, '2026-08-15' => -1999, '2026-09-15' => -1999]);
        $transactions[] = $this->transaction('2026-09-15', -999);

        $analysis = new SeriesAnalyzer()->analyze($transactions);

        self::assertSame(Periodicity::Monthly, $analysis?->periodicity);
        self::assertSame(-1999, $analysis->typicalAmount);
        // Regular, three occurrences, the same price from one to the next
        self::assertSame(85, $analysis->confidence);
    }

    public function testTheTypicalAmountFollowsTheLatestPrice(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions([
            '2026-03-20' => -1349, '2026-04-20' => -1349, '2026-05-20' => -1349, '2026-06-20' => -1349,
            '2026-07-20' => -1549, '2026-08-20' => -1549, '2026-09-20' => -1549,
        ]));

        self::assertSame(-1549, $analysis?->typicalAmount);
        // A new price once: stable but for one occurrence out of six
        self::assertSame(88, $analysis->confidence);
    }

    public function testAMissedMonthIsStillMonthly(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions(['2026-06-15' => -1999, '2026-08-15' => -1999, '2026-09-15' => -1999]));

        self::assertSame(Periodicity::Monthly, $analysis?->periodicity);
        self::assertSame(85, $analysis->confidence);
    }

    public function testEveryOtherMonthIsBimonthlyNotMonthly(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions(['2026-03-10' => -4500, '2026-05-10' => -4500, '2026-07-10' => -4500, '2026-09-10' => -4500]));

        self::assertSame(Periodicity::Bimonthly, $analysis?->periodicity);
        self::assertSame(90, $analysis->confidence);
    }

    public function testEveryQuarterIsQuarterlyNotMonthly(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions(['2026-01-05' => -3000, '2026-04-05' => -3000, '2026-07-06' => -3000]));

        self::assertSame(Periodicity::Quarterly, $analysis?->periodicity);
    }

    public function testTwiceAYearAtTheSameAmountIsSemiannual(): void
    {
        $analyzer = new SeriesAnalyzer();

        self::assertSame(Periodicity::Semiannual, $analyzer->analyze($this->transactions(['2026-03-01' => -24000, '2026-09-01' => -24000]))?->periodicity);
        // Twice only, at two amounts: a coincidence, maybe
        self::assertNull($analyzer->analyze($this->transactions(['2026-03-01' => -24000, '2026-09-01' => -31000])));
    }

    public function testTheShoppingOfEverySaturdayIsNotSure(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions([
            '2026-08-01' => -5230, '2026-08-08' => -8750, '2026-08-15' => -10980, '2026-08-22' => -6340,
            '2026-08-29' => -9500, '2026-09-05' => -7120, '2026-09-12' => -7480, '2026-09-19' => -10280,
        ]));

        self::assertSame(Periodicity::Weekly, $analysis?->periodicity);
        self::assertLessThan(60, $analysis->confidence);
    }

    public function testTheShoppingIsNotSure(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions([
            '2026-06-03' => -4312, '2026-06-10' => -8750, '2026-06-19' => -1230, '2026-06-24' => -6500,
            '2026-07-01' => -3145, '2026-07-06' => -10280, '2026-07-15' => -5420,
        ]));

        self::assertSame(Periodicity::Weekly, $analysis?->periodicity);
        self::assertLessThan(30, $analysis->confidence);
    }

    public function testAVariableBillOfARegularDayIsSureAfterAWhile(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions([
            '2026-04-08' => -6420, '2026-05-08' => -7010, '2026-06-08' => -5890, '2026-07-08' => -6230, '2026-08-08' => -6600, '2026-09-08' => -7450,
        ]));

        self::assertSame(Periodicity::Monthly, $analysis?->periodicity);
        self::assertGreaterThanOrEqual(60, $analysis->confidence);
    }

    public function testNoPeriodicityNoAnalysis(): void
    {
        $analyzer = new SeriesAnalyzer();

        self::assertNull($analyzer->analyze($this->transactions(['2026-06-03' => -4312, '2026-06-20' => -8750, '2026-07-02' => -1230])));
        // Monthly needs a third occurrence
        self::assertNull($analyzer->analyze($this->transactions(['2026-08-15' => -1999, '2026-09-15' => -1999])));
        self::assertNull($analyzer->analyze($this->transactions(['2026-09-15' => -1999])));
    }

    public function testTwoYearsInARowAreYearly(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions(['2025-03-02' => -4900, '2026-03-01' => -4900]));

        self::assertSame(Periodicity::Yearly, $analysis?->periodicity);
    }

    public function testASeriesIsCurrentUntilTwoPeriodsWentByWithoutIt(): void
    {
        $analysis = new SeriesAnalyzer()->analyze($this->transactions(['2026-04-15' => -1999, '2026-05-15' => -1999, '2026-06-15' => -1999]));
        self::assertNotNull($analysis);

        self::assertTrue($analysis->isCurrent(new \DateTimeImmutable('2026-08-14')));
        self::assertFalse($analysis->isCurrent(new \DateTimeImmutable('2026-09-30')));
    }

    /**
     * @param array<string, int> $amounts by day
     *
     * @return list<Transaction>
     */
    private function transactions(array $amounts): array
    {
        return array_map($this->transaction(...), array_keys($amounts), array_values($amounts));
    }

    private function transaction(string $date, int $amount): Transaction
    {
        $account = new BankAccount(new User(), Bank::CreditAgricole, '123', 'Compte', 'EUR');

        return new Transaction($account, null, new \DateTimeImmutable($date), 'FREE MOBILE', $amount, 'EUR', TransactionKind::Regular, null, $date.$amount);
    }
}
