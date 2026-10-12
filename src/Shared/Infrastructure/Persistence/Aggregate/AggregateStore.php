<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Aggregate;

use App\Shared\Domain\Models\Id;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * PATH 4. What a context's repository delegates to, so that "which reader, which writer, is it
 * already loaded, what changed" is written once instead of once per aggregate.
 *
 * Read `SubscriptionRepository` next; it
 * is nine lines, and that is the point of this class existing.
 *
 * Note the shape of `flush()`. It is not a transaction and there is no rollback: each writer
 * either succeeds or throws, and a writer that throws leaves the earlier ones done. That is not a
 * shortcoming to be fixed later, it is the fact ADR 003 closes on. Where partial failure is not
 * acceptable, the write does not belong here; it belongs behind an outbox or a saga.
 */
final readonly class AggregateStore
{
    /**
     * @param iterable<AggregateReader> $readers
     * @param iterable<AggregateWriter> $writers
     */
    public function __construct(
        #[AutowireIterator('app.aggregate_reader')]
        private iterable $readers,
        #[AutowireIterator('app.aggregate_writer')]
        private iterable $writers,
        private AggregateTracker $tracker,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $aggregateClass
     * @param Id<T>           $id
     *
     * @return T|null
     */
    public function load(string $aggregateClass, Id $id): ?object
    {
        // Identity map first, and not as an optimisation. Two handlers in one message asking for
        // the same subscription have to get the same instance, or the second one's mutations are
        // invisible to the first one's changeset and quietly never written.
        $tracked = $this->tracker->find($aggregateClass, $id);

        if (null !== $tracked) {
            return $tracked;
        }

        $aggregate = $this->readerFor($aggregateClass)->read($aggregateClass, $id);

        if (null === $aggregate) {
            return null;
        }

        // The one place the generic promise of this method is actually checked, rather than
        // asserted in a docblock. A reader that claims a class and returns something else is a
        // wiring bug, and it should not be discovered three layers up.
        if (!$aggregate instanceof $aggregateClass) {
            throw UnsupportedAggregateException::readerReturnedWrongClass($aggregateClass, $aggregate);
        }

        $this->tracker->watchLoaded($aggregate, $id);

        return $aggregate;
    }

    /**
     * @template T of object
     *
     * @param T     $aggregate
     * @param Id<T> $id
     */
    public function add(object $aggregate, Id $id): void
    {
        $this->tracker->watchNew($aggregate, $id);
    }

    /**
     * Track it if it is not already, without ever downgrading a loaded aggregate to a new one.
     * Used by {@see \App\Shared\Infrastructure\Ecotone\Repository\TrackedAggregateRepository},
     * which is handed aggregates it cannot know the provenance of.
     *
     * @template T of object
     *
     * @param T     $aggregate
     * @param Id<T> $id
     */
    public function remember(object $aggregate, Id $id): void
    {
        if ($this->tracker->isTracked($aggregate)) {
            return;
        }

        $this->tracker->watchNew($aggregate, $id);
    }

    public function remove(object $aggregate): void
    {
        $this->tracker->erase($aggregate);
    }

    /**
     * @param class-string $aggregateClass
     */
    public function handles(string $aggregateClass): bool
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($aggregateClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Called by {@see \App\Shared\Infrastructure\Ecotone\Interceptor\SaveTrackedAggregatesInterceptor}
     * at the end of the command, never by a handler.
     */
    public function flush(): void
    {
        foreach ($this->tracker->erased() as $aggregate) {
            $this->writerFor($aggregate::class)->erase($aggregate);
        }

        foreach ($this->tracker->touched() as $aggregate) {
            $changes = $this->tracker->changesOf($aggregate);

            // Nothing moved. Skipping is not just an optimisation on this path: an empty PATCH is
            // still a request, still a rate-limit token, and still a row in somebody's audit log.
            if ($changes->isEmpty()) {
                continue;
            }

            $this->writerFor($aggregate::class)->write($aggregate, $changes);
            $this->tracker->settle($aggregate);
        }
    }

    /**
     * @param class-string $aggregateClass
     */
    private function readerFor(string $aggregateClass): AggregateReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($aggregateClass)) {
                return $reader;
            }
        }

        throw UnsupportedAggregateException::noReaderFor($aggregateClass);
    }

    /**
     * @param class-string $aggregateClass
     */
    private function writerFor(string $aggregateClass): AggregateWriter
    {
        foreach ($this->writers as $writer) {
            if ($writer->supports($aggregateClass)) {
                return $writer;
            }
        }

        throw UnsupportedAggregateException::noWriterFor($aggregateClass);
    }
}
