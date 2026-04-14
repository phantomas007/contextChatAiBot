<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DeliveryError;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists real-time error events to delivery_errors table.
 * Always flushes immediately so records survive handler retries/failures.
 */
class DeliveryErrorLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function logOllama(
        int $groupId,
        string $groupTitle,
        string $errorMessage,
        bool $isTimeout = false,
    ): void {
        $this->persist(
            $isTimeout ? 'ollama_timeout' : 'ollama_error',
            null,
            [
                'group_id' => $groupId,
                'group_title' => $groupTitle,
                'message' => $errorMessage,
            ],
        );
    }

    public function logTelegram(
        int $code,
        ?int $userId,
        ?string $username,
        ?int $groupId,
        ?string $groupTitle,
        string $errorMessage,
    ): void {
        $type = match ($code) {
            403 => 'tg_403',
            429 => 'tg_429',
            default => 'tg_other',
        };

        $this->persist($type, $code, [
            'user_id' => $userId,
            'username' => $username,
            'group_id' => $groupId,
            'group_title' => $groupTitle,
            'message' => $errorMessage,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function persist(string $type, ?int $code, array $context): void
    {
        try {
            $error = new DeliveryError($type, $code, $context);
            $this->em->persist($error);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist delivery error', [
                'error_type' => $type,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
