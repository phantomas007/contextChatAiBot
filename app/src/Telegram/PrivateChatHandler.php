<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\TelegramGroup;
use App\Entity\User;
use App\Repository\GroupSettingsRepository;
use App\Repository\UserGroupRepository;
use App\Repository\UserGroupSubscriptionRepository;
use App\Service\AskAiTelegramService;
use App\Service\TelegramBotClient;
use App\Service\UserUpsertService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PrivateChatHandler
{
    private const string MY_CHATS_CAPTION = <<<'HTML'
        📬 <b>Твои чаты</b>
        Нажимая кнопочку:

        🟢 — получаешь контекст в личку
        ⚪ — выключаешь

        <b>Тебе доступно:</b>
        📊 — каждые N сообщений
        📅 — суточный обзор
        HTML;

    public function __construct(
        private readonly TelegramBotClient $telegramClient,
        private readonly UserUpsertService $userUpsertService,
        private readonly UserGroupRepository $userGroupRepository,
        private readonly UserGroupSubscriptionRepository $subscriptionRepository,
        private readonly GroupSettingsRepository $groupSettingsRepository,
        private readonly EntityManagerInterface $em,
        private readonly AskAiTelegramService $askAiTelegramService,
        private readonly AskAiPrivateModeStore $askAiPrivateModeStore,
        #[Autowire(env: 'default::TELEGRAM_BOT_LINK')]
        private readonly string $telegramBotLink,
        #[Autowire(env: 'default::APP_PUBLIC_URL')]
        private readonly ?string $appPublicUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $from
     */
    public function handle(array $message, array $from, string $text): void
    {
        $user = $this->userUpsertService->findOrCreate($from);
        $user->markBotChatStarted();
        $this->em->flush();

        $chatId = (int) $message['chat']['id'];
        $cmd = $this->normalizedCommandToken($text);

        if (str_starts_with($cmd, '/start')) {
            $this->cmdStart($chatId);

            return;
        }

        if (str_starts_with($cmd, '/help')) {
            $this->cmdHelp($chatId);

            return;
        }

        if (str_starts_with($cmd, '/stop_ask_ai')) {
            $this->askAiPrivateModeStore->disable((int) $from['id']);

            $this->telegramClient->sendMessage(
                $chatId,
                <<<'HTML'
                    ❌ <b>Режим вопросов к ИИ выключен</b>

                    Чтобы снова включить режим, нажмите /ask_ai
                    HTML
            );

            return;
        }

        if (str_starts_with($cmd, '/ask_ai')) {
            $this->askAiTelegramService->handlePrivate($message, $from, $text);

            return;
        }

        if (str_starts_with($cmd, '/my_chats')) {
            $this->sendMyChatsList($chatId, $user);

            return;
        }

        if (str_starts_with($cmd, '/add_to_chat')) {
            $this->cmdAddToChat($chatId);

            return;
        }

        if ($this->isGroupOnlyCommand($cmd)) {
            $this->cmdOnlyInGroup($chatId);

            return;
        }

        // В личке обычный текст — вопрос к ИИ только в режиме диалога (/ask_ai). В группе то же для обычных сообщений (см. webhook).
        $trimmed = trim($text);
        if ('' !== $trimmed && !str_starts_with($trimmed, '/')) {
            if ($this->askAiPrivateModeStore->isEnabled((int) $from['id'])) {
                $this->askAiTelegramService->handlePrivate($message, $from, '/ask_ai '.$trimmed);
            }
        }
    }

    /**
     * @return array{text: string, keyboard: list<list<array<string, string>>>}|null
     */
    public function buildMyChatsListPayload(User $user): ?array
    {
        $groups = $this->userGroupRepository->findActiveTelegramGroupsForUser($user);

        if ([] === $groups) {
            return null;
        }

        $rows = [];

        foreach ($groups as $group) {
            $isSubscribed = $this->isSubscribed($user, $group);
            $settings = $this->groupSettingsRepository->findByGroup($group);

            $batch = (null === $settings || null === $settings->getCountThreshold())
                ? null
                : $settings->getCountThreshold();

            $daily = (null === $settings)
                ? false
                : $settings->isDailyEnabled();

            $title = $group->getTitle() ?? ('#'.$group->getId());

            // режем, чтобы гарантированно влезало
            if (mb_strlen($title) > 25) {
                $title = mb_substr($title, 0, 22).'...';
            }

            $statusIcon = $isSubscribed ? '🟢' : '⚪';

            // компактные значения
            $batchValue = $batch ? (string) $batch : '×';
            $dailyValue = $daily ? '✓' : '×';

            // строка 1 — название
            $rows[] = [[
                'text' => $statusIcon.' '.$title,
                'callback_data' => 'sub_'.$group->getId(),
            ]];

            // строка 2 — инфа
            $rows[] = [[
                'text' => '📊'.$batchValue.'  📅'.$dailyValue,
                'callback_data' => 'sub_'.$group->getId(),
            ]];

            $rows[] = [[
                'text' => '──────────',
                'callback_data' => 'priv_noop',
            ]];
        }

        return [
            'text' => self::MY_CHATS_CAPTION,
            'keyboard' => $rows,
        ];
    }

    public function sendHelpMessage(int $chatId): void
    {
        $this->cmdHelp($chatId);
    }

    public function sendMyChatsList(int $chatId, User $user): void
    {
        $payload = $this->buildMyChatsListPayload($user);

        if (null === $payload) {
            $this->telegramClient->sendMessage(
                $chatId,
                'Пока здесь нет чатов. Напиши сообщение в группе с ботом — чат появится в списке. Или попроси администратора добавить бота в чат. Потом открой 👉 /my_chats',
            );

            return;
        }

        $this->telegramClient->sendMessageWithKeyboard(
            $chatId,
            $payload['text'],
            $payload['keyboard'],
        );
    }

    private function cmdStart(int $chatId): void
    {
        $addToGroupUrl = $this->buildAddToGroupStartUrl();
        if (null === $addToGroupUrl) {
            $this->telegramClient->sendMessage(
                $chatId,
                'Ссылка на бота не настроена (TELEGRAM_BOT_LINK). Обратитесь к администратору.',
            );

            return;
        }

        $href = htmlspecialchars($addToGroupUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $introSuffix = $this->introVideoSuffixForMessage();

        $this->telegramClient->sendMessageWithKeyboard(
            $chatId,
            <<<HTML
                🧠 <b>ИИ аналитик чатов — DeepSeek AI v3.2</b>

                📊 <b>Анализ</b> сообщений в чате
                📝 <b>Контекст</b> — публикую в чат или ЛС
                💡 <b>Вопросы</b> — задавай к ИИ в группе или в ЛС → получи ответ
                🔧 <b>Настройки</b> гибкие для чата и ЛС

                ━━━━━━━━━━━━━━━
                👑 <b>Админу</b>
                ➕ <a href="{$href}">Добавь бота в чат</a>
                ⚙️ Настрой контекст → /group_settings
                💬 Участники могут задавать к ИИ через кнопку «Задать вопрос ИИ»

                👤 <b>Участнику</b>
                💬 Задай вопрос ИИ → получи ответ
                📥 Подпишись на контекст чата → получай в ЛС 👇
                HTML.$introSuffix,
            [
                [
                    ['text' => '📬 Мои чаты', 'callback_data' => 'priv_my_chats'],
                ],
                [
                    ['text' => '💬 Задать вопрос ИИ', 'callback_data' => 'priv_ask_ai'],
                ],
                [
                    ['text' => 'ℹ️ Помощь', 'callback_data' => 'priv_help'],
                ],
                [
                    ['text' => '➕ Добавить бота в чат', 'url' => $addToGroupUrl],
                ],
            ],
        );
    }

    private function cmdHelp(int $chatId): void
    {
        $addToGroupUrl = $this->buildAddToGroupStartUrl();
        $adminAddLine = null !== $addToGroupUrl
            ? '➕ <a href="'.htmlspecialchars($addToGroupUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'">Добавь бота в чат</a>'
            : '➕ Добавь бота в чат';

        $introSuffix = $this->introVideoSuffixForMessage();

        $this->telegramClient->sendMessage(
            $chatId,
            <<<HTML
                ℹ️ <b>Как работает бот</b>

                Бот анализирует сообщения чата, может отправлять контекст в группу или ЛС и позволяет задавать вопросы к ИИ

                ━━━━━━━━━━━━━━━
                👑 <b>Админу</b>
                {$adminAddLine}
                ⚙️ Настрой контекст → /group_settings
                📊 Бот формирует контекст обсуждения и держит вас в курсе 
                💬 Участники смогут задавать вопросы к ИИ

                👤 <b>Участнику</b>
                📬 Открой /my_chats
                🔔 Подпишись на нужный чат
                📝 Контекст чата будет приходить в личные сообщения
                💬 Задай вопрос к ИИ /ask_ai → ответ

                ━━━━━━━━━━━━━━━
                🤖 <b>Как задать вопрос к ИИ</b>

                <b>В личных сообщениях</b>
                /ask_ai — включить диалог с ИИ 
                /stop_ask_ai — выключить  диалог с ИИ 
                       
                <b>В чате</b>
                /ask_ai — включить диалог с ИИ 
                HTML.$introSuffix,
        );
    }

    private function cmdAddToChat(int $chatId): void
    {
        $url = $this->buildAddToGroupStartUrl();
        if (null === $url) {
            $this->telegramClient->sendMessage(
                $chatId,
                'Ссылка на бота не настроена (TELEGRAM_BOT_LINK). Обратитесь к администратору.',
            );

            return;
        }

        $href = htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $this->telegramClient->sendMessage(
            $chatId,
            <<<HTML
                ➕ <b>Добавить бота в чат</b>

                Нажми на ссылку — Telegram предложит выбрать группу и добавить бота.

                <a href="{$href}">Добавить в чат</a>
                HTML
        );
    }

    /** Полный HTTPS URL к файлу в public/. */
    private function buildIntroVideoUrl(): string
    {
        $base = rtrim(trim($this->appPublicUrl ?? ''), '/');
        if ('' === $base) {
            $base = 'https://test.contextchat.ai';
        }

        return $base.'/test.mp4';
    }

    /**
     * Возвращает суффикс текста со ссылкой на видео.
     */
    private function introVideoSuffixForMessage(): string
    {
        $url = $this->buildIntroVideoUrl();
        $escaped = htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return "\n\n".'🎬 <a href="'.$escaped.'">Видео инструкция</a>';
    }

    /** Ссылка t.me/...?startgroup=add_to_chat или null, если TELEGRAM_BOT_LINK пуст. */
    private function buildAddToGroupStartUrl(): ?string
    {
        $base = rtrim(trim($this->telegramBotLink), '/');
        if ('' === $base) {
            return null;
        }

        $sep = str_contains($base, '?') ? '&' : '?';

        return $base.$sep.'startgroup=add_to_chat';
    }

    private function isSubscribed(User $user, TelegramGroup $group): bool
    {
        return null !== $this->subscriptionRepository->findByUserAndGroup($user, $group);
    }

    private function cmdOnlyInGroup(int $chatId): void
    {
        $this->telegramClient->sendMessage(
            $chatId,
            'Команда работает только в группе.',
        );
    }

    private function normalizedCommandToken(string $text): string
    {
        $first = explode(' ', trim($text), 2)[0];

        return preg_replace('/@[A-Za-z0-9_]+$/', '', $first) ?? $first;
    }

    private function isGroupOnlyCommand(string $cmd): bool
    {
        return str_starts_with($cmd, '/group_settings');
    }
}
