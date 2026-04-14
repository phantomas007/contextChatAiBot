<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260411120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Реактивация ЛС с ИИ: шаблоны текста и users.last_ai_reengage_sent_at';
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);

        $lines = explode("\n", $text);

        $lines = array_map(static fn ($line) => ltrim($line), $lines);

        return trim(implode("\n", $lines));
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE ai_private_reengage_template (
                    id         BIGSERIAL NOT NULL,
                    body       TEXT NOT NULL,
                    is_active  BOOLEAN DEFAULT true NOT NULL,
                    PRIMARY KEY(id)
                )
            SQL);

        $bodies = [
            '<b>Можно снова задать вопрос ИИ</b>
            
            <i>Если появилась задача или идея — просто напишите её здесь.</i>
            
            🚀 Задать вопрос: /ask_ai',

            '<b>ИИ всё ещё на связи</b>
            
            Нужно:
            • разобрать текст
            • получить идею
            • задать технический вопрос
            
            <i>Просто включите режим.</i>
            
            🚀 Задать вопрос: /ask_ai',

            '<b>Есть вопрос?</b>
            
            <i>Опишите задачу одним сообщением — ИИ попробует помочь.</i>
            
            🚀 Задать вопрос: /ask_ai',

            '<b>Попробуйте задать новый вопрос</b>
            
            <i>Чем точнее сформулирована задача,
            тем полезнее будет ответ.</i>
            
            🚀 Задать вопрос: /ask_ai',

            '<b>ИИ может помочь с задачой</b>
            
            Например:
            • идеи
            • объяснение темы
            • анализ текста
            
            🚀 Задать вопрос: /ask_ai',

            '<b>Вернуться к диалогу с ИИ</b>
            
            <i>Нажмите команду и задайте любой вопрос.</i>
            
            🚀 Задать вопрос: /ask_ai',

            '<b>Напоминание</b>
            
            <i>Диалог с ИИ в личных сообщениях по-прежнему доступен.</i>
            
            🚀 Задать вопрос: /ask_ai',

            '<b>Нужен быстрый ответ?</b>
            
            <i>Опишите вопрос или проблему —
            ИИ попробует помочь.</i>
            
            🚀 Задать вопрос: /ask_ai',

            '<b>Можно продолжить диалог</b>
            
            <i>Напишите вопрос обычным сообщением
            после включения режима.</i>
            
            🚀 Задать вопрос: /ask_ai',

            '<b>ИИ готов помочь</b>
            
            <i>Иногда один хороший вопрос
            экономит много времени.</i>
            
            🚀 Задать вопрос: /ask_ai',
        ];

        foreach ($bodies as $i => $body) {
            $body = $this->normalizeText($body);

            $this->addSql(
                'INSERT INTO ai_private_reengage_template (body, is_active) VALUES (?, true)',
                [$body],
            );
        }

        $this->addSql('ALTER TABLE users ADD last_ai_reengage_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN users.last_ai_reengage_sent_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP last_ai_reengage_sent_at');
        $this->addSql('DROP TABLE ai_private_reengage_template');
    }
}
