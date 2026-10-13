<?php

namespace App\Tests\Controller;

use App\Tests\EntityFactoryTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StatementControllerTest extends WebTestCase
{
    use EntityFactoryTrait;

    public function testTheImportPageShowsTheDropzone(): void
    {
        $client = self::createClient();
        $this->resetDatabase();
        $user = $this->createUser('alice');

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
