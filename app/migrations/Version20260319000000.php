<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Оптимизация индексов для запросов в MessageHandler и AggregationService.
 *
 * - messages: замена idx_messages_group_created на idx_messages_group_summarized_created
 *   для findUnsummarized/countUnsummarized
 * - contexts: добавление idx_contexts_group_period_to для findAfter
 * - aggregated_contexts_for_group: добавление idx_aggregated_contexts_group_type_period
 *   для findLastByType/findLastCountBased и idx_aggregated_contexts_unsent для findUnsent
 */
final class Version20260319000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optimize indexes for MessageHandler and AggregationService queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_messages_group_created');
        $this->addSql('CREATE INDEX idx_messages_group_summarized_created ON messages (group_id, summarized_at, created_at)');

        $this->addSql('CREATE INDEX idx_contexts_group_period_to ON contexts (group_id, period_to)');

        $this->addSql('CREATE INDEX idx_aggregated_contexts_group_type_period ON aggregated_contexts_for_group (group_id, settings_type, period_to)');
        $this->addSql('CREATE INDEX idx_aggregated_contexts_unsent ON aggregated_contexts_for_group (sent_at, dispatched_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_messages_group_summarized_created');
        $this->addSql('CREATE INDEX idx_messages_group_created ON messages (group_id, created_at)');

        $this->addSql('DROP INDEX IF EXISTS idx_contexts_group_period_to');

        $this->addSql('DROP INDEX IF EXISTS idx_aggregated_contexts_group_type_period');
        $this->addSql('DROP INDEX IF EXISTS idx_aggregated_contexts_unsent');
    }
}
