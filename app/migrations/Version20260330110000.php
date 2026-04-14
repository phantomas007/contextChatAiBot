<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'daily_stats: dispatched в группу; ЛС — dm_deliveries_* вместо подписок';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE daily_stats ADD deliveries_group_dispatched_daily INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE daily_stats ADD deliveries_group_dispatched_custom INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE daily_stats RENAME COLUMN deliveries_dm_daily TO dm_deliveries_sent');
        $this->addSql('ALTER TABLE daily_stats RENAME COLUMN deliveries_dm_count TO dm_deliveries_skipped');
        $this->addSql('ALTER TABLE daily_stats RENAME COLUMN deliveries_dm_time TO dm_deliveries_queued');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE daily_stats RENAME COLUMN dm_deliveries_queued TO deliveries_dm_time');
        $this->addSql('ALTER TABLE daily_stats RENAME COLUMN dm_deliveries_skipped TO deliveries_dm_count');
        $this->addSql('ALTER TABLE daily_stats RENAME COLUMN dm_deliveries_sent TO deliveries_dm_daily');
        $this->addSql('ALTER TABLE daily_stats DROP deliveries_group_dispatched_custom');
        $this->addSql('ALTER TABLE daily_stats DROP deliveries_group_dispatched_daily');
    }
}
