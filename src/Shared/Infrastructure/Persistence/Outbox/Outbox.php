<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Outbox;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * PATH 5. The write side, and it is one `persist()`.
 *
 * No flush. That is not an oversight: flushing here would commit the outbox row on its own and
 * break the only guarantee the pattern offers. The row has to come along the command's transaction,
 * which EcotoneDbalConfiguration already opens and commits.
 */
final readonly class Outbox
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(string $topic, array $payload): void
    {
        $this->entityManager->persist(OutboxMessage::record($topic, $payload, $this->clock->now()));
    }
}
