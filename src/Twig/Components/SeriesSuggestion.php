<?php

namespace App\Twig\Components;

use App\Category\CategoryChoices;
use App\Entity\Category;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use App\Form\SeriesReviewType;
use App\Repository\TransactionRepository;
use App\Security\OwnerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A recurring series the detection suggests, for the user to confirm, named and categorised, or to reject.
 *
 * Emits "series:reviewed" up with the series' id once it's done.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class SeriesSuggestion extends AbstractController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public RecurringSeries $series;

    /**
     * @var list<Transaction>|null
     */
    private ?array $occurrences = null;

    public function __construct(
        private readonly CategoryChoices $categories,
        private readonly TransactionRepository $transactions,
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[LiveAction]
    public function confirm(): void
    {
        $this->denyAccessUnlessGranted(OwnerVoter::OWNER, $this->series);
        $this->submitForm();

        /** @var array{name: string, category: ?Category} $data */
        $data = $this->getForm()->getData();
        $this->series->confirm($data['name'], $data['category']);
        $this->entityManager->flush();

        $this->emitUp('series:reviewed', ['id' => $this->series->getId()]);
    }

    #[LiveAction]
    public function reject(): void
    {
        $this->denyAccessUnlessGranted(OwnerVoter::OWNER, $this->series);

        $this->series->reject();
        $this->entityManager->flush();

        $this->emitUp('series:reviewed', ['id' => $this->series->getId()]);
    }

    /**
     * Re-renders the form, for its select to offer the new category: built again with the request, it keeps what the
     * user typed.
     */
    #[LiveListener('category:created')]
    public function onCategoryCreated(): void
    {
    }

    /**
     * Most recent first.
     *
     * @return list<Transaction>
     */
    public function getOccurrences(): array
    {
        return $this->occurrences ??= $this->transactions->findBy(['recurringSeries' => $this->series], ['date' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return FormInterface<array{name: string, category: ?Category}>
     */
    protected function instantiateForm(): FormInterface
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        // Named after the series, for the fields of the suggestions listed together to have ids of their own
        return $this->formFactory->createNamed(\sprintf('series_%d', $this->series->getId()), SeriesReviewType::class, [
            'name' => $this->series->getName(),
            'category' => $this->series->getCategory(),
        ], [
            'categories' => $this->categories->of($user),
        ]);
    }
}
