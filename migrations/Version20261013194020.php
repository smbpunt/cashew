<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013194020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The category the user files a counterparty in, for all its transactions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE counterparty ADD category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE counterparty ADD CONSTRAINT FK_9B3DE79C12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9B3DE79C12469DE2 ON counterparty (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE counterparty DROP CONSTRAINT FK_9B3DE79C12469DE2');
        $this->addSql('DROP INDEX IDX_9B3DE79C12469DE2');
        $this->addSql('ALTER TABLE counterparty DROP category_id');
    }
}
