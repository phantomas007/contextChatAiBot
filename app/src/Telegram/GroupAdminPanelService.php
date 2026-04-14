<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\GroupSettings;
use App\Entity\TelegramGroup;
use App\Repository\GroupSettingsRepository;
use App\Service\TelegramBotClient;
use Doctrine\ORM\EntityManagerInterface;

final class GroupAdminPanelService
{
    public const array COUNT_OPTIONS = [50, 100, 150, 200, 300];

    public function __construct(
        private readonly GroupSettingsRepository $groupSettingsRepository,
        private readonly TelegramBotClient $telegramClient,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function sendPanel(int $chatId, TelegramGroup $group): void
    {
        $settings = $this->groupSettingsRepository->findByGroup($group);

        $text = $this->buildPanelText($group, $settings);
        $keyboard = $this->buildKeyboard($settings);

        $this->telegramClient->sendMessageWithKeyboard($chatId, $text, $keyboard);
    }

    /**
     * Обновляет то же сообщение панели после действия по inline-кнопке.
     * В форум-супергруппе нужен message_thread_id из callback_query.message.
     */
    public function refreshPanelMessage(
        int $chatId,
        int $messageId,
        TelegramGroup $group,
        ?int $messageThreadId = null,
    ): void {
        $settings = $this->groupSettingsRepository->findByGroup($group);
        $text = $this->buildPanelText($group, $settings);
        $keyboard = $this->buildKeyboard($settings);

        $this->telegramClient->editMessageTextWithKeyboard($chatId, $messageId, $text, $keyboard, $messageThreadId);
    }

    public function buildPanelText(TelegramGroup $group, ?GroupSettings $settings): string
    {
        $count = $settings?->getCountThreshold();
        $daily = $settings?->isDailyEnabled() ?? true;

        $batchStatus = $count
            ? "каждые <b>{$count}</b> сообщений"
            : '🔴 выключено';

        $dailyStatus = $daily
            ? '🟢 включен'
            : '🔴 выключен';

        $title = htmlspecialchars($group->getTitle() ?? 'Группа', \ENT_QUOTES | \ENT_XML1);

        return <<<HTML
            ⚙️ <b>Панель управления</b>

            🏷 <b>{$title}</b>

            ━━━━━━━━━━━━━━━
            📊 <b>Контекст по сообщениям</b>
            {$batchStatus}

            📅 <b>Суточный контекст</b>
            {$dailyStatus}
            ━━━━━━━━━━━━━━━

            <i>Выбери настройку ниже 👇</i>
            HTML;
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public function buildKeyboard(?GroupSettings $settings = null): array
    {
        $current = $settings?->getCountThreshold();
        $daily = $settings?->isDailyEnabled() ?? true;

        $rows = [];

        $rows[] = [['text' => '📊 Контекст по сообщениям', 'callback_data' => 'ga_noop']];
        $rows[] = [[
            'text' => null === $current ? '✅ 🚫 Выключено' : '🚫 Выключить',
            'callback_data' => 'ga_dis_cust',
        ]];

        foreach (array_chunk(self::COUNT_OPTIONS, 3) as $chunk) {
            $row = [];

            foreach ($chunk as $n) {
                $row[] = [
                    'text' => ($current === $n ? '✅ ' : '').$n,
                    'callback_data' => 'ga_b_'.$n,
                ];
            }

            $rows[] = $row;
        }

        $rows[] = [['text' => '📅 Суточный режим', 'callback_data' => 'ga_noop']];

        $rows[] = [
            [
                'text' => $daily ? '✅ Вкл' : 'Вкл',
                'callback_data' => 'ga_en_daily',
            ],
            [
                'text' => !$daily ? '✅ Выкл' : 'Выкл',
                'callback_data' => 'ga_dis_daily',
            ],
        ];

        $rows[] = [[
            'text' => '✖️ Закрыть панель',
            'callback_data' => 'ga_close',
        ]];

        return $rows;
    }

    public function applySetBatch(TelegramGroup $group, int $value): bool
    {
        if (!\in_array($value, self::COUNT_OPTIONS, true)) {
            return false;
        }

        $settings = $this->getOrCreateGroupSettings($group);
        $settings->setCountThreshold($value);
        $this->em->flush();

        return true;
    }

    public function applyDisableCustom(TelegramGroup $group): void
    {
        $settings = $this->getOrCreateGroupSettings($group);
        $settings->disableCustom();
        $this->em->flush();
    }

    public function applyEnableDaily(TelegramGroup $group): void
    {
        $settings = $this->getOrCreateGroupSettings($group);
        $settings->setDailyEnabled(true);
        $this->em->flush();
    }

    public function applyDisableDaily(TelegramGroup $group): void
    {
        $settings = $this->getOrCreateGroupSettings($group);
        $settings->setDailyEnabled(false);
        $this->em->flush();
    }

    private function getOrCreateGroupSettings(TelegramGroup $group): GroupSettings
    {
        $settings = $this->groupSettingsRepository->findByGroup($group);

        if (null === $settings) {
            $settings = new GroupSettings($group);
            $this->em->persist($settings);
        }

        return $settings;
    }
}
