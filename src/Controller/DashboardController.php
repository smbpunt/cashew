<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\TransactionListMode;
use App\Report\MonthlyReportBuilder;
use App\Repository\StatementImportRepository;
use App\Statement\Import\CutoffPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    private const string MODE_SESSION_KEY = 'dashboard_transaction_list_mode';

    /**
     * A month of income and spending, the last one with transactions unless another is asked for.
     *
     * The transactions are listed in the mode asked for, remembered for the next visits; simple by default.
     */
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser] User $user,
        Request $request,
        MonthlyReportBuilder $reports,
        StatementImportRepository $imports,
        CutoffPolicy $cutoff,
        #[ValueResolver('month')] ?\DateTimeImmutable $month = null,
        #[MapQueryParameter] ?TransactionListMode $mode = null,
    ): Response {
        $session = $request->getSession();
        if (null === $mode) {
            $mode = TransactionListMode::tryFrom($session->get(self::MODE_SESSION_KEY, '')) ?? TransactionListMode::Simple;
        } else {
            $session->set(self::MODE_SESSION_KEY, $mode->value);
        }

        try {
            $report = $reports->build($user, $month);
        } catch (\OutOfRangeException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }

        return $this->render('dashboard/index.html.twig', [
            'report' => $report,
            'mode' => $mode,
            'importing' => [] !== $imports->findInProgress($user),
            // Imported, yet nothing to show: the statements only held the month in progress
            'waiting_for_month_end' => null === $report && $imports->hasDone($user),
            'next_import_date' => $cutoff->nextImportDate(),
        ]);
    }
}
