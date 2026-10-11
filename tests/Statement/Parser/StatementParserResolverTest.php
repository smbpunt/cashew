<?php

namespace App\Tests\Statement\Parser;

use App\Enum\Bank;
use App\Statement\Exception\UnreadableStatementException;
use App\Statement\Parser\CreditAgricoleParser;
use App\Statement\Parser\RevolutParser;
use App\Statement\Parser\StatementParser;
use App\Statement\Parser\StatementParserResolver;
use App\Statement\Reader\CsvReader;
use App\Statement\Reader\FrenchDateParser;
use App\Statement\Reader\MoneyParser;
use App\Statement\StatementSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementParserResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Bank}>
     */
    public static function statements(): iterable
    {
        yield 'Crédit Agricole' => ['credit_agricole.csv', Bank::CreditAgricole];
        yield 'Revolut' => ['revolut.csv', Bank::Revolut];
    }

    #[DataProvider('statements')]
    public function testEachStatementHasExactlyOneParser(string $fixture, Bank $bank): void
    {
        $sample = StatementSample::fromFile(new \SplFileInfo(__DIR__.'/../../Fixtures/Statement/'.$fixture));

        self::assertSame($bank, $this->resolver()->resolve($sample)->bank());
    }

    public function testAnUnknownFormatIsUnreadable(): void
    {
        $this->expectException(UnreadableStatementException::class);

        $this->resolver()->resolve(StatementSample::fromString("Date,Amount\n2026-10-01,12.00\n"));
    }

    public function testTwoParsersSupportingTheSameFileIsABug(): void
    {
        $greedy = $this->createStub(StatementParser::class);
        $greedy->method('supports')->willReturn(true);

        $this->expectException(\LogicException::class);

        new StatementParserResolver([$greedy, $greedy])->resolve(StatementSample::fromString('anything'));
    }

    private function resolver(): StatementParserResolver
    {
        $readers = [new CsvReader(), new MoneyParser(), new FrenchDateParser()];

        return new StatementParserResolver([new CreditAgricoleParser(...$readers), new RevolutParser(...$readers)]);
    }
}
