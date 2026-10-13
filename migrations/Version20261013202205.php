<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013202205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The card settlements imported without their card statement, regular until now';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE bank_transaction SET kind = 'card_settlement'
            WHERE kind = 'regular'
              AND label LIKE 'Prélèvement carte%'
              AND account_id IN (SELECT id FROM bank_account WHERE bank = 'credit_agricole')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE bank_transaction SET kind = 'regular' WHERE kind = 'card_settlement'");
    }
}
