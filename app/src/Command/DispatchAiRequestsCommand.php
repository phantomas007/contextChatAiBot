<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\MessageForAiDeepSeekMessage;
use App\Repository\MessageForAiRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:dispatch-ai-requests',
    description: 'Ставит в очередь message_for_ai без ответа — обработка DeepSeek',
)]
final class DispatchAiRequestsCommand extends Command
{
    public function __construct(
        private readonly MessageForAiRepository $messageForAiRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pending = $this->messageForAiRepository->findAwaitingDeepSeek(200);
        $n = 0;

        foreach ($pending as $row) {
            $id = $row->getId();
            if (null === $id) {
                continue;
            }
            $this->bus->dispatch(new MessageForAiDeepSeekMessage($id));
            ++$n;
        }

        if ($n > 0) {
            $io->writeln(\sprintf('Диспатч DeepSeek: %d задач.', $n));
        }

        return Command::SUCCESS;
    }
}
