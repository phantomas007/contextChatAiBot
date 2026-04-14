<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SummaryJobMessage;
use App\Repository\MessageRepository;
use App\Repository\TelegramGroupRepository;
use App\Summary\SummaryBrickSize;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:dispatch-summary-jobs',
    description: 'Проверяет несуммаризованные сообщения по всем активным группам и диспатчит SummaryJobMessage',
)]
final class DispatchSummaryJobsCommand extends Command
{
    /**
     * Размер кирпича — SummaryBrickSize::MESSAGES_PER_BRICK (не настройка группы).
     * GroupSettings.count_threshold задаёт порог публикации (кратен размеру кирпича).
     */
    public function __construct(
        private readonly TelegramGroupRepository $groupRepository,
        private readonly MessageRepository $messageRepository,
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

        foreach ($groups as $group) {
            $count = $this->messageRepository->countUnsummarized($group);

            if ($count < SummaryBrickSize::MESSAGES_PER_BRICK) {
                continue;
            }

            $groupId = $group->getId() ?? 0;
            $this->bus->dispatch(new SummaryJobMessage($groupId));
            ++$dispatched;

            $io->writeln(\sprintf(
                'Группа #%d «%s» — %d несуммаризованных → SummaryJobMessage диспатчен',
                $groupId,
                $group->getTitle() ?? '-',
                $count,
            ));
        }

        $io->success(\sprintf(
            'Обработано групп: %d. Задач диспатчено: %d.',
            \count($groups),
            $dispatched,
        ));

        return Command::SUCCESS;
    }
}
