<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Aggregate;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * PATH 4. The other half.
 *
 * `write()` receives the aggregate and the {@see Changes} since it was read, which is what lets
 * the same call become `Connection::update($table, $changed, $id)` on one side and
 * `PATCH /subscriptions/{id}` with the changed fields on the other. A writer that only received
 * the aggregate would have to send everything, every time, and on a `PATCH`-shaped API that is the
 * difference between "the member changed their plan" and "the member overwrote their whole
 * subscription with what we last read".
 *
 * Nothing in here is transactional and nothing pretends to be. When a write spans two stores the
 * answer is an outbox or a saga, not this interface; see ADR 003's closing rule and
 * `src/Shared/Infrastructure/Persistence/Outbox`.
 */
#[AutoconfigureTag('app.aggregate_writer')]
interface AggregateWriter
{
    /**
     * @param class-string $aggregateClass
     */
    public function supports(string $aggregateClass): bool;

    public function write(object $aggregate, Changes $changes): void;

    public function erase(object $aggregate): void;
}
