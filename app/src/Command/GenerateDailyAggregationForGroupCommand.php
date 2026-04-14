<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\GenerateDailyAggregationMessage;
use App\Repository\GroupSettingsRepository;
use App\Repository\TelegramGroupRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:generate-daily-aggregation-group',
    description: 'Диспатчит GenerateDailyAggregationMessage для активных групп с включённым суточным обзором',
)]
final class GenerateDailyAggregationForGroupCommand extends Command
{
    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly GroupSettingsRepository $groupSettingsRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $groups = $this->groupRepository->findAllActive();

        if (empty($groups)) {
            $io->info('Активных групп не найдено.');

            return Command::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            $settings = $this->groupSettingsRepository->findByGroup($group);

            if (null !== $settings && !$settings->isDailyEnabled()) {
                ++$skipped;
                continue;
            }

            $groupId = $group->getId() ?? 0;
            $this->bus->dispatch(new GenerateDailyAggregationMessage($groupId));
            ++$dispatched;

            $io->writeln(\sprintf(
                'Группа #%d «%s» → GenerateDailyAggregationMessage диспатчен',
                $groupId,
                $group->getTitle() ?? '-',
            ));
        }

        if ($skipped > 0) {
            $io->note(\sprintf('Пропущено групп с выключенным суточным обзором: %d.', $skipped));
        }

        $io->success(\sprintf('Диспатчено сообщений: %d.', $dispatched));

        return Command::SUCCESS;
    }
}
