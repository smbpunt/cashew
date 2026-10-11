<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011181635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store why a statement import failed as a translation key and its parameters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE statement_import ADD error_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE statement_import ADD error_data JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE statement_import ALTER error_data DROP DEFAULT');
        // The English messages of past failures can't be told apart reliably: they become the generic one
        $this->addSql('UPDATE statement_import SET error_key = \'statement.error.unexpected\' WHERE error IS NOT NULL');
        $this->addSql('ALTER TABLE statement_import DROP error');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE statement_import ADD error TEXT DEFAULT NULL');
        $this->addSql('UPDATE statement_import SET error = error_key WHERE error_key IS NOT NULL');
        $this->addSql('ALTER TABLE statement_import DROP error_key');
        $this->addSql('ALTER TABLE statement_import DROP error_data');
    }
}
