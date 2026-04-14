<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TelegramBotClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telegram-set-commands',
    description: 'Регистрирует меню команд бота в Telegram (setMyCommands)',
)]
final class SetTelegramBotCommandsCommand extends Command
{
    /** Клиенты с русской локалью часто не подхватывают команды без явного language_code. */
    private const array EXTRA_LANGUAGE_CODES = ['ru', 'en'];

    public function __construct(
        private readonly TelegramBotClient $telegramClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $privateCommands = [
            ['command' => 'start', 'description' => 'Старт бота'],
            ['command' => 'help', 'description' => 'Инструкция и список команд'],
            ['command' => 'add_to_chat', 'description' => 'Добавить бота в группу'],
            ['command' => 'ask_ai', 'description' => 'Спроси ИИ — DeepSeek AI v3.2'],
            ['command' => 'stop_ask_ai', 'description' => 'Выключить режим вопросов к ИИ'],
        ];

        /** @var list<array{command: string, description: string}> $groupCommands */
        $groupCommands = [
            ['command' => 'ask_ai', 'description' => 'Спросить ИИ — DeepSeek AI v3.2'],
        ];

        $adminCommands = [
            ['command' => 'group_settings', 'description' => 'Настройки контекста'],
        ];

        $io->writeln('Сброс прежних команд (включая список из BotFather)…');
        $this->telegramClient->deleteMyCommands();
        $io->writeln('✓ deleteMyCommands');

        $this->registerScopeWithLocales($io, $privateCommands, ['type' => 'all_private_chats'], 'Личка');
        if ([] !== $groupCommands) {
            $this->registerScopeWithLocales($io, $groupCommands, ['type' => 'all_group_chats'], 'Группы (все участники)');
        } else {
            $io->writeln('Пропуск scope «группы»: меню для участников пустое (как после deleteMyCommands).');
        }
        $this->registerScopeWithLocales($io, $adminCommands, ['type' => 'all_chat_administrators'], 'Админы групп');

        $io->note('Проверить, что видит API: php bin/console app:telegram-dump-commands');
        $io->success('Меню зарегистрировано. Полностью закройте Telegram (смахните из списка приложений) и откройте чат с ботом заново.');

        return Command::SUCCESS;
    }

    /**
     * @param list<array{command: string, description: string}> $commands
     * @param array<string, mixed>                               $scope
     */
    private function registerScopeWithLocales(SymfonyStyle $io, array $commands, array $scope, string $label): void
    {
        $this->telegramClient->setMyCommands($commands, $scope);
        $io->writeln(\sprintf('✓ %s — язык по умолчанию', $label));

        foreach (self::EXTRA_LANGUAGE_CODES as $lang) {
            $this->telegramClient->setMyCommands($commands, $scope, $lang);
            $io->writeln(\sprintf('✓ %s — language_code=%s', $label, $lang));
        }
    }
}
