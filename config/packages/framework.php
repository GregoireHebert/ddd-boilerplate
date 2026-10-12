<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

// see https://symfony.com/doc/current/reference/configuration/framework.html
return App::config([
    'framework' => [
        'secret' => env('APP_SECRET'),

        // Note that the session will be started ONLY if you read or write from it.
        'session' => true,
    ],
    'when@test' => [
        'framework' => [
            'test' => true,
            'session' => [
                'storage_factory_id' => 'session.storage.factory.mock_file',
            ],
        ],
    ],
]);
