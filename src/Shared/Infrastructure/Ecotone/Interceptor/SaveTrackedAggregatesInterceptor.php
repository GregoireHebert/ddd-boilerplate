<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone\Interceptor;

use App\Shared\Infrastructure\Persistence\Aggregate\AggregateStore;
use App\Shared\Infrastructure\Persistence\Aggregate\AggregateTracker;
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;

/**
 * PATH 4. What actually writes a provider-owned aggregate, for both options ADR 003 describes:
 * the handlers on `Subscription` (option A, driven by Ecotone's
 * aggregate flow) and handlers that stay in an Application service (option B). Either way the
 * aggregate ends up in the tracker, and either way this is the one place that writes it.
 *
 * It saves everything that was loaded during the command, not only what a handler chose to mark.
 * That sounds careless and is not, because of CQS: a command handler loads an aggregate in order
 * to change it. If it only wanted to look, it would be a query. The changeset then filters out
 * whatever really did not move, so "loaded and untouched" costs one comparison and no write.
 *
 * ## Why the pointcut is the bus and not `CommandHandler::class`
 *
 * That was the first attempt, and it is wrong in a way worth recording because nothing fails
 * loudly. An `#[Around]` on `CommandHandler` wraps the *handler method*, which for an aggregate is
 * only the CallAggregate step of Ecotone's LoadAggregate -> CallAggregate -> SaveAggregate chain.
 * It returns before SaveAggregate has told the repository anything, so `flush()` ran over an empty
 * tracker, the `finally` below cleared the identity map, and SaveAggregate then registered an
 * aggregate nothing would ever write. No error, no write, and a subscription that still read back
 * correctly from the identity map for the rest of the request.
 *
 * Intercepting the bus puts this outside the whole chain, where "the message is over" is true.
 *
 * ## Why this precedence
 *
 * Lower precedence is more outer. `DATABASE_TRANSACTION_PRECEDENCE` (-2000) opens the transaction
 * and `DATABASE_OBJECT_MANAGER_PRECEDENCE` (-1997) flushes the EntityManagers; one more than that
 * puts us inside both, so DBAL writes issued here join the command's transaction and still run
 * before Doctrine's own flush.
 *
 * With the limitation that implies written down rather than hidden: a SQL writer's work rolls back
 * with everything else, an HTTP writer's does not. Nothing here makes a cross-store write atomic.
 * When that matters the write goes through the outbox or a saga; see ADR 003's last rule.
 *
 * The query pointcut only clears. A query that loaded an aggregate must not leave it in the
 * identity map for the next message, and must never cause a write.
 */
final readonly class SaveTrackedAggregatesInterceptor
{
    public function __construct(
        private AggregateStore $store,
        private AggregateTracker $tracker,
    ) {
    }

    #[Around(precedence: Precedence::DATABASE_OBJECT_MANAGER_PRECEDENCE + 1, pointcut: CommandBus::class)]
    public function saveTouched(MethodInvocation $methodInvocation): mixed
    {
        try {
            $result = $methodInvocation->proceed();

            $this->store->flush();

            return $result;
        } finally {
            $this->tracker->reset();
        }
    }

    #[Around(precedence: Precedence::DATABASE_OBJECT_MANAGER_PRECEDENCE + 1, pointcut: QueryBus::class)]
    public function forgetAfterQuery(MethodInvocation $methodInvocation): mixed
    {
        try {
            return $methodInvocation->proceed();
        } finally {
            $this->tracker->reset();
        }
    }
}
