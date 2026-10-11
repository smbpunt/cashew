<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011195454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Whether a user was given the default categories already';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD categories_seeded BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP categories_seeded');
    }
}
