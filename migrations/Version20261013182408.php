<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013182408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When the user said a recurring series ended, or still goes on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_series ADD ended_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE recurring_series ADD still_active_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_series DROP ended_on');
        $this->addSql('ALTER TABLE recurring_series DROP still_active_on');
    }
}
