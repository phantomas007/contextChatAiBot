<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial tables: users, telegram_groups, user_groups, messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE users (
                    id         BIGSERIAL NOT NULL,
                    telegram_user_id BIGINT NOT NULL,
                    username   VARCHAR(255) DEFAULT NULL,
                    first_name VARCHAR(255) DEFAULT NULL,
                    registered_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY(id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX users_telegram_user_id_unique ON users (telegram_user_id)');
        $this->addSql("COMMENT ON COLUMN users.registered_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
                CREATE TABLE telegram_groups (
                    id               BIGSERIAL NOT NULL,
                    telegram_chat_id BIGINT NOT NULL,
                    title            VARCHAR(255) DEFAULT NULL,
                    bot_joined_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
                    PRIMARY KEY(id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX telegram_groups_chat_id_unique ON telegram_groups (telegram_chat_id)');
        $this->addSql("COMMENT ON COLUMN telegram_groups.bot_joined_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
                CREATE TABLE user_groups (
                    id        BIGSERIAL NOT NULL,
                    user_id   BIGINT NOT NULL,
                    group_id  BIGINT NOT NULL,
                    joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY(id),
                    CONSTRAINT user_group_unique UNIQUE (user_id, group_id),
                    CONSTRAINT fk_user_groups_user  FOREIGN KEY (user_id)  REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT fk_user_groups_group FOREIGN KEY (group_id) REFERENCES telegram_groups (id) ON DELETE CASCADE
                )
            SQL);
        $this->addSql("COMMENT ON COLUMN user_groups.joined_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
                CREATE TABLE messages (
                    id                  BIGSERIAL NOT NULL,
                    group_id            BIGINT NOT NULL,
                    telegram_message_id BIGINT NOT NULL,
                    telegram_user_id    BIGINT DEFAULT NULL,
                    username            VARCHAR(255) DEFAULT NULL,
                    text                TEXT NOT NULL,
                    created_at          TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    summarized_at       TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    PRIMARY KEY(id),
                    CONSTRAINT messages_unique UNIQUE (telegram_message_id, group_id),
                    CONSTRAINT fk_messages_group FOREIGN KEY (group_id) REFERENCES telegram_groups (id)
                )
            SQL);
        $this->addSql('CREATE INDEX idx_messages_group_created ON messages (group_id, created_at)');
        $this->addSql("COMMENT ON COLUMN messages.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN messages.summarized_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messages');
        $this->addSql('DROP TABLE user_groups');
        $this->addSql('DROP TABLE telegram_groups');
        $this->addSql('DROP TABLE users');
    }
}
