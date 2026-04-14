<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'daily_stats: метрики запросов к ИИ (message_for_ai)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE daily_stats ADD ai_requests_total INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE daily_stats ADD ai_requests_users INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE daily_stats ADD ai_requests_chats INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE daily_stats DROP ai_requests_chats');
        $this->addSql('ALTER TABLE daily_stats DROP ai_requests_users');
        $this->addSql('ALTER TABLE daily_stats DROP ai_requests_total');
    }
}
