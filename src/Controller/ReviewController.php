<?php

namespace App\Controller;

use App\Entity\User;
use App\Recurrence\AmountChangeFinder;
use App\Repository\CategoryRepository;
use App\Repository\StatementImportRepository;
use App\Repository\TransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ReviewController extends AbstractController
{
    /**
     * What the imports asked for brought, the transfers among it, then the recurring series waiting for a review, and
     * the categories to file them in.
     *
     * @param list<int> $imports the imports just done, if any
     */
    #[Route('/review', name: 'app_review', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser] User $user,
        StatementImportRepository $importRepository,
        TransactionRepository $transactions,
        CategoryRepository $categories,
        AmountChangeFinder $amountChanges,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_INT)] array $imports = [],
    ): Response {
        $imports = $importRepository->findOwned($user, array_values($imports));
        $periods = $transactions->findDateRangesByImport($imports);

        return $this->render('review/index.html.twig', [
            'imports' => $imports,
            'periods' => $periods,
            // From the first day of all the imports to their last
            'period' => [] === $periods ? null : [min(array_column($periods, 0)), max(array_column($periods, 1))],
            'amount_changes' => $amountChanges->find($imports),
            'transfers' => $transactions->findTransfersImportedBy($imports),
            'has_categories' => $categories->hasAny($user),
        ]);
    }
}
