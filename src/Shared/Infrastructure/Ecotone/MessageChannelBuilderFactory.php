<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;

/**
 * Builds the asynchronous channels, backed by a Symfony Messenger transport (e.g. RabbitMQ) everywhere but
 * in the test environment.
 *
 * Tests must not publish to RabbitMQ: the queues are shared with the dev worker, which runs under
 * APP_ENV=dev and would replay test messages against the dev database. They get an in-memory queue
 * channel instead, drained on demand by the functional tests' AsyncMessageChannelTrait. The handler
 * side is unchanged: same polling endpoint, same interceptors, same transaction per message.
 *
 * The payload is still converted to its serialized form, so a message that cannot survive the hop
 * fails in the test suite rather than on the worker.
 */
final class MessageChannelBuilderFactory
{
    public static function create(string $channelName, string $environment): MessageChannelBuilder
    {
        if ($environment === 'test') {
            return SimpleMessageChannelBuilder::createQueueChannel(
                $channelName,
                conversionMediaType: MediaType::APPLICATION_X_PHP_SERIALIZED,
            );
        }

        return SymfonyMessengerMessageChannelBuilder::create($channelName);
    }
}
