<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'message_for_ai: удаление response_clean (не храним)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_for_ai DROP COLUMN IF EXISTS response_clean');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_for_ai ADD response_clean TEXT DEFAULT NULL');
    }
}
