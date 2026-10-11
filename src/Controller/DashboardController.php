<?php

namespace App\Controller;

use App\Entity\User;
use App\Report\MonthlyReportBuilder;
use App\Repository\StatementImportRepository;
use App\Statement\Import\CutoffPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    /**
     * A month of income and spending, the last one with transactions unless another is asked for.
     */
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser] User $user,
        MonthlyReportBuilder $reports,
        StatementImportRepository $imports,
        CutoffPolicy $cutoff,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^\d{4}-(0[1-9]|1[0-2])$/'])] ?string $month = null,
    ): Response {
        try {
            $report = $reports->build($user, null === $month ? null : new \DateTimeImmutable($month.'-01'));
        } catch (\OutOfRangeException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }

        return $this->render('dashboard/index.html.twig', [
            'report' => $report,
            'importing' => [] !== $imports->findInProgress($user),
            // Imported, yet nothing to show: the statements only held the month in progress
            'waiting_for_month_end' => null === $report && $imports->hasDone($user),
            'next_import_date' => $cutoff->nextImportDate(),
        ]);
    }
}
