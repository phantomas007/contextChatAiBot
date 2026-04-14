<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260308000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contexts, aggregated_contexts, group_settings, user_group_subscriptions; extend users with has_bot_chat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                ALTER TABLE users ADD COLUMN has_bot_chat BOOLEAN NOT NULL DEFAULT FALSE
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TABLE contexts (
                    id             BIGSERIAL NOT NULL,
                    group_id       BIGINT NOT NULL,
                    summary        TEXT NOT NULL,
                    messages_count INT NOT NULL,
                    period_from    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    period_to      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    created_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY(id),
                    CONSTRAINT fk_contexts_group FOREIGN KEY (group_id) REFERENCES telegram_groups (id)
                )
            SQL);
        $this->addSql('CREATE INDEX idx_contexts_group_created ON contexts (group_id, created_at)');
        $this->addSql("COMMENT ON COLUMN contexts.period_from IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN contexts.period_to IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN contexts.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
                CREATE TABLE aggregated_contexts (
                    id             BIGSERIAL NOT NULL,
                    group_id       BIGINT NOT NULL,
                    summary        TEXT NOT NULL,
                    bricks_count   INT NOT NULL,
                    messages_count INT NOT NULL,
                    period_from    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    period_to      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    settings_type  VARCHAR(20) NOT NULL,
                    created_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY(id),
                    CONSTRAINT fk_aggregated_contexts_group FOREIGN KEY (group_id) REFERENCES telegram_groups (id)
                )
            SQL);
        $this->addSql('CREATE INDEX idx_aggregated_contexts_group_type_created ON aggregated_contexts (group_id, settings_type, created_at)');
        $this->addSql("COMMENT ON COLUMN aggregated_contexts.period_from IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN aggregated_contexts.period_to IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN aggregated_contexts.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
                CREATE TABLE group_settings (
                    group_id         BIGINT NOT NULL,
                    count_threshold  SMALLINT DEFAULT NULL,
                    time_interval    SMALLINT DEFAULT NULL,
                    PRIMARY KEY(group_id),
                    CONSTRAINT fk_group_settings_group FOREIGN KEY (group_id) REFERENCES telegram_groups (id) ON DELETE CASCADE
                )
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TABLE user_group_subscriptions (
                    id                BIGSERIAL NOT NULL,
                    user_id           BIGINT NOT NULL,
                    group_id          BIGINT NOT NULL,
                    count_threshold   SMALLINT DEFAULT NULL,
                    time_interval     SMALLINT DEFAULT NULL,
                    last_delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    created_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY(id),
                    CONSTRAINT user_group_subscription_unique UNIQUE (user_id, group_id),
                    CONSTRAINT fk_ugs_user  FOREIGN KEY (user_id)  REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT fk_ugs_group FOREIGN KEY (group_id) REFERENCES telegram_groups (id) ON DELETE CASCADE
                )
            SQL);
        $this->addSql("COMMENT ON COLUMN user_group_subscriptions.last_delivered_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN user_group_subscriptions.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_group_subscriptions');
        $this->addSql('DROP TABLE group_settings');
        $this->addSql('DROP TABLE aggregated_contexts');
        $this->addSql('DROP TABLE contexts');
        $this->addSql('ALTER TABLE users DROP COLUMN has_bot_chat');
    }
}
