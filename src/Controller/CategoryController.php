<?php

namespace App\Controller;

use App\Category\CategorySeeder;
use App\Entity\Category;
use App\Entity\User;
use App\Form\CategoryType;
use App\Report\CategoryHistoryBuilder;
use App\Repository\CategoryRepository;
use App\Security\OwnerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/categories')]
final class CategoryController extends AbstractController
{
    /**
     * Where the seeding may send the user back to.
     */
    private const array SEED_REDIRECTS = ['app_categories', 'app_review'];

    #[Route('', name: 'app_categories', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, CategoryRepository $categories): Response
    {
        return $this->render('category/index.html.twig', [
            'has_categories' => $categories->hasAny($user),
        ]);
    }

    /**
     * A category's transactions month by month, those of its subcategories included.
     */
    #[Route('/{id}', name: 'app_category_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(OwnerVoter::OWNER, 'category')]
    public function show(
        Category $category,
        CategoryHistoryBuilder $histories,
        #[ValueResolver('month')] ?\DateTimeImmutable $month = null,
    ): Response {
        return $this->render('category/show.html.twig', [
            'history' => $histories->build($category, $month),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_category_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted(OwnerVoter::OWNER, 'category')]
    public function edit(#[CurrentUser] User $user, Category $category, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CategoryType::class, $category, ['owner' => $user]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_categories', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('category/edit.html.twig', [
            'category' => $category,
            'form' => $form,
        ]);
    }

    /**
     * Its subcategories go with it; its transactions and series lose it.
     */
    #[Route('/{id}/delete', name: 'app_category_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(OwnerVoter::OWNER, 'category')]
    #[IsCsrfTokenValid(new Expression('"delete-category-" ~ args["category"].getId()'))]
    public function delete(Category $category, EntityManagerInterface $entityManager): Response
    {
        $entityManager->remove($category);
        $entityManager->flush();

        return $this->redirectToRoute('app_categories', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * A first set of categories, for a user without any.
     */
    #[Route('/seed', name: 'app_category_seed', methods: ['POST'])]
    #[IsCsrfTokenValid('seed-categories')]
    public function seed(#[CurrentUser] User $user, Request $request, CategoryRepository $categories, CategorySeeder $seeder): Response
    {
        if (!$categories->hasAny($user)) {
            $seeder->seed($user);
        }

        $redirect = $request->getPayload()->getString('redirect');

        return $this->redirectToRoute(\in_array($redirect, self::SEED_REDIRECTS, true) ? $redirect : 'app_categories', status: Response::HTTP_SEE_OTHER);
    }
}
