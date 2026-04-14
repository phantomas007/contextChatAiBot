<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Общий сервис для создания или получения пользователя по данным из Telegram update.
 * Flush остаётся ответственностью вызывающего кода.
 */
class UserUpsertService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $from Telegram "from" object
     */
    public function findOrCreate(array $from): User
    {
        $telegramUserId = (int) $from['id'];
        $user = $this->userRepository->findOneBy(['telegramUserId' => $telegramUserId]);

        if (null === $user) {
            $user = new User(
                telegramUserId: $telegramUserId,
                username: $from['username'] ?? null,
                firstName: $from['first_name'] ?? null,
            );
            $this->em->persist($user);
        }

        return $user;
    }
}
