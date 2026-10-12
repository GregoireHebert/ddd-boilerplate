<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Outbox;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * PATH 5. The read side. Picks up rows nobody has sent, calls the handler, records what happened.
 *
 * Three decisions in here are worth more than the code itself.
 *
 * **One transaction per message, not one per batch.** A batch-wide transaction would mean that
 * the fourteenth message failing un-marks the thirteen that already went out over HTTP, and those
 * would then be sent again. Per message, a failure costs exactly one retry.
 *
 * **Mark after, not before.** Marking first would turn every transport blip into a lost
 * notification; marking after turns it into a duplicate. Between a message that might arrive twice
 * and a message that might never arrive, the first is the one you would appologise for.
 *
 * **Exhausted rows stay.** After MAX_ATTEMPTS the relay stops trying and leaves the row with its
 * error, for a person to look at. An outbox that deletes what it cannot deliver is indistinguishable
 * from one that works.
 */
final readonly class OutboxRelay
{
    /**
     * @param iterable<OutboxHandler> $handlers
     */
    public function __construct(
        #[AutowireIterator('app.outbox_handler')]
        private iterable $handlers,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function drain(int $batchSize = 50): array
    {
        $report = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->pending($batchSize) as $message) {
            if ($message->isExhausted()) {
                ++$report['skipped'];

                continue;
            }

            $handler = $this->handlerFor($message->topic());

            if (null === $handler) {
                $message->markFailed(\sprintf('No OutboxHandler claims topic "%s".', $message->topic()));
                ++$report['failed'];
                $this->entityManager->flush();

                continue;
            }

            try {
                $handler->handle($message->payload());
                $message->markSent($this->clock->now());
                ++$report['sent'];
            } catch (\Throwable $throwable) {
                $message->markFailed($throwable->getMessage());
                ++$report['failed'];

                $this->logger->warning('Outbox delivery failed.', [
                    'outboxId' => $message->id()->toString(),
                    'topic' => $message->topic(),
                    'attempts' => $message->attempts(),
                    'exception' => $throwable,
                ]);
            }

            $this->entityManager->flush();
        }

        return $report;
    }

    /**
     * @return list<OutboxMessage>
     */
    private function pending(int $batchSize): array
    {
        return $this->entityManager
            ->createQuery(\sprintf(
                'SELECT m FROM %s m WHERE m.sentAt IS NULL AND m.attempts < :max ORDER BY m.recordedAt ASC',
                OutboxMessage::class,
            ))
            ->setParameter('max', OutboxMessage::MAX_ATTEMPTS)
            ->setMaxResults($batchSize)
            ->getResult();
    }

    private function handlerFor(string $topic): ?OutboxHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($topic)) {
                return $handler;
            }
        }

        return null;
    }
}
