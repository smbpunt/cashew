<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StatementControllerTest extends WebTestCase
{
    public function testTheImportPageShowsTheDropzone(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.User::class)->execute();
        $user = new User()->setLogin('alice')->setPassword('not-a-real-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/statements/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Import statements');
        self::assertSelectorExists('[data-controller~="live"] input[type="file"][name="statement_upload[statements][]"][multiple]');
        self::assertSelectorExists('[data-controller~="symfony--ux-dropzone--dropzone"]');
    }

    public function testTheImportPageRequiresALoggedInUser(): void
    {
        $client = self::createClient();
        $client->request('GET', '/statements/import');

        self::assertResponseRedirects('/login');
    }
}
