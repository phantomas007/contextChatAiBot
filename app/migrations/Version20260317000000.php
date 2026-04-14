<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename aggregated_contexts to aggregated_contexts_for_group, add sent_at column, update index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aggregated_contexts RENAME TO aggregated_contexts_for_group');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group ADD sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN aggregated_contexts_for_group.sent_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('DROP INDEX idx_aggregated_contexts_group_type_created');
        $this->addSql('CREATE INDEX idx_aggregated_contexts_for_group_type_created ON aggregated_contexts_for_group (group_id, settings_type, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_aggregated_contexts_for_group_type_created');
        $this->addSql('CREATE INDEX idx_aggregated_contexts_group_type_created ON aggregated_contexts_for_group (group_id, settings_type, created_at)');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group DROP COLUMN sent_at');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group RENAME TO aggregated_contexts');
    }
}
