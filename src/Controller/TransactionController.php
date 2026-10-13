<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Report\CounterpartyHistoryBuilder;
use App\Security\OwnerVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/transactions')]
final class TransactionController extends AbstractController
{
    /**
     * A transaction among the others of its counterparty: how much, how often, since when.
     */
    #[Route('/{id}', name: 'app_transaction_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(OwnerVoter::OWNER, 'transaction')]
    public function show(Transaction $transaction, CounterpartyHistoryBuilder $histories): Response
    {
        return $this->render('transaction/show.html.twig', [
            'history' => $histories->build($transaction),
        ]);
    }
}
