<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

return Routes::config([
    '_security_logout' => [
        'resource' => 'security.route_loader.logout',
        'type' => 'service',
    ],
]);
