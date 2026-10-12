<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone;

use Ecotone\Messaging\Attribute\Parameter\ConfigurationVariable;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\MessageChannelBuilder;

/**
 * @see https://docs.ecotone.tech/modules/symfony/symfony-messenger-transport
 * @see MessageChannelBuilderFactory for the test-environment channel
 */
final class AsyncConfiguration
{
    #[ServiceContext]
    public function __invoke(#[ConfigurationVariable('kernel.environment')] string $environment): MessageChannelBuilder
    {
        return MessageChannelBuilderFactory::create('async', $environment);
    }
}
