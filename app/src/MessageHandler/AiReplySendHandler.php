<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MessageForAi;
use App\Message\AiReplySendMessage;
use App\Repository\MessageForAiRepository;
use App\Service\TelegramBotClient;
use App\Telegram\AiReplyFoldCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AiReplySendHandler
{
    private const int LOCK_TTL = 60;

    private const int TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly MessageForAiRepository $messageForAiRepository,
        private readonly TelegramBotClient $telegramClient,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly AiReplyFoldCache $aiReplyFoldCache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AiReplySendMessage $message): void
    {
        $lock = $this->lockFactory->createLock(
            'ai_reply_send_'.$message->messageForAiId,
            ttl: self::LOCK_TTL,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            return;
        }

        try {
            $entity = $this->messageForAiRepository->find($message->messageForAiId);
            if (!$entity instanceof MessageForAi) {
                return;
            }

            if (null !== $entity->getSentAt()) {
                return;
            }

            $body = $this->buildOutgoingText($entity);
            if ('' === $body) {
                return;
            }

            $escaped = $this->escapeTelegramHtml($body);
            if (mb_strlen($escaped) > self::TELEGRAM_MAX_MESSAGE_LENGTH) {
                $escaped = mb_substr($escaped, 0, self::TELEGRAM_MAX_MESSAGE_LENGTH - 1).'…';
            }

            try {
                if ($this->hasSuccessfulAiResponse($entity)) {
                    $sentMessageId = $this->telegramClient->sendMessageReplyWithKeyboard(
                        $entity->getChatId(),
                        $escaped,
                        $entity->getReplyToMessageId(),
                        [
                            [
                                [
                                    'text' => '🫥 Свернуть',
                                    'callback_data' => 'ai_toggle',
                                ],
                            ],
                        ],
                        $entity->getMessageThreadId(),
                    );
                    $this->aiReplyFoldCache->save($entity->getChatId(), $sentMessageId, $escaped);
                } else {
                    $this->telegramClient->sendMessageReply(
                        $entity->getChatId(),
                        $escaped,
                        $entity->getReplyToMessageId(),
                        $entity->getMessageThreadId(),
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Ai reply Telegram send failed', [
                    'id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $entity->setSentAt(new \DateTimeImmutable());
            $this->em->flush();
        } finally {
            $lock->release();
        }
    }

    private function buildOutgoingText(MessageForAi $entity): string
    {
        $response = $entity->getResponseText();
        if (\is_string($response) && '' !== trim($response)) {
            return trim($response);
        }

        $err = $entity->getErrorMessage();
        if (\is_string($err) && '' !== $err) {
            return 'Не удалось получить ответ от ИИ. Попробуйте позже.';
        }

        return '';
    }

    /** Есть нормальный текст от модели — показываем кнопку свернуть и кладём полный текст в Redis. */
    private function hasSuccessfulAiResponse(MessageForAi $entity): bool
    {
        $response = $entity->getResponseText();

        return \is_string($response) && '' !== trim($response);
    }

    private function escapeTelegramHtml(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
