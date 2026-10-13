<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011215319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The system categories, and the contract of a recurring series';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD role VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CATEGORY_ROLE ON category (owner_id, role)');
        $this->addSql('DROP INDEX uniq_recurring_series_counterparty');
        $this->addSql('ALTER TABLE recurring_series ADD reference VARCHAR(64) DEFAULT \'\' NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RECURRING_SERIES_COUNTERPARTY ON recurring_series (account_id, counterparty, reference, income)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_CATEGORY_ROLE');
        $this->addSql('ALTER TABLE category DROP role');
        $this->addSql('DROP INDEX UNIQ_RECURRING_SERIES_COUNTERPARTY');
        $this->addSql('ALTER TABLE recurring_series DROP reference');
        $this->addSql('CREATE UNIQUE INDEX uniq_recurring_series_counterparty ON recurring_series (account_id, counterparty, income)');
    }
}
