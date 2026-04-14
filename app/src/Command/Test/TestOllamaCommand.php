<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Service\OllamaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:test-ollama',
    description: 'Проверка LLM: локальный Ollama или RunPod (как в OllamaClient)',
)]
final class TestOllamaCommand extends Command
{
    public function __construct(
        #[Autowire(env: 'OLLAMA_URL')]
        private readonly string $ollamaUrl,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private readonly string $model,
        private readonly OllamaClient $ollamaClient,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'bool:default:use_local_ollama_default:USE_LOCAL_OLLAMA')]
        private readonly bool $useLocalOllama,
        #[Autowire(env: 'default::RUNPOD_API_KEY')]
        private readonly string $runpodApiKey,
        #[Autowire(env: 'default::RUNPOD_ENDPOINT_ID')]
        private readonly string $runpodEndpointId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Проверка LLM');

        if ($this->useLocalOllama) {
            return $this->runLocalFlow($io);
        }

        return $this->runRunpodFlow($io);
    }

    private function runLocalFlow(SymfonyStyle $io): int
    {
        $io->writeln('Режим: <info>локальный Ollama</info>');
        $io->writeln("OLLAMA_URL: {$this->ollamaUrl}");
        $io->writeln("Модель: {$this->model}");
        $io->newLine();

        try {
            $response = $this->httpClient->request('GET', $this->ollamaUrl.'/api/tags');
            $data = $response->toArray();
            $models = $data['models'] ?? [];
            $modelNames = array_column($models, 'name');
            $io->success('Ollama доступна');

            $found = false;
            foreach ($modelNames as $name) {
                if (str_starts_with($name, $this->model) || $name === $this->model) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $io->warning(\sprintf(
                    'Модель «%s» не найдена. Доступные: %s. Выполните: docker compose exec ollama ollama pull %s',
                    $this->model,
                    implode(', ', \array_slice($modelNames, 0, 5)),
                    $this->model,
                ));
            } else {
                $io->success("Модель «{$this->model}» найдена");
            }

            return $this->runGenerationTest($io);
        } catch (\Throwable $e) {
            $this->printLocalFailure($io, $e);

            return Command::FAILURE;
        }
    }

    private function runRunpodFlow(SymfonyStyle $io): int
    {
        $io->writeln('Режим: <info>RunPod serverless</info>');
        $io->writeln('Модель (ожидается на воркере): '.$this->model);
        $io->writeln('RUNPOD_ENDPOINT_ID: '.('' !== $this->runpodEndpointId ? $this->runpodEndpointId : '<comment>не задан</comment>'));
        $io->writeln('RUNPOD_API_KEY: '.('' !== $this->runpodApiKey ? $this->maskSecret($this->runpodApiKey) : '<comment>не задан</comment>'));
        $io->newLine();

        if ('' === $this->runpodApiKey || '' === $this->runpodEndpointId) {
            $io->error('Для RunPod задайте RUNPOD_API_KEY и RUNPOD_ENDPOINT_ID в окружении.');
            $io->listing([
                'USE_LOCAL_OLLAMA=false',
                'RUNPOD_API_KEY — ключ из консоли RunPod',
                'RUNPOD_ENDPOINT_ID — ID serverless-эндпоинта с Ollama (тот же, что для кирпичей)',
            ]);

            return Command::FAILURE;
        }

        try {
            return $this->runGenerationTest($io);
        } catch (\Throwable $e) {
            $this->printRunpodFailure($io, $e);

            return Command::FAILURE;
        }
    }

    private function runGenerationTest(SymfonyStyle $io): int
    {
        $io->section('Тестовый запрос (summarizeYa2)');
        $result = $this->ollamaClient->summarizeYa2(
            [
                ['username' => 'test_user', 'text' => 'Привет! Это тестовое сообщение.', 'date' => '01.01.2025 12:00'],
            ],
            'Тестовая группа',
        );
        $io->success('Генерация работает');
        $io->writeln('<info>Ответ (первые 200 символов):</info>');
        $io->writeln(mb_substr($result, 0, 200).'...');

        return Command::SUCCESS;
    }

    private function printLocalFailure(SymfonyStyle $io, \Throwable $e): void
    {
        $io->error('Ошибка: '.$e->getMessage());
        $io->writeln('');
        $io->writeln('Возможные причины:');
        $io->listing([
            'Ollama не запущена: docker compose ps ollama',
            'Модель не загружена: docker compose exec ollama ollama pull '.$this->model,
            'Неверный OLLAMA_URL в app/.env.local (часто http://ollama:11434 внутри Docker)',
        ]);
    }

    private function printRunpodFailure(SymfonyStyle $io, \Throwable $e): void
    {
        $io->error('Ошибка: '.$e->getMessage());
        $io->writeln('');
        $io->writeln('Возможные причины:');
        $io->listing([
            'Неверный или просроченный RUNPOD_API_KEY',
            'Неверный RUNPOD_ENDPOINT_ID или эндпоинт выключён в консоли RunPod',
            'Воркер не отдаёт ответ в ожидаемом формате (см. OllamaClient::generateRunpod)',
            'USE_LOCAL_OLLAMA=false должен совпадать с реальным способом вызова',
        ]);
    }

    private function maskSecret(string $secret): string
    {
        $len = \strlen($secret);
        if ($len <= 8) {
            return '***';
        }

        return substr($secret, 0, 4).'…'.substr($secret, -4);
    }
}
