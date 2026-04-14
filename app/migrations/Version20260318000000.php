<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add daily_enabled flag to group_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_settings ADD daily_enabled BOOLEAN NOT NULL DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_settings DROP COLUMN daily_enabled');
    }
}
