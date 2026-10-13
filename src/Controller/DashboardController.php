<?php

namespace App\Controller;

use App\Dashboard\TransactionListPreferences;
use App\Dashboard\TransactionListQuery;
use App\Entity\User;
use App\Enum\TransactionFilter;
use App\Report\MonthlyReportBuilder;
use App\Repository\StatementImportRepository;
use App\Statement\Import\CutoffPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    /**
     * A month of income and spending, the last one with transactions unless another is asked for.
     *
     * The transactions are listed in the mode and order asked for, remembered for the next visits; simply, the most
     * recent first, by default. Only those the user didn't identify yet on demand.
     */
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser] User $user,
        MonthlyReportBuilder $reports,
        StatementImportRepository $imports,
        CutoffPolicy $cutoff,
        TransactionListPreferences $preferences,
        #[ValueResolver('month')] ?\DateTimeImmutable $month = null,
        #[MapQueryString] TransactionListQuery $query = new TransactionListQuery(),
    ): Response {
        $list = $preferences->resolve($query);

        try {
            $report = $reports->build($user, $month);
        } catch (\OutOfRangeException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }

        return $this->render('dashboard/index.html.twig', [
            'report' => $report,
            'list' => $list,
            'transactions' => $list->apply($report->transactions ?? []),
            'unidentified' => \count(TransactionFilter::Unidentified->filter($report->transactions ?? [])),
            'importing' => [] !== $imports->findInProgress($user),
            // Imported, yet nothing to show: the statements only held the month in progress
            'waiting_for_month_end' => null === $report && $imports->hasDone($user),
            'next_import_date' => $cutoff->nextImportDate(),
        ]);
    }
}
