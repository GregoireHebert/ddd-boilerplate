<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

return Routes::config([
    'api_platform' => [
        'resource' => '.',
        'type' => 'api_platform',

        // Stays `/`, and ADR 009 rule 1 is why: one resource, one URL, with the representation
        // chosen by `Accept` rather than by a path prefix. This read `/api` for about fourteen
        // hours, to stop API Platform's routes colliding with a Twig delivery mounted at the same
        // root. The collision is real; the answer is a media type, not a second set of URLs for
        // the same nouns.
        'prefix' => '/',
    ],
]);
