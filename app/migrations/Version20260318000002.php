<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Заменяет execution_time_us на execution_time_display (формат "1.23 с").
 * Нужна, если была применена старая версия Version20260318000001 с execution_time_us.
 */
final class Version20260318000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace execution_time_us with execution_time_display';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contexts DROP COLUMN IF EXISTS execution_time_us');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group DROP COLUMN IF EXISTS execution_time_us');
        $this->addSql('ALTER TABLE contexts ADD COLUMN IF NOT EXISTS execution_time_display VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group ADD COLUMN IF NOT EXISTS execution_time_display VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contexts DROP COLUMN IF EXISTS execution_time_display');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group DROP COLUMN IF EXISTS execution_time_display');
        $this->addSql('ALTER TABLE contexts ADD execution_time_us INT DEFAULT NULL');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group ADD execution_time_us INT DEFAULT NULL');
    }
}
