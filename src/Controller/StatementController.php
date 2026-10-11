<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class StatementController extends AbstractController
{
    #[Route('/statements/import', name: 'app_statement_import', methods: ['GET'])]
    public function import(): Response
    {
        return $this->render('statement/import.html.twig');
    }
}
