<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DailyStats;
use App\Repository\DailyStatsRepository;
use App\Repository\DeliveryErrorRepository;
use App\Service\TelegramBotClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:collect-daily-stats',
    description: 'Агрегирует статистику за вчерашний день, сохраняет в БД и отправляет отчёт администратору',
)]
final class CollectDailyStatsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DailyStatsRepository $dailyStatsRepository,
        private readonly DeliveryErrorRepository $errorRepository,
        private readonly TelegramBotClient $telegramClient,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'ADMIN_TELEGRAM_USER_ID')]
        private readonly string $adminUserId,
        #[Autowire(env: 'APP_STATS_TIMEZONE')]
        private readonly string $statsTimezone,
    ) {
        parent::__construct();
        try {
            new \DateTimeZone($this->statsTimezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid APP_STATS_TIMEZONE: %s', $this->statsTimezone), 0, $e);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tz = new \DateTimeZone($this->statsTimezone);
        $yesterdayDateStr = (new \DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d');
        $yesterday = \DateTimeImmutable::createFromFormat('!Y-m-d', $yesterdayDateStr, $tz);

        if (null !== $this->dailyStatsRepository->findByDate($yesterday)) {
            $io->warning(\sprintf('Stats for %s already collected, skipping.', $yesterday->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $io->info(\sprintf('Collecting stats for %s...', $yesterday->format('Y-m-d')));

        $stats = $this->aggregateStats($yesterday);
        $this->em->persist($stats);
        $this->em->flush();

        $io->success('Stats saved to database.');

        $report = $this->buildReport($stats, $yesterday);
        $report = mb_substr($report, 0, 4096);

        $this->telegramClient->sendMessage((int) $this->adminUserId, $report);

        $io->success('Report sent to admin via Telegram.');

        return Command::SUCCESS;
    }

    private function aggregateStats(\DateTimeImmutable $date): DailyStats
    {
        $dateStr = $date->format('Y-m-d');

        $stats = new DailyStats($date);

        $dp = $this->dateParams($dateStr);

        $stats->setMessagesTotal(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messages WHERE '.$this->sqlWhereUtcLocalDay('created_at'),
                $dp,
            ),
        );

        $stats->setActiveGroups(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(DISTINCT group_id) FROM messages WHERE '.$this->sqlWhereUtcLocalDay('created_at'),
                $dp,
            ),
        );

        $stats->setActiveUsers(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(DISTINCT telegram_user_id) FROM messages
                 WHERE '.$this->sqlWhereUtcLocalDay('created_at').' AND telegram_user_id IS NOT NULL',
                $dp,
            ),
        );

        $stats->setNewUsers(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM users WHERE '.$this->sqlWhereUtcLocalDay('registered_at'),
                $dp,
            ),
        );

        $stats->setTotalSubs(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_group_subscriptions'),
        );

        $stats->setBricksGenerated(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM contexts WHERE '.$this->sqlWhereUtcLocalDay('created_at'),
                $dp,
            ),
        );

        $stats->setAggregationsGenerated(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM aggregated_contexts_for_group WHERE '.$this->sqlWhereUtcLocalDay('created_at'),
                $dp,
            ),
        );

        $aiRow = $this->connection->fetchAssociative(
            'SELECT
                    COUNT(*)::int AS total,
                    COUNT(DISTINCT telegram_user_id)::int AS users,
                    COUNT(DISTINCT chat_id)::int AS chats
                FROM message_for_ai
                WHERE '.$this->sqlWhereUtcLocalDay('created_at'),
            $dp,
        );
        if (false === $aiRow) {
            $aiRow = ['total' => 0, 'users' => 0, 'chats' => 0];
        }
        $stats->setAiRequestsTotal((int) $aiRow['total']);
        $stats->setAiRequestsUsers((int) $aiRow['users']);
        $stats->setAiRequestsChats((int) $aiRow['chats']);

        // Доставка в группу: факт отправки в Telegram (PublishGroupJobHandler → sent_at)
        $stats->setDeliveriesGroupDaily(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM aggregated_contexts_for_group
                 WHERE '.$this->sqlWhereUtcLocalDayNotNull('sent_at')." AND settings_type = 'daily'",
                $dp,
            ),
        );

        $stats->setDeliveriesGroupCustom(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM aggregated_contexts_for_group
                 WHERE '.$this->sqlWhereUtcLocalDayNotNull('sent_at')." AND settings_type <> 'daily'",
                $dp,
            ),
        );

        // Постановка в очередь публикации в группу (PublishGroupContextsCommand → dispatched_at)
        $stats->setDeliveriesGroupDispatchedDaily(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM aggregated_contexts_for_group
                 WHERE '.$this->sqlWhereUtcLocalDayNotNull('dispatched_at')." AND settings_type = 'daily'",
                $dp,
            ),
        );

        $stats->setDeliveriesGroupDispatchedCustom(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM aggregated_contexts_for_group
                 WHERE '.$this->sqlWhereUtcLocalDayNotNull('dispatched_at')." AND settings_type <> 'daily'",
                $dp,
            ),
        );

        // ЛС: aggregated_context_dm_deliveries (фаза 2 — PublishDmJobHandler)
        $stats->setDmDeliveriesSent(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM aggregated_context_dm_deliveries
                 WHERE '.$this->sqlWhereUtcLocalDayNotNull('sent_at'),
                $dp,
            ),
        );

        $stats->setDmDeliveriesSkipped(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM aggregated_context_dm_deliveries
                 WHERE '.$this->sqlWhereUtcLocalDayNotNull('skipped_at'),
                $dp,
            ),
        );

        $stats->setDmDeliveriesQueued(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM aggregated_context_dm_deliveries
                 WHERE '.$this->sqlWhereUtcLocalDay('queued_at'),
                $dp,
            ),
        );

        $errors = $this->errorRepository->countByPrefixForDate($date, $this->statsTimezone);
        $stats->setErrorsOllama($errors['ollama']);
        $stats->setErrorsTelegram($errors['telegram']);

        return $stats;
    }

    private function buildReport(DailyStats $stats, \DateTimeImmutable $date): string
    {
        $d = $date->format('d.m.Y');
        $dateStr = $date->format('Y-m-d');

        $groupSentTotal = $stats->getDeliveriesGroupDaily() + $stats->getDeliveriesGroupCustom();
        $groupDispatchedTotal = $stats->getDeliveriesGroupDispatchedDaily()
            + $stats->getDeliveriesGroupDispatchedCustom();

        $hasErrors = $stats->getErrorsOllama() > 0 || $stats->getErrorsTelegram() > 0;

        $errorBlock = '';
        if ($hasErrors) {
            $errorBlock = "\n\n<b>⚠️ Ошибки доставки</b>\n";
            if ($stats->getErrorsOllama() > 0) {
                $errorBlock .= \sprintf("  • Ollama: %d\n", $stats->getErrorsOllama());
            }
            if ($stats->getErrorsTelegram() > 0) {
                $errorBlock .= \sprintf("  • Telegram API: %d\n", $stats->getErrorsTelegram());

                $recentErrors = $this->errorRepository->findForDate($date, $this->statsTimezone, 5);
                if (!empty($recentErrors)) {
                    $errorBlock .= "\n<b>Последние ошибки Telegram:</b>\n";
                    foreach ($recentErrors as $err) {
                        $ctx = $err->getContext();
                        $who = isset($ctx['user_id'])
                            ? \sprintf('@%s (id:%d)', $ctx['username'] ?? '?', $ctx['user_id'])
                            : \sprintf('group:%d', $ctx['group_id'] ?? 0);
                        $errorBlock .= \sprintf(
                            "  <code>%s</code> — %s\n",
                            htmlspecialchars($err->getErrorType(), \ENT_QUOTES | \ENT_XML1),
                            htmlspecialchars($who, \ENT_QUOTES | \ENT_XML1),
                        );
                    }
                }
            }
        }

        $groupDetailsBlock = $this->buildGroupDetailsBlock($this->dateParams($dateStr));

        return \sprintf(
            <<<'TXT'
                <b>📊 Статистика бота за %s</b>
                <i>Часовой пояс дня отчёта: %s · срез UTC→локаль · в БД timestamp без TZ = UTC.</i>

                <b>Поступление в таблицы (строк за день)</b>
                <i>• <code>messages</code> — только группы/супергруппы (личка сюда не пишется).</i>
                <i>• <code>message_for_ai</code> — личка и группы.</i>
                  • <code>messages</code>: %d
                  • <code>message_for_ai</code>: %d

                <b>Сообщения</b> <i>(по таблице messages — активность в группах)</i>
                  • Активных групп: %d
                  • Активных пользователей: %d
                  • Новых пользователей: %d
                  • Всего подписок: %d

                <b>AI-обработка</b> <i>(кирпичи/агрегаты — из конвейера групп)</i>
                  • Кирпичей сгенерировано: %d
                  • Агрегатов сгенерировано (aggregated_contexts_for_group, created_at): %d

                <b>Запросы к ИИ (message_for_ai)</b>
                  • Уникальных пользователей: %d
                  • Уникальных чатов: %d

                <b>Доставка в группу (aggregated_contexts_for_group, факт в Telegram — sent_at)</b>
                  • Суточные (daily): %d
                  • По порогу (count_*): %d
                  • Итого отправлено в группу: %d

                <b>Очередь публикации в группу (dispatched_at)</b>
                  • Суточные: %d
                  • По порогу: %d
                  • Итого поставлено в очередь: %d

                <b>Доставка в ЛС (aggregated_context_dm_deliveries)</b>
                  • Отправлено в ЛС (sent_at): %d
                  • Пропущено (skipped_at): %d
                  • Строк доставки создано (queued_at — постановка в очередь ЛС): %d%s%s
                TXT,
            $d,
            $this->statsTimezone,
            $stats->getMessagesTotal(),
            $stats->getAiRequestsTotal(),
            $stats->getActiveGroups(),
            $stats->getActiveUsers(),
            $stats->getNewUsers(),
            $stats->getTotalSubs(),
            $stats->getBricksGenerated(),
            $stats->getAggregationsGenerated(),
            $stats->getAiRequestsUsers(),
            $stats->getAiRequestsChats(),
            $stats->getDeliveriesGroupDaily(),
            $stats->getDeliveriesGroupCustom(),
            $groupSentTotal,
            $stats->getDeliveriesGroupDispatchedDaily(),
            $stats->getDeliveriesGroupDispatchedCustom(),
            $groupDispatchedTotal,
            $stats->getDmDeliveriesSent(),
            $stats->getDmDeliveriesSkipped(),
            $stats->getDmDeliveriesQueued(),
            $errorBlock,
            $groupDetailsBlock,
        );
    }

    /**
     * @param array{date: string, stats_tz: string} $dp
     *
     * @return array<int, array{title: string, messages: int, bricks: int, aggregationsCreated: int, aggregationsSentToGroup: int, members: int, setting: string}>
     */
    private function fetchGroupDetails(array $dp): array
    {
        $wm = $this->sqlWhereUtcLocalDay('created_at');
        $wc = $this->sqlWhereUtcLocalDay('created_at');
        $wa = $this->sqlWhereUtcLocalDay('created_at');
        $ws = $this->sqlWhereUtcLocalDayNotNull('sent_at');

        $sql = <<<SQL
            SELECT
                g.id,
                g.title,
                COALESCE(m.cnt, 0)   AS messages,
                COALESCE(c.cnt, 0)   AS bricks,
                COALESCE(a.cnt, 0)   AS aggregations_created,
                COALESCE(s.cnt, 0)   AS aggregations_sent,
                COALESCE(u.cnt, 0)   AS members,
                gs.count_threshold   AS setting
            FROM telegram_groups g
            LEFT JOIN (
                SELECT group_id, COUNT(*) AS cnt
                FROM messages WHERE {$wm}
                GROUP BY group_id
            ) m ON m.group_id = g.id
            LEFT JOIN (
                SELECT group_id, COUNT(*) AS cnt
                FROM contexts WHERE {$wc}
                GROUP BY group_id
            ) c ON c.group_id = g.id
            LEFT JOIN (
                SELECT group_id, COUNT(*) AS cnt
                FROM aggregated_contexts_for_group WHERE {$wa}
                GROUP BY group_id
            ) a ON a.group_id = g.id
            LEFT JOIN (
                SELECT group_id, COUNT(*) AS cnt
                FROM aggregated_contexts_for_group
                WHERE {$ws}
                GROUP BY group_id
            ) s ON s.group_id = g.id
            LEFT JOIN (
                SELECT group_id, COUNT(*) AS cnt
                FROM user_groups
                GROUP BY group_id
            ) u ON u.group_id = g.id
            LEFT JOIN group_settings gs ON gs.group_id = g.id
            WHERE g.is_active = true
            ORDER BY messages DESC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $dp);

        return array_map(static function (array $row): array {
            $threshold = $row['setting'];
            if (null === $threshold) {
                $setting = 'daily';
            } else {
                $setting = \sprintf('count_%d', (int) $threshold);
            }

            return [
                'title' => $row['title'] ?? 'Без названия',
                'messages' => (int) $row['messages'],
                'bricks' => (int) $row['bricks'],
                'aggregationsCreated' => (int) $row['aggregations_created'],
                'aggregationsSentToGroup' => (int) $row['aggregations_sent'],
                'members' => (int) $row['members'],
                'setting' => $setting,
            ];
        }, $rows);
    }

    /**
     * @param array{date: string, stats_tz: string} $dp
     */
    private function buildGroupDetailsBlock(array $dp): string
    {
        $groups = $this->fetchGroupDetails($dp);

        if (empty($groups)) {
            return '';
        }

        $block = "\n\n<b>📋 Детали по группам</b>\n";

        foreach ($groups as $g) {
            $title = htmlspecialchars($g['title'], \ENT_QUOTES | \ENT_XML1);
            $block .= \sprintf(
                "\n<b>%s</b> [%s]\n  💬 %d сообщ · 🧱 %d кирп · 📦 %d агрег (созд) · ✉️ %d в группу (sent_at) · 👥 %d чел\n",
                $title,
                $g['setting'],
                $g['messages'],
                $g['bricks'],
                $g['aggregationsCreated'],
                $g['aggregationsSentToGroup'],
                $g['members'],
            );
        }

        return $block;
    }

    /**
     * @return array{date: string, stats_tz: string}
     */
    private function dateParams(string $dateStr): array
    {
        return ['date' => $dateStr, 'stats_tz' => $this->statsTimezone];
    }

    /**
     * Календарная дата «дня отчёта» в часовом поясе APP_STATS_TIMEZONE.
     * Значения TIMESTAMP WITHOUT TIME ZONE в БД трактуем как UTC (как пишет Doctrine).
     */
    private function sqlWhereUtcLocalDay(string $column): string
    {
        return \sprintf('(%s AT TIME ZONE \'UTC\' AT TIME ZONE :stats_tz)::date = :date', $column);
    }

    private function sqlWhereUtcLocalDayNotNull(string $column): string
    {
        return \sprintf(
            '%1$s IS NOT NULL AND (%1$s AT TIME ZONE \'UTC\' AT TIME ZONE :stats_tz)::date = :date',
            $column,
        );
    }
}
