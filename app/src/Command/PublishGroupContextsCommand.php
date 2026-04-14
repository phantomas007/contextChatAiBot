<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\PublishGroupJobMessage;
use App\Repository\AggregatedContextGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:publish-group-contexts',
    description: 'Диспатчит PublishGroupJobMessage для неопубликованных count-based агрегаций',
)]
final class PublishGroupContextsCommand extends Command
{
    public function __construct(
        private readonly AggregatedContextGroupRepository $aggregatedContextGroupRepository,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pending = $this->aggregatedContextGroupRepository->findUnsent();

        if (empty($pending)) {
            return Command::SUCCESS;
        }

        $dispatched = 0;

        foreach ($pending as $aggregated) {
            $this->bus->dispatch(new PublishGroupJobMessage($aggregated->getIdOrFail()));
            $aggregated->markAsDispatched();
            ++$dispatched;

            $io->writeln(\sprintf(
                'Агрегация #%d (группа «%s») → PublishGroupJobMessage диспатчен',
                $aggregated->getIdOrFail(),
                $aggregated->getGroup()->getTitle() ?? '-',
            ));
        }

        $this->em->flush();

        $io->success(\sprintf('Диспатчено сообщений: %d.', $dispatched));

        return Command::SUCCESS;
    }
}
