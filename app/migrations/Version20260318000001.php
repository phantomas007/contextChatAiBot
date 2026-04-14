<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add execution_time_display to contexts and aggregated_contexts_for_group';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contexts ADD execution_time_display VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group ADD execution_time_display VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contexts DROP COLUMN execution_time_display');
        $this->addSql('ALTER TABLE aggregated_contexts_for_group DROP COLUMN execution_time_display');
    }
}
