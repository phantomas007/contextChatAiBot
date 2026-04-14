<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'message_for_ai: response_clean, response_tldr (форматирование ответа ИИ)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_for_ai ADD response_clean TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_for_ai ADD response_tldr TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_for_ai DROP response_clean');
        $this->addSql('ALTER TABLE message_for_ai DROP response_tldr');
    }
}
