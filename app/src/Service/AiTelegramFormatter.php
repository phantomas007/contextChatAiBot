<?php

declare(strict_types=1);

namespace App\Service;

final class AiTelegramFormatter
{
    private const TELEGRAM_LIMIT = 4000;

    /**
     * @param string $rawText ответ от AI в формате:
     * ---TLDR---
     * короткий ответ
     * ---FULL---
     * развёрнутый ответ
     * @param int  $usedToday   уже использовано сегодня (UTC), включая текущий запрос
     * @param int  $maxPerDay   лимит в сутки
     * @param bool $isPrivate   личка с ботом — в подвале подсказка /stop_ask_ai
     * @param bool $isGroupChat группа — в шапке формулировка «на группу»
     *
     * @return array{message: string, clean: string, tldr: string, full: string}
     */
    public function format(string $rawText, int $usedToday, int $maxPerDay, bool $isPrivate, bool $isGroupChat = false): array
    {
        // Разделяем TLDR и FULL
        [$tldrRaw, $fullRaw] = $this->splitTldrFull($rawText);

        $cleanFull = $this->cleanup($fullRaw);
        $fullText = $this->formatFullText($cleanFull);

        $tldr = $this->cleanup($tldrRaw); // TL;DR без эмодзи

        $message = $this->makeHeader($usedToday, $maxPerDay, $isGroupChat)."\n\n".
                   ($tldr ? "💬 Коротко:\n".$tldr."\n\n" : '').
                   "📖 Подробнее:\n".$fullText."\n\n".
                   $this->makeFooter($isPrivate);

        // Ограничение Telegram
        if (mb_strlen($message) > self::TELEGRAM_LIMIT) {
            $message = mb_substr($message, 0, self::TELEGRAM_LIMIT - 50)."\n\n…";
        }

        return [
            'message' => $message,
            'clean' => $cleanFull,
            'tldr' => $tldr,
            'full' => $fullText,
        ];
    }

    /**
     * @return array{string, string} [tldr, full]
     */
    private function splitTldrFull(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $tldr = '';
        $full = $text;

        if (preg_match('/---TLDR---\s*(.*?)\s*---FULL---/s', $text, $matches)) {
            $tldr = trim($matches[1]);
            $full = trim(str_replace($matches[0], '', $text));
        }

        return [$tldr, $full];
    }

    private function cleanup(string $text): string
    {
        // убираем **жирный текст**
        $text = preg_replace('/\*\*(.*?)\*\*/u', '$1', $text);

        // заголовки ###
        $text = preg_replace('/^###\s*(.*?)$/um', '$1', $text);

        // списки
        $text = preg_replace('/^\s*-\s+/um', '• ', $text);

        // лишние пустые строки
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private function formatFullText(string $text): string
    {
        // нумерованные пункты превращаем в 📌
        $text = preg_replace('/^\d+\.\s*(.*?)$/um', '📌 $1', $text);

        // секции
        $text = preg_replace('/\bВажно:/u', "⚠️ Важно:\n", $text);
        $text = preg_replace('/\bПример.*?:/u', "💬 Пример:\n", $text);

        // списки — аккуратнее
        $text = preg_replace('/•\s*/u', '• ', $text);

        // пустые строки
        $text = preg_replace("/\n{2,}/", "\n\n", $text);

        return trim($text);
    }

    private function makeHeader(int $usedToday, int $maxPerDay, bool $isGroupChat): string
    {
        $headers = [
            '🧠 DeepSeek AI v3.2 · Отвечает',
            '🤖 DeepSeek AI v3.2 · Разбор',
            '💡 Ответ от DeepSeek v3.2 AI',
        ];

        $title = $headers[array_rand($headers)];
        $remaining = max(0, $maxPerDay - $usedToday);
        $limitLine = $isGroupChat
            ? '📊 Запросов сегодня (UTC): '.$usedToday.' из '.$maxPerDay.'. Доступно ещё на группу: '.$remaining
            : '📊 Запросов сегодня (UTC): '.$usedToday.' из '.$maxPerDay.'. Доступно ещё: '.$remaining;

        return $title."\n".
               "━━━━━━━━━━\n".
               $limitLine."\n".
               "━━━━━━━━━━\n".
               "⚠️ Ответ сгенерирован ИИ на основе данных\n".
               "📌 Возможны неточности\n".
               "❗ Не является рекомендацией или фактом\n".
               '━━━━━━━━━━';
    }

    private function makeFooter(bool $isPrivate): string
    {
        $base = "━━━━━━━━━━\n✨ Ваш ИИ помощник → @ContextChatAiBot";
        if (!$isPrivate) {
            return $base;
        }

        return "Выключить режим вопросов к ИИ — нажмите /stop_ask_ai\n\n".$base;
    }
}
