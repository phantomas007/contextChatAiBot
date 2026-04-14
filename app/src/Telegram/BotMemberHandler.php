<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\GroupSettings;
use App\Entity\TelegramGroup;
use App\Repository\GroupSettingsRepository;
use App\Repository\TelegramGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Обрабатывает my_chat_member — добавление/удаление бота из группы.
 *
 * При добавлении (member/administrator) — создаёт или активирует TelegramGroup,
 * при отсутствии строки — создаёт GroupSettings (daily_enabled = true по умолчанию).
 * При удалении (left/kicked) — деактивирует TelegramGroup.
 */
final class BotMemberHandler
{
    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly GroupSettingsRepository $groupSettingsRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $myChatMember
     */
    public function handle(array $myChatMember): void
    {
        $chat = $myChatMember['chat'] ?? [];
        $chatType = $chat['type'] ?? '';

        if (!\in_array($chatType, ['group', 'supergroup'], true)) {
            return;
        }

        $chatId = (int) ($chat['id'] ?? 0);
        $title = (string) ($chat['title'] ?? '');
        $newStatus = $myChatMember['new_chat_member']['status'] ?? '';

        if (\in_array($newStatus, ['member', 'administrator'], true)) {
            $this->onBotAdded($chatId, $title);
        } elseif (\in_array($newStatus, ['left', 'kicked'], true)) {
            $this->onBotRemoved($chatId);
        }
    }

    private function onBotAdded(int $chatId, string $title): void
    {
        $group = $this->groupRepository->findOneBy(['telegramChatId' => $chatId]);

        if (null === $group) {
            $group = new TelegramGroup($chatId, $title);
            $this->em->persist($group);
            $this->logger->info('BotMemberHandler: новая группа создана', [
                'chat_id' => $chatId,
                'title' => $title,
            ]);
        } else {
            $group->activate();
            if ('' !== $title) {
                $group->updateTitle($title);
            }
            $this->logger->info('BotMemberHandler: группа активирована', [
                'chat_id' => $chatId,
                'title' => $title,
            ]);
        }

        $this->ensureDefaultGroupSettings($group);

        $this->em->flush();
    }

    private function ensureDefaultGroupSettings(TelegramGroup $group): void
    {
        if (null !== $this->groupSettingsRepository->findByGroup($group)) {
            return;
        }

        $this->em->persist(new GroupSettings($group));
    }

    private function onBotRemoved(int $chatId): void
    {
        $group = $this->groupRepository->findOneBy(['telegramChatId' => $chatId]);

        if (null !== $group) {
            $group->deactivate();
            $this->em->flush();

            $this->logger->info('BotMemberHandler: группа деактивирована', [
                'chat_id' => $chatId,
                'title' => $group->getTitle(),
            ]);
        }
    }
}
