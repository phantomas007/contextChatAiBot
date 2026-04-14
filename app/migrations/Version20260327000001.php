<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'aggregated_context_dm_deliveries: двухфазная рассылка агрегата в ЛС (queued → sent/skipped)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE aggregated_context_dm_deliveries (
                    id                           BIGSERIAL NOT NULL,
                    aggregated_group_context_id  BIGINT NOT NULL,
                    user_id                      BIGINT NOT NULL,
                    queued_at                    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    sent_at                      TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    skipped_at                   TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    PRIMARY KEY(id),
                    CONSTRAINT uq_agg_dm_delivery_agg_user UNIQUE (aggregated_group_context_id, user_id),
                    CONSTRAINT fk_agg_dm_delivery_agg FOREIGN KEY (aggregated_group_context_id)
                        REFERENCES aggregated_contexts_for_group (id) ON DELETE CASCADE,
                    CONSTRAINT fk_agg_dm_delivery_user FOREIGN KEY (user_id)
                        REFERENCES users (id) ON DELETE CASCADE
                )
            SQL);
        $this->addSql("COMMENT ON COLUMN aggregated_context_dm_deliveries.queued_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN aggregated_context_dm_deliveries.sent_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN aggregated_context_dm_deliveries.skipped_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_agg_dm_delivery_pending ON aggregated_context_dm_deliveries (queued_at) WHERE sent_at IS NULL AND skipped_at IS NULL');
        $this->addSql('CREATE INDEX idx_agg_dm_delivery_user ON aggregated_context_dm_deliveries (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE aggregated_context_dm_deliveries');
    }
}
