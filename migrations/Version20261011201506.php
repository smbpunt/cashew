<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011201506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The icon of a category';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD icon VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP icon');
    }
}
