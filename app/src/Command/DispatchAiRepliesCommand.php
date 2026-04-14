<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\AiReplySendMessage;
use App\Repository\MessageForAiRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:dispatch-ai-replies',
    description: 'Ставит в очередь готовые ответы ИИ для отправки в Telegram',
)]
final class DispatchAiRepliesCommand extends Command
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

        $ready = $this->messageForAiRepository->findReadyToSend(200);
        $n = 0;

        foreach ($ready as $row) {
            $id = $row->getId();
            if (null === $id) {
                continue;
            }
            $this->bus->dispatch(new AiReplySendMessage($id));
            ++$n;
        }

        if ($n > 0) {
            $io->writeln(\sprintf('Диспатч ответов в Telegram: %d задач.', $n));
        }

        return Command::SUCCESS;
    }
}
