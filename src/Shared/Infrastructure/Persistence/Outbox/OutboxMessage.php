<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Outbox;

use App\Shared\Domain\Models\Id;

/**
 * PATH 5. One row, written inside the same transaction as the change that caused it.
 *
 * That sentence is the entire pattern. A command that both inserts a Pair and notifies two members
 * cannot do both atomically, because one of them is a database and the other is somebody's HTTP
 * API. So the command does not notify: it writes a row saying a notification is owed, in the
 * transaction that inserts the Pair. Either both land or neither does, and a separate relay turns
 * the row into the call afterwards.
 *
 * What that buys is not "no failure". It is *at-least-once with a record*: the notification may go
 * out twice, and the row says so, which is a problem you can see and reason about. The alternative,
 * calling the API inside the transaction, fails as "the pair exists but nobody was told", silently,
 * and only under rollback.
 *
 * A Doctrine entity, deliberately. The outbox is the one place on path 5 where being inside
 * Doctrine's UnitOfWork is the whole point: `persist()` here joins the flush the command was
 * already going to do.
 */
final class OutboxMessage
{
    public const int MAX_ATTEMPTS = 5;

    /** @var Id<self> */
    private readonly Id $id;

    private int $attempts = 0;

    private ?\DateTimeImmutable $sentAt = null;

    private ?string $lastError = null;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        private readonly string $topic,
        private readonly array $payload,
        private readonly \DateTimeImmutable $recordedAt,
    ) {
        /** @var Id<self> $id */
        $id = Id::generate();
        $this->id = $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function record(string $topic, array $payload, \DateTimeImmutable $at): self
    {
        return new self($topic, $payload, $at);
    }

    /**
     * @return Id<self>
     */
    public function id(): Id
    {
        return $this->id;
    }

    public function topic(): string
    {
        return $this->topic;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function isSent(): bool
    {
        return null !== $this->sentAt;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Exhausted rows stay in the table rather than being deleted or retried forever. Somebody has
     * to look at them, and an outbox that silently drops its own failures is worse than not having
     * one, because it looks like it worked.
     */
    public function isExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function markSent(\DateTimeImmutable $at): void
    {
        ++$this->attempts;
        $this->sentAt = $at;
        $this->lastError = null;
    }

    public function markFailed(string $error): void
    {
        ++$this->attempts;
        $this->lastError = mb_substr($error, 0, 500);
    }
}
