<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

return Routes::config([
    'when@dev' => [
        '_errors' => [
            'resource' => '@FrameworkBundle/Resources/config/routing/errors.xml',
            'prefix' => '/_error',
        ],
    ],
]);
