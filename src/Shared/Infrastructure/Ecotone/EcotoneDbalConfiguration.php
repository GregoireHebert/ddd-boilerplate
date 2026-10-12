<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\ObjectManager\ObjectManagerModule;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;

/**
 * Configures ecotone/dbal for the async worker. {@see ObjectManagerModule} registers an interceptor
 * that clears (and flushes) the ObjectManager around each consumed message — the framework-native cure
 * for the stale identity map that would otherwise survive across messages on `bin/console ecotone:run async`.
 *
 * The connection is manager-registry based (over the `doctrine` registry) and named `DbalConnectionFactory::class`
 * so the ObjectManagerModule can resolve the EntityManager(s) from its default connection reference.
 *
 * Enabled, scoped to the async endpoints only:
 *  - ObjectManager clear+flush (identity-map cure);
 *  - a DB transaction per message (atomic unit-of-work: rollback on failure + Ecotone's message
 *    collector emitting outgoing messages only after commit);
 *  - deduplication (idempotency against RabbitMQ at-least-once redelivery).
 *
 * Everything else ecotone/dbal enables by default is deliberately OFF: transactions/clearing on the sync
 * command bus + console, dead-letter (retry/DLQ stay with RabbitMQ + Symfony Messenger's `failed`
 * transport — Ecotone's dead-letter is not in a Messenger-backed channel's failure path), consumer-position
 * tracking (irrelevant to an AMQP push consumer) and the document store. Automatic table initialization is
 * off too: the `ecotone_deduplication` table is created by an explicit Doctrine migration.
 */
final class EcotoneDbalConfiguration
{
    #[ServiceContext]
    public function connection(): SymfonyConnectionReference
    {
        return SymfonyConnectionReference::defaultManagerRegistry('default');
    }

    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
            // Clear + flush the ObjectManager around each async message.
            ->withClearAndFlushObjectManagerOnAsynchronousEndpoints(true)
            // Atomic unit-of-work per message: rollback on failure (no partial writes) then Messenger
            // resends, and Ecotone's message collector only emits outgoing commands/events after commit.
            ->withTransactionOnAsynchronousEndpoints(true)
            // Idempotency against RabbitMQ at-least-once redelivery. The dedup record is written inside
            // the transaction above, so "handled" and the handler's writes commit atomically.
            ->withDeduplication(true)
            // The ecotone_deduplication table is created lazily on first message.
            ->withAutomaticTableInitialization(true)
            // The sync command bus + console keep today's explicit-flush semantics (decided separately).
            ->withClearAndFlushObjectManagerOnCommandBus(true)
            ->withClearAndFlushObjectManagerOnProjectionBatch(true)
            ->withTransactionOnCommandBus(true)
            ->withTransactionOnConsoleCommands(true)
            ->withFlushWhenPersisting(true)
            // Retry/DLQ stay with RabbitMQ + Symfony Messenger's `failed` transport (Ecotone's dead-letter
            // is not in a Messenger-backed channel's failure path). Consumer tracking + document store unused.
            ->withDeadLetter(true)
            ->withConsumerPositionTracking(false)
            ->withDocumentStore(false);
    }
}
