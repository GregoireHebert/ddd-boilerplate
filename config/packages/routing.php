<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    // An empty array enables the node, matching the previous empty YAML `router:` key.
    'framework' => [
        'router' => [],
    ],
    'when@prod' => [
        'framework' => [
            'router' => [
                'strict_requirements' => null,
            ],
        ],
    ],
]);
