<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\IncomingTelegramUpdateMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Сохраняет входящие сообщения из Telegram в БД.
 *
 * Обрабатывает IncomingTelegramUpdateMessage из очереди incoming_messages.
 * Извлекает текст из group/supergroup чатов, создаёт/обновляет группу и пользователя,
 * сохраняет Message. Игнорирует личку, сообщения от ботов (в т.ч. свои посты/дайджесты в группе),
 * пустые сообщения и текст команд (/start, /set_batch@bot …).
 *
 * Использует Lock по группе+сообщению для максимального параллелизма воркеров.
 * Redis-кеш для group_id, user_id, user_group — избегаем повторных запросов к БД.
 * Raw SQL вместо Doctrine для ускорения импорта.
 */
#[AsMessageHandler]
final class MessageSaveHandler
{
    private const int LOCK_TTL = 10;

    private const string CACHE_KEY_GROUP = 'msg_save_group_';
    private const string CACHE_KEY_USER = 'msg_save_user_';
    private const string CACHE_KEY_USER_GROUP = 'msg_save_ug_';

    public function __construct(
        private readonly Connection $connection,
        private readonly LockFactory $lockFactory,
        private readonly CacheInterface $groupCache,
    ) {
    }

    public function __invoke(IncomingTelegramUpdateMessage $envelope): void
    {
        $update = $envelope->update;
        $telegramMessage = $update['message'] ?? null;
        if (!\is_array($telegramMessage)) {
            return;
        }

        $chat = $telegramMessage['chat'] ?? null;
        if (!\is_array($chat) || !\in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
            return;
        }

        $text = $telegramMessage['text'] ?? null;
        if (!\is_string($text) || '' === $text) {
            return;
        }

        $trimmed = trim($text);
        if (str_starts_with($trimmed, '/')) {
            return;
        }

        $from = $telegramMessage['from'] ?? null;
        $senderChat = $telegramMessage['sender_chat'] ?? null;

        if (\is_array($from) && ($from['is_bot'] ?? false)) {
            return;
        }

        $telegramChatId = (int) $chat['id'];
        $telegramMessageId = (int) $telegramMessage['message_id'];
        $messageDate = new \DateTimeImmutable('@'.(int) $telegramMessage['date']);
        $chatTitle = $chat['title'] ?? null;

        $lock = $this->lockFactory->createLock(
            'message_save_group_'.$telegramChatId.'_msg_'.$telegramMessageId,
            ttl: self::LOCK_TTL,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->connection->beginTransaction();

            try {
                $groupId = $this->getOrCreateGroupId($telegramChatId, $chatTitle);

                $telegramUserId = null;
                $username = null;

                $resolved = $this->resolveSender($from, $senderChat);
                if (null !== $resolved) {
                    [$telegramUserId, $username, $firstName] = $resolved;
                    $userId = $this->getOrCreateUserId($telegramUserId, $username, $firstName);
                    $this->ensureUserGroup($userId, $groupId);
                } else {
                    $username = \is_array($from) ? ($from['username'] ?? $from['first_name'] ?? null) : null;
                    if (\is_array($senderChat)) {
                        $username = $senderChat['title'] ?? $senderChat['username'] ?? $username;
                    }
                }

                $this->insertMessage(
                    $groupId,
                    $telegramMessageId,
                    $telegramUserId,
                    $username,
                    $text,
                    $messageDate,
                );

                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolves sender from 'from' (user) or 'sender_chat' (channel/anonymous admin).
     * Returns [telegramUserId, username, firstName] or null when no user should be saved.
     *
     * @return array{0: int, 1: ?string, 2: ?string}|null
     */
    private function resolveSender(mixed $from, mixed $senderChat): ?array
    {
        if (\is_array($from) && !($from['is_bot'] ?? false)) {
            return [
                (int) $from['id'],
                $from['username'] ?? $from['first_name'] ?? null,
                $from['first_name'] ?? null,
            ];
        }
        if (\is_array($senderChat)) {
            $chatId = (int) ($senderChat['id'] ?? 0);
            if (0 !== $chatId) {
                return [
                    $chatId,
                    $senderChat['title'] ?? $senderChat['username'] ?? null,
                    null,
                ];
            }
        }

        return null;
    }

    private function getOrCreateGroupId(int $telegramChatId, ?string $title): int
    {
        $cacheKey = self::CACHE_KEY_GROUP.str_replace('-', 'n', (string) $telegramChatId);

        $data = $this->groupCache->get($cacheKey, function (ItemInterface $item) use ($telegramChatId, $title) {
            $groupId = $this->upsertGroup($telegramChatId, $title);

            return json_encode(['group_id' => $groupId, 'title' => $title], \JSON_THROW_ON_ERROR);
        });

        $decoded = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        $groupId = (int) $decoded['group_id'];
        $cachedTitle = $decoded['title'] ?? null;

        if (null !== $title && '' !== $title && $title !== $cachedTitle) {
            $this->updateGroupTitle($groupId, $title);
            $this->groupCache->delete($cacheKey);
            $this->groupCache->get($cacheKey, static fn () => json_encode(['group_id' => $groupId, 'title' => $title], \JSON_THROW_ON_ERROR));
        }

        return $groupId;
    }

    private function updateGroupTitle(int $groupId, string $title): void
    {
        $this->connection->executeStatement(
            'UPDATE telegram_groups SET title = :title WHERE id = :id',
            ['title' => $title, 'id' => $groupId],
            ['id' => ParameterType::INTEGER],
        );
    }

    private function getOrCreateUserId(int $telegramUserId, ?string $username, ?string $firstName): int
    {
        $cacheKey = self::CACHE_KEY_USER.str_replace('-', 'n', (string) $telegramUserId);

        $data = $this->groupCache->get($cacheKey, function (ItemInterface $item) use ($telegramUserId, $username, $firstName) {
            $userId = $this->upsertUser($telegramUserId, $username, $firstName);

            return json_encode(['user_id' => $userId, 'username' => $username, 'first_name' => $firstName], \JSON_THROW_ON_ERROR);
        });

        $decoded = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        $userId = (int) $decoded['user_id'];
        $cachedUsername = $decoded['username'] ?? null;
        $cachedFirstName = $decoded['first_name'] ?? null;

        if (($username !== $cachedUsername || $firstName !== $cachedFirstName)
            && (null !== $username || null !== $firstName)) {
            $this->updateUserProfile($userId, $username, $firstName);
            $this->groupCache->delete($cacheKey);
            $this->groupCache->get($cacheKey, static fn () => json_encode(['user_id' => $userId, 'username' => $username, 'first_name' => $firstName], \JSON_THROW_ON_ERROR));
        }

        return $userId;
    }

    private function updateUserProfile(int $userId, ?string $username, ?string $firstName): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET username = COALESCE(:username, username), first_name = COALESCE(:first_name, first_name) WHERE id = :id',
            ['username' => $username, 'first_name' => $firstName, 'id' => $userId],
            ['id' => ParameterType::INTEGER],
        );
    }

    private function upsertGroup(int $telegramChatId, ?string $title): int
    {
        $result = $this->connection->executeQuery(
            <<<'SQL'
                    INSERT INTO telegram_groups (telegram_chat_id, title, bot_joined_at, is_active)
                    VALUES (:telegram_chat_id, :title, NOW(), true)
                    ON CONFLICT (telegram_chat_id) DO UPDATE SET
                        title = CASE
                            WHEN EXCLUDED.title IS NOT NULL AND EXCLUDED.title != ''
                            THEN EXCLUDED.title
                            ELSE telegram_groups.title
                        END
                    RETURNING id
                SQL,
            [
                'telegram_chat_id' => $telegramChatId,
                'title' => $title,
            ],
            [
                'telegram_chat_id' => ParameterType::INTEGER,
            ],
        );

        $id = $result->fetchOne();
        if (false !== $id) {
            return (int) $id;
        }

        $row = $this->connection->fetchOne(
            'SELECT id FROM telegram_groups WHERE telegram_chat_id = :telegram_chat_id',
            ['telegram_chat_id' => $telegramChatId],
            ['telegram_chat_id' => ParameterType::INTEGER],
        );

        return (int) $row;
    }

    private function upsertUser(int $telegramUserId, ?string $username, ?string $firstName): int
    {
        $result = $this->connection->executeQuery(
            <<<'SQL'
                    INSERT INTO users (telegram_user_id, username, first_name, registered_at)
                    VALUES (:telegram_user_id, :username, :first_name, NOW())
                    ON CONFLICT (telegram_user_id) DO UPDATE SET
                        username = COALESCE(EXCLUDED.username, users.username),
                        first_name = COALESCE(EXCLUDED.first_name, users.first_name)
                    RETURNING id
                SQL,
            [
                'telegram_user_id' => $telegramUserId,
                'username' => $username,
                'first_name' => $firstName,
            ],
            [
                'telegram_user_id' => ParameterType::INTEGER,
            ],
        );

        $id = $result->fetchOne();
        if (false !== $id) {
            return (int) $id;
        }

        $row = $this->connection->fetchOne(
            'SELECT id FROM users WHERE telegram_user_id = :telegram_user_id',
            ['telegram_user_id' => $telegramUserId],
            ['telegram_user_id' => ParameterType::INTEGER],
        );

        return (int) $row;
    }

    private function ensureUserGroup(int $userId, int $groupId): void
    {
        $cacheKey = self::CACHE_KEY_USER_GROUP.$userId.'_'.$groupId;

        $this->groupCache->get($cacheKey, function () use ($userId, $groupId) {
            $this->connection->executeStatement(
                <<<'SQL'
                        INSERT INTO user_groups (user_id, group_id, joined_at)
                        VALUES (:user_id, :group_id, NOW())
                        ON CONFLICT (user_id, group_id) DO NOTHING
                    SQL,
                [
                    'user_id' => $userId,
                    'group_id' => $groupId,
                ],
                [
                    'user_id' => ParameterType::INTEGER,
                    'group_id' => ParameterType::INTEGER,
                ],
            );

            return '1';
        });
    }

    private function insertMessage(
        int $groupId,
        int $telegramMessageId,
        ?int $telegramUserId,
        ?string $username,
        string $text,
        \DateTimeImmutable $createdAt,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                    INSERT INTO messages (group_id, telegram_message_id, telegram_user_id, username, text, created_at)
                    VALUES (:group_id, :telegram_message_id, :telegram_user_id, :username, :text, :created_at)
                    ON CONFLICT (telegram_message_id, group_id) DO NOTHING
                SQL,
            [
                'group_id' => $groupId,
                'telegram_message_id' => $telegramMessageId,
                'telegram_user_id' => $telegramUserId,
                'username' => $username,
                'text' => $text,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
            ],
            [
                'group_id' => ParameterType::INTEGER,
                'telegram_message_id' => ParameterType::INTEGER,
                'telegram_user_id' => null !== $telegramUserId ? ParameterType::INTEGER : ParameterType::NULL,
            ],
        );
    }
}
