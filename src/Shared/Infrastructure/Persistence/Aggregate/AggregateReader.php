<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Aggregate;

use App\Shared\Domain\Models\Id;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * PATH 4. One half of "the provider owns this aggregate".
 *
 * A reader assembles one aggregate from wherever its data actually is: a row, an HTTP response,
 * two HTTP responses, a row and an HTTP response. It is the only place in the application allowed
 * to know that, and the only place that rebuilds an aggregate without going through the named
 * constructors that enforce its invariants, because whatever it is reading passed those once.
 *
 * Deliberately not "findBy(criteria)". A reader answers "give me this one aggregate, by identity",
 * which is all a command ever needs. Lists, filters and pages are read-model questions, and they
 * go to a projection (`CandidateFeedInterface`) rather
 * than through here; asking three APIs for "all subscriptions matching X" is not a thing that
 * works.
 */
#[AutoconfigureTag('app.aggregate_reader')]
interface AggregateReader
{
    /**
     * @param class-string $aggregateClass
     */
    public function supports(string $aggregateClass): bool;

    /**
     * Deliberately not generic, although the caller is. Every implementation reads exactly one
     * class, so a `@template T` here would only be a promise each of them then has to talk PHPStan
     * out of. {@see AggregateStore::load()} holds the generics and narrows once, with an
     * `instanceof` that is a real check rather than an annotation.
     *
     * @param class-string $aggregateClass
     * @param Id<object>   $id
     */
    public function read(string $aggregateClass, Id $id): ?object;
}
