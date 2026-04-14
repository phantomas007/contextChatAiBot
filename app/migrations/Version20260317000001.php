<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dispatched_at to aggregated_contexts_for_group to prevent duplicate queue dispatching';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aggregated_contexts_for_group ADD dispatched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN aggregated_contexts_for_group.dispatched_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aggregated_contexts_for_group DROP COLUMN dispatched_at');
    }
}
