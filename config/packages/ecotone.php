<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'ecotone' => [
        // Ecotone has its own attribute loader and psr-4 autoload which is not compatible with functions files, we need to override it
        'loadSrcNamespaces' => false,
        // A new bounded context means a new line here, or its attributes go unscanned.
        'namespaces' => [
            'App\Shared\Infrastructure',
            'App\Ui',
            // **Adding a bounded context means adding its root namespace here**, one line, or every
            // `#[CommandHandler]`, `#[QueryHandler]` and `#[EventHandler]` in it is simply never
            // found. There is no error for this: the handler does not exist as far as Ecotone is
            // concerned, and the bus answers "no handler" at runtime.
            //
            //     'App\Membership',
            //     'App\Matching',
        ],
        'defaultSerializationMediaType' => 'application/json',
    ],
]);
