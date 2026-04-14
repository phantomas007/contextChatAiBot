<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'message_for_ai: запросы к DeepSeek и ответы в Telegram (без messages)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE message_for_ai (
                    id                    BIGSERIAL NOT NULL,
                    telegram_user_id      BIGINT NOT NULL,
                    chat_id               BIGINT NOT NULL,
                    message_thread_id     BIGINT DEFAULT NULL,
                    reply_to_message_id   BIGINT NOT NULL,
                    prompt_text           TEXT NOT NULL,
                    response_text         TEXT DEFAULT NULL,
                    error_message         TEXT DEFAULT NULL,
                    created_at            TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    sent_at               TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    PRIMARY KEY(id)
                )
            SQL);
        $this->addSql("COMMENT ON COLUMN message_for_ai.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN message_for_ai.sent_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_message_for_ai_pending_deepseek ON message_for_ai (id) WHERE response_text IS NULL AND error_message IS NULL');
        $this->addSql('CREATE INDEX idx_message_for_ai_pending_send ON message_for_ai (id) WHERE sent_at IS NULL AND (response_text IS NOT NULL OR error_message IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE message_for_ai');
    }
}
