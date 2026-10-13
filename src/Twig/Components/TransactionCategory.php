<?php

namespace App\Twig\Components;

use App\Category\CategoryChoices;
use App\Entity\Category;
use App\Entity\Counterparty;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Security\OwnerVoter;
use App\Transfer\TransferFiling;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The category of a transaction, for the user to change: its own, over that of its series or its counterparty; or, on
 * the page of one of no series, that of its counterparty, for all of its transactions.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class TransactionCategory extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public Transaction $transaction;

    /**
     * Whether the category is that of its counterparty: asked for, and of no series. Not for money moved between the
     * user's accounts, whose counterparty is the user themselves.
     */
    #[LiveProp]
    public bool $ofCounterparty = false;

    /**
     * Its own category, or that of its counterparty; null for the one it inherits, if any.
     */
    #[LiveProp(writable: true, onUpdated: 'categorize')]
    public ?int $categoryId = null;

    public function __construct(
        private readonly CategoryChoices $categories,
        private readonly TransactionRepository $transactions,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransferFiling $filing,
    ) {
    }

    public function mount(Transaction $transaction, bool $ofCounterparty = false): void
    {
        $this->transaction = $transaction;
        $counterparty = $transaction->getCounterparty();
        $this->ofCounterparty = $ofCounterparty && !$transaction->isRecurring() && !$transaction->isInternalTransfer() && null !== $counterparty;
        $this->categoryId = ($transaction->getCategory() ?? ($this->ofCounterparty ? $counterparty?->getCategory() : null))?->getId();
    }

    public function categorize(): void
    {
        $this->denyAccessUnlessGranted(OwnerVoter::OWNER, $this->transaction);

        $category = $this->categories->find($this->user(), $this->categoryId);
        $counterparty = $this->transaction->getCounterparty();
        if ($this->ofCounterparty && null !== $counterparty) {
            $this->categorizeCounterparty($counterparty, $category);
        } else {
            $this->transaction->categorize($category);
        }
        $this->categoryId = $category?->getId();
        $this->entityManager->flush();
    }

    /**
     * @return list<Category>
     */
    public function getChoices(): array
    {
        return $this->categories->of($this->user());
    }

    /**
     * Its own category gives way to that of its counterparty; so does the one the import filed the others in as
     * transfers.
     */
    private function categorizeCounterparty(Counterparty $counterparty, ?Category $category): void
    {
        $counterparty->categorize($category);
        $this->transaction->categorize(null);

        if (null !== $category) {
            $this->filing->release($this->transactions->findBy(['counterparty' => $counterparty]));
        }
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
