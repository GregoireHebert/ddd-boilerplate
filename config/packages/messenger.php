<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'messenger' => [
            'failure_transport' => 'failed',
            'transports' => [
                'sync' => 'sync://',
                'async' => [
                    'dsn' => env('MESSENGER_TRANSPORT_DSN').'/async_messages'.env('AMQP_CHANNEL_SUFFIX'),
                    'retry_strategy' => [
                        'max_retries' => 3,
                    ],
                ],
                'import_pricing_grid' => [
                    'dsn' => env('MESSENGER_TRANSPORT_DSN').'/import_pricing_grid_messages'.env('AMQP_CHANNEL_SUFFIX'),
                    'retry_strategy' => [
                        'max_retries' => 3,
                    ],
                ],
                'failed' => env('MESSENGER_TRANSPORT_DSN').'/failed_messages'.env('AMQP_CHANNEL_SUFFIX'),
            ],
        ],
    ],
    /*
     * Tests must never publish to RabbitMQ: the async queues are shared with the dev worker, which
     * would consume test messages under APP_ENV=dev and replay them against the dev database and the
     * real OIDC server.
     *
     * The Ecotone channels do not use these transports in the test environment at all
     * ({@see App\Shared\Infrastructure\Ecotone\MessageChannelBuilderFactory} swaps them for in-memory
     * queue channels, drained with {@see App\Tests\Functional\AsyncMessageChannelTrait}). These
     * in-memory transports are the second line of defence, so a direct Symfony Messenger dispatch
     * cannot reach the broker either.
     */
    'when@test' => [
        'framework' => [
            'messenger' => [
                'transports' => [
                    'async' => [
                        'dsn' => 'in-memory://?serialize=true',
                    ],
                    'import_pricing_grid' => [
                        'dsn' => 'in-memory://?serialize=true',
                    ],
                    'failed' => [
                        'dsn' => 'in-memory://',
                    ],
                ],
            ],
        ],
    ],
]);
