<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\CheckAggregationMessage;
use App\Repository\GroupSettingsRepository;
use App\Repository\TelegramGroupRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:check-aggregation-group',
    description: 'Диспатчит CheckAggregationMessage для групп с настроенным count-based порогом',
)]
final class CheckAggregationGroupCommand extends Command
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
        $skippedNoThreshold = 0;

        foreach ($groups as $group) {
            $settings = $this->groupSettingsRepository->findByGroup($group);

            if (null === $settings || null === $settings->getCountThreshold()) {
                ++$skippedNoThreshold;

                continue;
            }

            $groupId = $group->getId() ?? 0;
            $this->bus->dispatch(new CheckAggregationMessage($groupId));
            ++$dispatched;

            $io->writeln(\sprintf(
                'Группа #%d «%s» (порог %d) → CheckAggregationMessage диспатчен',
                $groupId,
                $group->getTitle() ?? '-',
                $settings->getCountThreshold(),
            ));
        }

        if (0 === $dispatched && $skippedNoThreshold > 0) {
            $io->warning(
                'Ни у одной активной группы не задан count-based порог (25/50/100/150/200/300). '
                .'Сообщения в очередь aggregation_checks не отправляются. '
                .'Runpod (агрегация) вызывается только из воркера, когда накопилось достаточно кирпичей.',
            );
        }

        $io->success(\sprintf(
            'Проверено групп: %d. Задач диспатчено: %d.',
            \count($groups),
            $dispatched,
        ));

        return Command::SUCCESS;
    }
}
