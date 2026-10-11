<?php

namespace App\Twig\Components;

use App\Entity\Category;
use App\Entity\User;
use App\Form\CategoryType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Creates a category, top or within another, without leaving the page.
 *
 * Emits "category:created" with its id, for the selects and lists of categories to show it.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class CategoryForm extends AbstractController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();

        $category = $this->getForm()->getData();
        \assert($category instanceof Category);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $this->resetForm();
        $this->emit('category:created', ['id' => $category->getId()]);
    }

    /**
     * Created elsewhere on the page: the select of parents offers it.
     */
    #[LiveListener('category:created')]
    public function onCategoryCreated(): void
    {
    }

    /**
     * Picked in the IconPicker within: the form takes it.
     */
    #[LiveListener('icon:picked')]
    public function onIconPicked(#[LiveArg] ?string $icon = null): void
    {
        $this->formValues['icon'] = (string) $icon;
    }

    /**
     * @return FormInterface<Category>
     */
    protected function instantiateForm(): FormInterface
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->createForm(CategoryType::class, options: [
            'owner' => $user,
            // Submitted through a live action, which UX Live Component protects already
            'csrf_protection' => false,
        ]);
    }
}
