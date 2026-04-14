<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\BotBlockedByUserException;
use App\Exception\TelegramRateLimitException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @phpstan-type InlineKeyboardButton = array{text: string, callback_data: string}|array{text: string, url: string}
 */
class TelegramBotClient
{
    private const string API_BASE = 'https://api.telegram.org';
    private const int TIMEOUT = 10;

    /** Загрузка видео по URL: Telegram качает файл с вашего HTTPS. */
    private const int SEND_VIDEO_TIMEOUT = 120;

    /** Дольше обычного: до api.telegram.org из части сетей ответ приходит медленно. */
    private const int SET_MY_COMMANDS_TIMEOUT = 120;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'TELEGRAM_BOT_TOKEN')]
        private readonly string $botToken,
    ) {
    }

    /**
     * Отправляет текстовое сообщение в чат (группу или личку).
     *
     * @throws BotBlockedByUserException когда бот заблокирован пользователем (HTTP 403)
     * @throws TelegramRateLimitException когда превышен лимит запросов (HTTP 429)
     */
    public function sendMessage(int $chatId, string $text): void
    {
        try {
            $this->httpClient->request('POST', $this->apiUrl('sendMessage'), [
                'timeout' => self::TIMEOUT,
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ],
            ])->getContent();
        } catch (ClientExceptionInterface $e) {
            $this->handleSendError($chatId, $e);
        }
    }

    /**
     * Ответ на сообщение пользователя (reply).
     *
     * @see https://core.telegram.org/bots/api#sendmessage
     */
    public function sendMessageReply(int $chatId, string $text, int $replyToMessageId, ?int $messageThreadId = null): void
    {
        try {
            $json = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_parameters' => [
                    'message_id' => $replyToMessageId,
                ],
            ];
            if (null !== $messageThreadId) {
                $json['message_thread_id'] = $messageThreadId;
            }

            $this->httpClient->request('POST', $this->apiUrl('sendMessage'), [
                'timeout' => self::TIMEOUT,
                'json' => $json,
            ])->getContent();
        } catch (ClientExceptionInterface $e) {
            $this->handleSendError($chatId, $e);
        }
    }

    /**
     * Ответ на сообщение с inline-клавиатурой; возвращает message_id нового сообщения.
     *
     * @param array<int, array<int, InlineKeyboardButton>> $keyboard
     *
     * @throws BotBlockedByUserException
     * @throws TelegramRateLimitException
     * @throws \RuntimeException при ответе API без message_id
     */
    public function sendMessageReplyWithKeyboard(
        int $chatId,
        string $text,
        int $replyToMessageId,
        array $keyboard,
        ?int $messageThreadId = null,
    ): int {
        try {
            $json = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_parameters' => [
                    'message_id' => $replyToMessageId,
                ],
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ];
            if (null !== $messageThreadId) {
                $json['message_thread_id'] = $messageThreadId;
            }

            $response = $this->httpClient->request('POST', $this->apiUrl('sendMessage'), [
                'timeout' => self::TIMEOUT,
                'json' => $json,
            ]);
            $data = $response->toArray(false);
            if (!($data['ok'] ?? false)) {
                $desc = isset($data['description']) ? (string) $data['description'] : 'unknown';

                throw new \RuntimeException('Telegram sendMessage: '.$desc);
            }
            $mid = $data['result']['message_id'] ?? null;
            if (!is_numeric($mid)) {
                throw new \RuntimeException('Telegram sendMessage: no message_id in response');
            }

            return (int) $mid;
        } catch (ClientExceptionInterface $e) {
            $this->handleSendError($chatId, $e);
        }
    }

    /**
     * Отправляет сообщение с inline-клавиатурой.
     *
     * @param array<int, array<int, InlineKeyboardButton>> $keyboard
     *
     * @throws BotBlockedByUserException
     * @throws TelegramRateLimitException
     */
    public function sendMessageWithKeyboard(int $chatId, string $text, array $keyboard): void
    {
        try {
            $this->httpClient->request('POST', $this->apiUrl('sendMessage'), [
                'timeout' => self::TIMEOUT,
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => ['inline_keyboard' => $keyboard],
                ],
            ])->getContent();
        } catch (ClientExceptionInterface $e) {
            $this->handleSendError($chatId, $e);
        }
    }

    /**
     * Видео по публичному HTTPS URL (Telegram скачивает файл сам).
     *
     * @see https://core.telegram.org/bots/api#sendvideo
     *
     * @throws BotBlockedByUserException
     * @throws TelegramRateLimitException
     */
    public function sendVideoByUrl(int $chatId, string $videoUrl, ?string $caption = null): void
    {
        try {
            $json = [
                'chat_id' => $chatId,
                'video' => $videoUrl,
            ];
            if (null !== $caption && '' !== $caption) {
                $json['caption'] = $caption;
                $json['parse_mode'] = 'HTML';
            }

            $this->httpClient->request('POST', $this->apiUrl('sendVideo'), [
                'timeout' => self::SEND_VIDEO_TIMEOUT,
                'json' => $json,
            ])->getContent();
        } catch (ClientExceptionInterface $e) {
            $this->handleSendError($chatId, $e);
        }
    }

    /**
     * @param array<string, mixed> $message callback_query.message или message из апдейта
     */
    public static function messageThreadIdFromMessage(array $message): ?int
    {
        if (!isset($message['message_thread_id'])) {
            return null;
        }

        $t = $message['message_thread_id'];

        return is_numeric($t) ? (int) $t : null;
    }

    /**
     * Убирает inline-клавиатуру у сообщения (текст панели остаётся).
     *
     * @see https://core.telegram.org/bots/api#editmessagereplymarkup
     */
    public function removeInlineKeyboardFromMessage(int $chatId, int $messageId, ?int $messageThreadId = null): void
    {
        try {
            $json = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => ['inline_keyboard' => []],
            ];
            if (null !== $messageThreadId) {
                $json['message_thread_id'] = $messageThreadId;
            }

            $this->httpClient->request('POST', $this->apiUrl('editMessageReplyMarkup'), [
                'timeout' => self::TIMEOUT,
                'json' => $json,
            ])->getContent();
        } catch (ClientExceptionInterface $e) {
            $this->logger->warning('editMessageReplyMarkup (remove keyboard) failed', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Редактирует текст сообщения и inline-клавиатуру (обновление панели админа, ctx_toggle).
     *
     * Telegram часто отвечает HTTP 200 с JSON {"ok":false,...} — проверяем поле ok.
     * Если указан message_thread_id и запрос не прошёл — один повтор без топика (редкий случай API).
     *
     * @param array<int, array<int, array{text: string, callback_data: string}>> $keyboard
     *
     * @see https://core.telegram.org/bots/api#editmessagetext
     */
    public function editMessageTextWithKeyboard(
        int $chatId,
        int $messageId,
        string $text,
        array $keyboard,
        ?int $messageThreadId = null,
    ): bool {
        $json = $this->buildEditMessageTextPayload($chatId, $messageId, $text, $keyboard, $messageThreadId);
        $ok = $this->postEditMessageText($json);
        if (!$ok && null !== $messageThreadId) {
            unset($json['message_thread_id']);
            $ok = $this->postEditMessageText($json);
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function postEditMessageText(array $json): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl('editMessageText'), [
                'timeout' => self::TIMEOUT,
                'json' => $json,
            ]);
            $content = $response->getContent(false);
            $data = json_decode($content, true);
            if (!\is_array($data) || !($data['ok'] ?? false)) {
                $this->logger->warning('editMessageText failed', [
                    'chat_id' => $json['chat_id'] ?? null,
                    'message_id' => $json['message_id'] ?? null,
                    'message_thread_id' => $json['message_thread_id'] ?? null,
                    'http_status' => $response->getStatusCode(),
                    'description' => \is_array($data) ? ($data['description'] ?? $content) : $content,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('editMessageText failed', [
                'chat_id' => $json['chat_id'] ?? null,
                'message_id' => $json['message_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<int, array<int, array{text: string, callback_data: string}>> $keyboard
     *
     * @return array<string, mixed>
     */
    private function buildEditMessageTextPayload(
        int $chatId,
        int $messageId,
        string $text,
        array $keyboard,
        ?int $messageThreadId,
    ): array {
        $json = [
            // Строка: для супергрупп с большим отрицательным id JSON надёжнее совпадает с тем, что ждёт API.
            'chat_id' => (string) $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];
        if (null !== $messageThreadId) {
            $json['message_thread_id'] = $messageThreadId;
        }

        return $json;
    }

    /**
     * Подтверждает обработку callback-запроса (убирает spinner с кнопки у пользователя).
     *
     * @param string $text      до ~200 символов; пустая строка — без всплывающего текста
     * @param bool   $showAlert если true — модальное окно вместо короткого тоста
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void
    {
        try {
            $payload = [
                'callback_query_id' => $callbackQueryId,
            ];
            if ('' !== $text) {
                $payload['text'] = $text;
            }
            if ($showAlert) {
                $payload['show_alert'] = true;
            }

            $this->httpClient->request('POST', $this->apiUrl('answerCallbackQuery'), [
                'timeout' => self::TIMEOUT,
                'json' => $payload,
            ])->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('answerCallbackQuery failed', [
                'callback_query_id' => $callbackQueryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Регистрирует пункты меню команд (кнопка «/» у поля ввода).
     *
     * @param list<array{command: string, description: string}> $commands
     * @param array<string, mixed>                             $scope например ['type' => 'all_private_chats']
     * @param string|null                                      $languageCode ISO 639-1 (например ru, en) — иначе часть клиентов не показывает меню для локали пользователя
     *
     * @see https://core.telegram.org/bots/api#setmycommands
     */
    public function setMyCommands(array $commands, array $scope = [], ?string $languageCode = null): void
    {
        $payload = ['commands' => $commands];
        if ([] !== $scope) {
            $payload['scope'] = $scope;
        }
        if (null !== $languageCode && '' !== $languageCode) {
            $payload['language_code'] = $languageCode;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl('setMyCommands'), [
                'timeout' => self::SET_MY_COMMANDS_TIMEOUT,
                'json' => $payload,
            ])->toArray(false);

            if (!($response['ok'] ?? false)) {
                $desc = isset($response['description']) ? (string) $response['description'] : 'unknown';
                $this->logger->error('Telegram setMyCommands failed', ['description' => $desc, 'scope' => $scope]);

                throw new \RuntimeException(\sprintf('Telegram setMyCommands: %s', $desc));
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Telegram setMyCommands request failed', [
                'error' => $e->getMessage(),
                'scope' => $scope,
            ]);

            throw new \RuntimeException(\sprintf('Telegram setMyCommands: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * Удаляет все зарегистрированные команды бота (все scope). Перед setMyCommands снимает конфликт со старым списком из BotFather.
     *
     * @see https://core.telegram.org/bots/api#deletemycommands
     */
    public function deleteMyCommands(): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl('deleteMyCommands'), [
                'timeout' => self::SET_MY_COMMANDS_TIMEOUT,
                'json' => new \stdClass(),
            ])->toArray(false);

            if (!($response['ok'] ?? false)) {
                $desc = isset($response['description']) ? (string) $response['description'] : 'unknown';
                $this->logger->error('Telegram deleteMyCommands failed', ['description' => $desc]);

                throw new \RuntimeException(\sprintf('Telegram deleteMyCommands: %s', $desc));
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Telegram deleteMyCommands request failed', ['error' => $e->getMessage()]);

            throw new \RuntimeException(\sprintf('Telegram deleteMyCommands: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * Возвращает список команд, видимый API для scope/языка (диагностика меню «/»).
     *
     * @param array<string, mixed> $scope
     *
     * @return list<array{command: string, description: string}>
     *
     * @see https://core.telegram.org/bots/api#getmycommands
     */
    public function getMyCommands(array $scope = [], ?string $languageCode = null): array
    {
        $payload = [];
        if ([] !== $scope) {
            $payload['scope'] = $scope;
        }
        if (null !== $languageCode && '' !== $languageCode) {
            $payload['language_code'] = $languageCode;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl('getMyCommands'), [
                'timeout' => self::SET_MY_COMMANDS_TIMEOUT,
                'json' => [] === $payload ? new \stdClass() : $payload,
            ])->toArray(false);

            if (!($response['ok'] ?? false)) {
                $desc = isset($response['description']) ? (string) $response['description'] : 'unknown';

                throw new \RuntimeException(\sprintf('Telegram getMyCommands: %s', $desc));
            }

            $result = $response['result'] ?? [];
            if (!\is_array($result)) {
                return [];
            }

            $out = [];
            foreach ($result as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $out[] = [
                    'command' => (string) ($item['command'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                ];
            }

            return $out;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(\sprintf('Telegram getMyCommands: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * Проверяет, является ли пользователь администратором чата.
     */
    public function isAdminInChat(int $chatId, int $userId): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl('getChatMember'), [
                'timeout' => self::TIMEOUT,
                'json' => ['chat_id' => $chatId, 'user_id' => $userId],
            ])->toArray();

            $status = $response['result']['status'] ?? '';

            return \in_array($status, ['creator', 'administrator'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function handleSendError(int $chatId, ClientExceptionInterface $e): never
    {
        $statusCode = $e->getResponse()->getStatusCode();

        if (403 === $statusCode) {
            $this->logger->warning('Bot blocked by user/chat', ['chat_id' => $chatId]);

            throw new BotBlockedByUserException($chatId);
        }

        if (429 === $statusCode) {
            $body = $e->getResponse()->toArray(false);
            $retryAfter = (int) ($body['parameters']['retry_after'] ?? 30);
            $this->logger->info('Telegram rate limit hit', [
                'chat_id' => $chatId,
                'retry_after' => $retryAfter,
            ]);

            throw new TelegramRateLimitException($retryAfter);
        }

        $this->logger->error('Telegram API error', [
            'chat_id' => $chatId,
            'status_code' => $statusCode,
            'message' => $e->getMessage(),
        ]);

        throw new \RuntimeException(\sprintf('Telegram API error %d: %s', $statusCode, $e->getMessage()), $statusCode, $e);
    }

    private function apiUrl(string $method): string
    {
        return \sprintf('%s/bot%s/%s', self::API_BASE, $this->botToken, $method);
    }
}
