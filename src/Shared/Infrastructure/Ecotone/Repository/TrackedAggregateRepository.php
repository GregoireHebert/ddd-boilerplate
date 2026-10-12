<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone\Repository;

use App\Shared\Domain\Models\Id;
use App\Shared\Infrastructure\Persistence\Aggregate\AggregateStore;
use Ecotone\Modelling\Attribute\Repository;
use Ecotone\Modelling\StandardRepository;

/**
 * PATH 4, option A of ADR 003: the handler lives on the aggregate and Ecotone drives the whole
 * LoadAggregate -> CallAggregate -> SaveAggregate chain, with this class as the L and the S.
 *
 * It is the answer the ADR arrived at for "which aggregates were touched, and when is the
 * message over": Ecotone already knows both, precisely, without an identity map having to infer
 * it. So this repository does not write anything. `findBy()` reads through the store, `save()`
 * only makes sure the aggregate is tracked, and
 * {@see \App\Shared\Infrastructure\Ecotone\Interceptor\SaveTrackedAggregatesInterceptor} does the
 * writing once, at the end of the command, for aggregates that arrived either way.
 *
 * One repository for every provider-backed aggregate, because `canHandle()` can just ask the
 * store whether a reader claims the class. Ecotone falls back to its own repositories for
 * anything this one refuses, which is how Doctrine-owned aggregates stay untouched by all of it.
 *
 * `$versionBeforeHandling` is Ecotone's optimistic-concurrency token, and it is where an
 * `If-Match` would come from on a remote aggregate. It is ignored here because the providers in
 * this project hand out an ETag that the aggregate carries itself
 * (`Subscription`), which survives a round trip through a queue
 * in a way a per-message integer does not.
 */
#[Repository]
final readonly class TrackedAggregateRepository implements StandardRepository
{
    public function __construct(private AggregateStore $store)
    {
    }

    #[\Override]
    public function canHandle(string $aggregateClassName): bool
    {
        return $this->store->handles($aggregateClassName);
    }

    /**
     * @param class-string        $aggregateClassName
     * @param array<string,mixed> $identifiers
     */
    #[\Override]
    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        return $this->store->load($aggregateClassName, self::identity($identifiers));
    }

    /**
     * @param array<string,mixed> $identifiers
     * @param array<string,mixed> $metadata
     */
    #[\Override]
    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $this->store->remember($aggregate, self::identity($identifiers));
    }

    /**
     * Ecotone hands identifiers back as a map keyed by the `#[Identifier]` property name, and
     * every aggregate on this path has exactly one. A composite key would need the readers to
     * take one too, and none of them do.
     *
     * @param array<string,mixed> $identifiers
     *
     * @return Id<object>
     */
    private static function identity(array $identifiers): Id
    {
        $value = reset($identifiers);

        if ($value instanceof Id) {
            return $value;
        }

        return Id::fromString((string) $value);
    }
}
