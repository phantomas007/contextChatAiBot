<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260308000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add daily_stats and delivery_errors tables for usage statistics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE daily_stats (
                    id              SERIAL NOT NULL,
                    stat_date       DATE NOT NULL,
                    messages_total  INT NOT NULL DEFAULT 0,
                    active_groups   INT NOT NULL DEFAULT 0,
                    active_users    INT NOT NULL DEFAULT 0,
                    new_users       INT NOT NULL DEFAULT 0,
                    total_subs      INT NOT NULL DEFAULT 0,
                    bricks_generated        INT NOT NULL DEFAULT 0,
                    aggregations_generated  INT NOT NULL DEFAULT 0,
                    deliveries_group_daily  INT NOT NULL DEFAULT 0,
                    deliveries_group_custom INT NOT NULL DEFAULT 0,
                    deliveries_dm_daily     INT NOT NULL DEFAULT 0,
                    deliveries_dm_count     INT NOT NULL DEFAULT 0,
                    deliveries_dm_time      INT NOT NULL DEFAULT 0,
                    errors_ollama           INT NOT NULL DEFAULT 0,
                    errors_telegram         INT NOT NULL DEFAULT 0,
                    created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                    PRIMARY KEY (id),
                    UNIQUE (stat_date)
                )
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TABLE delivery_errors (
                    id          BIGSERIAL NOT NULL,
                    error_type  VARCHAR(20) NOT NULL,
                    error_code  INT DEFAULT NULL,
                    context     JSONB NOT NULL DEFAULT '{}',
                    occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                    PRIMARY KEY (id)
                )
            SQL);

        $this->addSql(<<<'SQL'
                CREATE INDEX idx_delivery_errors_type_date
                    ON delivery_errors (error_type, occurred_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS delivery_errors');
        $this->addSql('DROP TABLE IF EXISTS daily_stats');
    }
}
