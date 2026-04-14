<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\BotBlockedByUserException;
use App\Exception\TelegramRateLimitException;
use App\Repository\AiPrivateReengageTemplateRepository;
use App\Repository\MessageForAiRepository;
use App\Repository\UserRepository;
use App\Service\TelegramBotClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:send-ai-private-reengage',
    description: 'Напоминание в ЛС пользователям, давно не писавшим ИИ в личке (шаблон из БД, случайный)',
)]
final class SendAiPrivateReengageCommand extends Command
{
    public function __construct(
        private readonly MessageForAiRepository $messageForAiRepository,
        private readonly UserRepository $userRepository,
        private readonly AiPrivateReengageTemplateRepository $templateRepository,
        private readonly TelegramBotClient $telegramClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly int $inactiveDays,
        private readonly int $cooldownDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать число получателей, без отправки');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $tzUtc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tzUtc);
        $inactiveBefore = $now->modify(\sprintf('-%d days', $this->inactiveDays));
        $cooldownBefore = $now->modify(\sprintf('-%d days', $this->cooldownDays));

        $inactiveIds = $this->messageForAiRepository->findTelegramUserIdsPrivateAiLastActivityBefore($inactiveBefore);
        $users = $this->userRepository->findEligibleForAiPrivateReengage($inactiveIds, $cooldownBefore);

        $io->info(\sprintf(
            'Неактивность: последняя активность ЛС с ИИ раньше %s (UTC). Cooldown: last_sent раньше %s или не задано.',
            $inactiveBefore->format('Y-m-d H:i:s'),
            $cooldownBefore->format('Y-m-d H:i:s'),
        ));

        if ([] === $users) {
            $io->success('Нет получателей.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note(\sprintf('Dry-run: было бы отправлено сообщений: %d.', \count($users)));

            return Command::SUCCESS;
        }

        if (0 === $this->templateRepository->count(['isActive' => true])) {
            $io->error('В ai_private_reengage_template нет активных записей (is_active = true).');

            return Command::FAILURE;
        }

        $sent = 0;
        $blocked = 0;
        foreach ($users as $user) {
            $template = $this->templateRepository->findRandomActive();
            if (null === $template) {
                break;
            }

            $text = $template->getBody();

            try {
                $this->telegramClient->sendMessage($user->getTelegramUserId(), $text);
            } catch (BotBlockedByUserException) {
                $user->markBotChatBlocked();
                $this->em->flush();
                ++$blocked;

                continue;
            } catch (TelegramRateLimitException $e) {
                $this->logger->warning('Telegram rate limit при реактивации ЛС', [
                    'message' => $e->getMessage(),
                ]);
                $io->warning('Лимит Telegram, остановка. Уже отправлено: '.$sent);

                return Command::FAILURE;
            } catch (\Throwable $e) {
                $this->logger->error('Ошибка отправки реактивации ЛС', [
                    'telegram_user_id' => $user->getTelegramUserId(),
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $user->markAiReengageSent($now);
            $this->em->flush();
            ++$sent;
        }

        $io->success(\sprintf('Отправлено: %d, заблокировали бота: %d.', $sent, $blocked));

        return Command::SUCCESS;
    }
}
