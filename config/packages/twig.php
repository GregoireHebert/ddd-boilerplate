<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'twig' => [
        'file_name_pattern' => '*.twig',
    ],
    'when@test' => [
        'twig' => [
            'strict_variables' => true,
        ],
    ],
]);
