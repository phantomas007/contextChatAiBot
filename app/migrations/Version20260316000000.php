<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add model column to contexts table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contexts ADD model VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contexts DROP COLUMN model');
    }
}
