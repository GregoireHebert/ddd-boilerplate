<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone\Interceptor;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Precedence;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ensures the Doctrine connection is alive before an asynchronous message is handled on a long-running
 * worker (`bin/console ecotone:run async`). While the consumer blocks on RabbitMQ, the idle PostgreSQL
 * connection can be dropped by the server, a pooler or the network.
 * Since doctrine/dbal 4 no longer reconnects automatically, the next query would fail with
 * "SQLSTATE[HY000] General error: 7 no connection to the server" and crash the worker.
 *
 * This runs once per consumed message (pointcut {@see AsynchronousRunningEndpoint}), just before the
 * database transaction opens and inside the instant-retry loop, so each attempt gets a healthy connection.
 * A dead connection is closed so DBAL re-establishes it lazily on the next query — the EntityManager keeps
 * the same Connection instance and is unaffected.
 *
 * @see https://docs.ecotone.tech/modelling/extending-messaging-middlewares/interceptors#before-interceptor
 *
 * @author Grégoire Hébert <contact@gheb.dev>
 */
final readonly class DatabaseReconnectInterceptor
{
    public function __construct(
        private ManagerRegistry $registry,
        #[Autowire('@monolog.logger.messaging')]
        private LoggerInterface $logger,
    ) {
    }

    #[Before(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: AsynchronousRunningEndpoint::class)]
    public function reconnect(): void
    {
        foreach ($this->registry->getConnections() as $name => $connection) {
            /** @var Connection $connection */
            if (!$connection->isConnected()) {
                continue;
            }

            try {
                $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            } catch (\Throwable $throwable) {
                $this->logger->warning('Stale database connection detected before async handling, forcing reconnection.', [
                    'connection' => $name,
                    'exception' => $throwable,
                ]);

                $connection->close();
            }
        }
    }
}
