<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI-совместимый Chat Completions API DeepSeek.
 *
 * @see https://api-docs.deepseek.com/
 */
final class DeepSeekClient
{
    private const int TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'DEEPSEEK_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'DEEPSEEK_API_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'DEEPSEEK_MODEL')]
        private readonly string $model,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * @throws \RuntimeException при ошибке HTTP или пустом ответе
     */
    public function chatCompletion(string $userPrompt): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('DEEPSEEK_API_KEY is not set');
        }

        $url = rtrim($this->baseUrl, '/').'/chat/completions';

        $response = $this->httpClient->request('POST', $url, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => <<<PROMPT
                            Ты полезный и структурированный AI-ассистент. Отвечай максимально по сути, как для Telegram-бота.

                            Правила ответа:
                            1. Язык ответа — русский, если пользователь не попросил другой.
                            2. Ответ должен быть структурированным: заголовки, списки, эмодзи (📌, 💬, ⚠️) для читаемости.
                            3. Максимальная длина ответа — 4000 символов.
                            4. Разделяй ответ на два блока:
                               ---TLDR---
                               Короткий ответ в 1–2 предложения (до 220 символов), максимально по сути, без лишних вступлений и эмодзи.
                               ---FULL---
                               Развёрнутый, структурированный ответ с примерами, списками, секциями, эмодзи.
                            5. Не используй фразы "как я понимаю", "вот основные варианты" и т.п.
                            6. Все советы носят справочный характер и не являются фактом или рекомендацией.
                            7. Если информации слишком много, сокращай до самого важного, сохраняя смысл.
                            8. Форматируй списки аккуратно: начинай каждый пункт с "📌", примеры с "💬", важные моменты с "⚠️".
                            PROMPT
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
                'temperature' => 0.7,
            ],
        ]);

        $status = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            $err = isset($data['error']['message']) ? (string) $data['error']['message'] : $response->getContent(false);

            throw new \RuntimeException('DeepSeek HTTP '.$status.': '.$err);
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!\is_string($content) || '' === trim($content)) {
            throw new \RuntimeException('DeepSeek: empty completion content');
        }

        return trim($content);
    }
}
