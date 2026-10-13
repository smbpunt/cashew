<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011221004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The default category a category was created as';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD default_key VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP default_key');
    }
}
