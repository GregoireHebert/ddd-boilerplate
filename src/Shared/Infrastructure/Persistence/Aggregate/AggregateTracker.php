<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Aggregate;

use App\Shared\Domain\Models\Id;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * PATH 4. Capabilities (1) and (2) of the four ADR 003 takes "Unit of Work" apart into, and the
 * two the whole exercise turned out to actually need:
 *
 *  1. an identity map, so that within one message the same aggregate id is the same instance;
 *  2. which aggregates were touched, so something can save them at the end.
 *
 * (3) changesets it does as well, because the billing provider is `PATCH`-shaped. (4) cross-store
 * atomicity it does not do, cannot do, and does not pretend to; that is what the outbox and the
 * saga are for.
 *
 * Scope is one message. The `#[Around]` interceptor clears it after every command, and it is also
 * tagged `kernel.reset` so the existing ResetServicesInterceptor empties it between async messages
 * and Symfony empties it between requests. An identity map that outlives its message is not a
 * cache, it is a bug with good manners.
 */
#[Autoconfigure(tags: [['name' => 'kernel.reset', 'method' => 'reset']])]
final class AggregateTracker implements ResetInterface
{
    /** @var array<string, object> */
    private array $identityMap = [];

    /** @var array<int, object> */
    private array $touched = [];

    /** @var array<int, array<string, mixed>|null> null means "created in this message" */
    private array $snapshots = [];

    /** @var array<int, object> */
    private array $erased = [];

    /**
     * @template T of object
     *
     * @param class-string<T> $aggregateClass
     * @param Id<T>           $id
     *
     * @return T|null
     */
    public function find(string $aggregateClass, Id $id): ?object
    {
        $aggregate = $this->identityMap[self::key($aggregateClass, $id)] ?? null;

        return $aggregate instanceof $aggregateClass ? $aggregate : null;
    }

    /**
     * @template T of object
     *
     * @param T     $aggregate
     * @param Id<T> $id
     */
    public function watchLoaded(object $aggregate, Id $id): void
    {
        $this->register($aggregate, $id, Snapshot::of($aggregate));
    }

    /**
     * @template T of object
     *
     * @param T     $aggregate
     * @param Id<T> $id
     */
    public function watchNew(object $aggregate, Id $id): void
    {
        $this->register($aggregate, $id, null);
    }

    public function isTracked(object $aggregate): bool
    {
        return \array_key_exists(spl_object_id($aggregate), $this->snapshots);
    }

    public function erase(object $aggregate): void
    {
        $this->erased[spl_object_id($aggregate)] = $aggregate;

        unset($this->touched[spl_object_id($aggregate)]);
    }

    public function changesOf(object $aggregate): Changes
    {
        $before = $this->snapshots[spl_object_id($aggregate)] ?? null;
        $after = Snapshot::of($aggregate);

        return null === $before ? Changes::forNew($after) : Changes::between($before, $after);
    }

    /**
     * Called once a writer has accepted the aggregate, so a second flush in the same message does
     * not send the same fields again.
     */
    public function settle(object $aggregate): void
    {
        $this->snapshots[spl_object_id($aggregate)] = Snapshot::of($aggregate);
    }

    /**
     * @return list<object>
     */
    public function touched(): array
    {
        return array_values($this->touched);
    }

    /**
     * @return list<object>
     */
    public function erased(): array
    {
        return array_values($this->erased);
    }

    #[\Override]
    public function reset(): void
    {
        $this->identityMap = [];
        $this->touched = [];
        $this->snapshots = [];
        $this->erased = [];
    }

    /**
     * @template T of object
     *
     * @param T                         $aggregate
     * @param Id<T>                     $id
     * @param array<string, mixed>|null $snapshot
     */
    private function register(object $aggregate, Id $id, ?array $snapshot): void
    {
        $this->identityMap[self::key($aggregate::class, $id)] = $aggregate;
        $this->touched[spl_object_id($aggregate)] = $aggregate;
        $this->snapshots[spl_object_id($aggregate)] = $snapshot;
    }

    /**
     * @param class-string $aggregateClass
     * @param Id<object>   $id
     */
    private static function key(string $aggregateClass, Id $id): string
    {
        return $aggregateClass.'#'.$id->toString();
    }
}
