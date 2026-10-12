<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Ecotone\MessageChannelBuilderFactory;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;

/**
 * Drains an asynchronous message channel from inside the test process.
 *
 * In the test environment the Ecotone channels are in-memory queue channels
 * ({@see MessageChannelBuilderFactory}), so a message published
 * during an HTTP request stays in the booted kernel until a test asks for it to be handled. Unlike
 * {@see EcotoneEventDispatcherTrait}, which builds a separate messaging instance where the
 * `#[Asynchronous]` attribute collapses to inline execution, this runs the very consumer the worker
 * runs, on the application container: same handlers, same interceptors, same transaction per message.
 *
 * The consumer stops on the first failure and rethrows, so a failing handler fails the test with its
 * own exception instead of silently ending up in a dead letter queue.
 *
 * Usage:
 * <code>
 *     $this->client->request('POST', '/rentals', ['json' => $payload]);
 *     self::consumeAsyncMessages(); // handle everything published by the request
 * </code>
 */
trait AsyncMessageChannelTrait
{
    private static function consumeAsyncMessages(string $channelName = 'async'): void
    {
        $messagingSystem = self::getContainer()->get(ConfiguredMessagingSystem::class);
        if (!$messagingSystem instanceof ConfiguredMessagingSystem) {
            throw new \LogicException('The Ecotone messaging system is not available in the test container.');
        }

        $messagingSystem->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }
}
