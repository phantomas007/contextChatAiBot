<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiPrivateReengageTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiPrivateReengageTemplate>
 */
class AiPrivateReengageTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiPrivateReengageTemplate::class);
    }

    public function findRandomActive(): ?AiPrivateReengageTemplate
    {
        $templates = $this->findBy(['isActive' => true], ['id' => 'ASC']);
        if ([] === $templates) {
            return null;
        }

        return $templates[random_int(0, \count($templates) - 1)];
    }
}
