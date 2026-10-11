<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011171704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Count the transactions a statement import leaves for after the cutoff date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE statement_import ADD after_cutoff_count INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE statement_import DROP after_cutoff_count');
    }
}
