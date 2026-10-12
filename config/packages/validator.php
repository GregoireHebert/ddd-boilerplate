<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    // An empty array enables the node, matching the previous empty YAML `validation:` key.
    'framework' => [
        'validation' => [],
    ],
    'when@test' => [
        'framework' => [
            'validation' => [
                'not_compromised_password' => false,
            ],
        ],
    ],
]);
