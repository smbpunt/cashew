<?php

namespace App\Twig\Components;

use App\Entity\Nameable;
use App\Entity\Transaction;
use App\Form\RenameType;
use App\Repository\TransactionRepository;
use App\Security\OwnerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The name of a transaction, for the user to change: that of its series once they confirmed it, or else that of its
 * counterparty, for all its transactions that aren't recurring, those to come included.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class TransactionName extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Transaction $transaction;

    #[LiveProp]
    public bool $editing = false;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[LiveAction]
    public function edit(): void
    {
        $this->editing = true;
    }

    #[LiveAction]
    public function cancel(): void
    {
        $this->editing = false;
        $this->resetForm();
    }

    #[LiveAction]
    public function rename(): void
    {
        $this->denyAccessUnlessGranted(OwnerVoter::OWNER, $this->transaction);
        $holder = $this->getNameHolder() ?? throw $this->createNotFoundException('Its label only holds references: there is nothing to name.');
        $this->submitForm();

        /** @var array{name: ?string} $data */
        $data = $this->getForm()->getData();
        $holder->rename($data['name']);
        $this->entityManager->flush();

        $this->editing = false;
        $this->resetForm();
    }

    /**
     * What naming it names: its series once the user confirmed it, or else its counterparty; null when its label only
     * holds references.
     */
    public function getNameHolder(): ?Nameable
    {
        return $this->transaction->isRecurring() ? $this->transaction->getRecurringSeries() : $this->transaction->getCounterparty();
    }

    /**
     * How many transactions go by the name, it among them.
     */
    public function getCount(): int
    {
        $series = $this->transaction->getRecurringSeries();
        if ($this->transaction->isRecurring() && null !== $series) {
            return $this->transactions->count(['recurringSeries' => $series]);
        }

        $counterparty = $this->transaction->getCounterparty();

        return null === $counterparty ? 1 : $this->transactions->countNamedAfter($counterparty);
    }

    /**
     * @return FormInterface<array{name: ?string}>
     */
    protected function instantiateForm(): FormInterface
    {
        // What it goes by, or else a name made from its counterparty to start from
        return $this->formFactory->create(RenameType::class, [
            'name' => $this->getNameHolder()?->getName(),
        ]);
    }
}
