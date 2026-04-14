<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AggregatedContextDmDelivery;
use App\Message\PublishDmJobMessage;
use App\Repository\AggregatedContextDmDeliveryRepository;
use App\Repository\AggregatedContextGroupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Фаза 1: создаёт строки «к отправке» и диспатчит PublishDmJobMessage (фаза 2 — PublishDmJobHandler).
 */
#[AsCommand(
    name: 'app:dispatch-aggregated-dm-jobs',
    description: 'Создаёт записи доставки в ЛС и диспатчит задачи отправки для опубликованных в группе агрегатов',
)]
final class DispatchAggregatedDmJobsCommand extends Command
{
    public function __construct(
        private readonly AggregatedContextDmDeliveryRepository $dmDeliveryRepository,
        private readonly AggregatedContextGroupRepository $aggregatedContextGroupRepository,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pairs = $this->dmDeliveryRepository->findNewDispatchPairs();

        if ([] === $pairs) {
            return Command::SUCCESS;
        }

        $created = [];
        foreach ($pairs as $pair) {
            $aggregated = $this->aggregatedContextGroupRepository->find($pair['aggId']);
            $user = $this->userRepository->find($pair['userId']);

            if (null === $aggregated || null === $user) {
                continue;
            }

            if (null === $aggregated->getSentAt()) {
                continue;
            }

            $delivery = new AggregatedContextDmDelivery($aggregated, $user);
            $this->em->persist($delivery);
            $created[] = $delivery;
        }

        $this->em->flush();

        $dispatched = 0;
        foreach ($created as $delivery) {
            $this->bus->dispatch(new PublishDmJobMessage($delivery->getIdOrFail()));
            ++$dispatched;
            $io->writeln(\sprintf(
                'Доставка #%d (агрегат #%d, user #%d) → PublishDmJobMessage',
                $delivery->getIdOrFail(),
                $delivery->getAggregatedGroupContext()->getIdOrFail(),
                $delivery->getUser()->getId() ?? 0,
            ));
        }

        $io->success(\sprintf('Создано строк доставки: %d, диспатчено в очередь: %d.', \count($created), $dispatched));

        return Command::SUCCESS;
    }
}
