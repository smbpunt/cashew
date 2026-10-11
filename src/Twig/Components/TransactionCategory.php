<?php

namespace App\Twig\Components;

use App\Category\CategoryChoices;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\User;
use App\Security\OwnerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The category of a transaction, for the user to change: its own, over that of its series.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class TransactionCategory extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public Transaction $transaction;

    /**
     * Its own category; null for that of its series, if any.
     */
    #[LiveProp(writable: true, onUpdated: 'categorize')]
    public ?int $categoryId = null;

    public function __construct(
        private readonly CategoryChoices $categories,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function mount(Transaction $transaction): void
    {
        $this->transaction = $transaction;
        $this->categoryId = $transaction->getCategory()?->getId();
    }

    public function categorize(): void
    {
        $this->denyAccessUnlessGranted(OwnerVoter::OWNER, $this->transaction);

        $category = $this->categories->find($this->user(), $this->categoryId);
        $this->transaction->categorize($category);
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

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
