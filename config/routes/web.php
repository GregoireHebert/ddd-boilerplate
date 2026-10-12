<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

/*
 * Attribute routes on the controllers under `src/Ui/Web/`, discovered rather than listed, which
 * matches `Ui/Api`'s resources being found by `resource_class_directories` instead of enumerated.
 *
 * The directory holds one controller at the end of this commit and that is correct: ADR 001's
 * "where does a page live" section decides that `Ui/Web/<Context>/<Name>Controller` is where a
 * Twig delivery goes, and the twenty nine page cards each add one controller and one template
 * and nothing else.
 */
return Routes::config([
    'web' => [
        'resource' => '../../src/Ui/Web/',
        'type' => 'attribute',
    ],
]);
