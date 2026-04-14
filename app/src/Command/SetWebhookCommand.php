<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:set-webhook',
    description: 'Устанавливает Telegram webhook для бота',
)]
final class SetWebhookCommand extends Command
{
    private const string API_BASE = 'https://api.telegram.org';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'TELEGRAM_BOT_TOKEN')]
        private readonly string $botToken,
        #[Autowire(env: 'TELEGRAM_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'URL вебхука (например, https://example.com/api/telegram/webhook)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $url */
        $url = $input->getArgument('url');

        $payload = [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        ];

        if ('' !== $this->webhookSecret) {
            $payload['secret_token'] = $this->webhookSecret;
        }

        $response = $this->httpClient->request(
            'POST',
            \sprintf('%s/bot%s/setWebhook', self::API_BASE, $this->botToken),
            ['json' => $payload],
        );

        $result = $response->toArray(false);

        if ($result['ok'] ?? false) {
            $io->success(\sprintf('Webhook установлен: %s', $url));
            $io->note(\sprintf('allowed_updates: %s', implode(', ', $payload['allowed_updates'])));
            if ('' !== $this->webhookSecret) {
                $io->note('secret_token: установлен');
            }

            return Command::SUCCESS;
        }

        $io->error(\sprintf('Ошибка: %s', $result['description'] ?? 'неизвестная ошибка'));

        return Command::FAILURE;
    }
}
