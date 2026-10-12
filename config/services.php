<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Ecotone\Dbal\DbalConnection;
use Enqueue\Dbal\DbalConnectionFactory;

// This file is the entry point to configure your own services.
// Files in the packages/ subdirectory configure your dependencies.
//
// Services declared here are autowired and autoconfigured by default,
// App::config() takes care of the `_defaults` section.
return App::config([
    'services' => [
        // makes classes in src/ available to be used as services
        // this creates a service per class whose id is the fully-qualified class name
        // Application is in there because Ecotone's Symfony bundle resolves every
        // #[CommandHandler]/#[QueryHandler]/#[EventHandler] class out of the container and throws
        // at compile time when one is missing (RequiredReferencesCompilerPass). Domain stays out:
        // nothing in it is ever constructed by the container.
        'App\\' => [
            // `Api` is in there for ADR 001's `Ui/Api/{StateProvider,StateProcessor,RPCProcessor}`:
            // a state provider calls the query bus, so it is a delivery handler and not framework
            // plumbing, and the container has to know about it for API Platform to resolve it.
            // `Web` is the same argument one directory over: a Twig controller is a delivery
            // handler that calls the bus, exactly like a state provider, and Symfony's controller
            // resolver refuses a controller with constructor arguments that is not in the container.
            'resource' => '../src/**/{Api,Application,Console,Infrastructure,Web}/',
            'exclude' => [
                // Commands, queries and events are serialized payloads, not services.
                '../src/**/Application/{Command,Query,Event}/',
                // Same idea at the other end of the pipe: an API Platform resource class is a
                // metadata declaration read by `resource_class_directories`, never constructed by
                // the container.
                '../src/Ui/Api/Resources/',
                // The two directories below declare classes in the *vendor's* namespaces
                // (`ApiPlatform\…`, `Translation\…`) rather than in `App\…`, so the glob above
                // would derive a class name that does not exist in them. They are registered
                // separately, further down, under the prefix they actually carry.
                '../src/Shared/Infrastructure/{ApiPlatform,Translation}/',
                // Same reason, one level up: `Shared/Application` is the read side's vocabulary
                // (ADR 004). Page requests, page results and filter declarations are values that
                // travel inside a query, not things the container ever builds.
                '../src/Shared/Application/',
                // ADR 008's vocabulary, same reasoning one directory over: an `Actor` is who is
                // asking and a `SecurityUser` is what Symfony calls them, and both are values an
                // edge builds with `new`. The `Gate`, the voter, the registry and the role map in
                // the same directory *are* services and stay registered. `Permission/` holds
                // enums, which the container skips anyway; it is listed so the split reads as a
                // decision rather than as luck.
                // `Credential` and `RevokedToken` join them for the same reason one directory
                // further in: a Doctrine entity is hydrated by reflection through a private
                // constructor, never built by the container. `AccessToken` is there as a value, like
                // `Actor` beside it: ADR 009 rule 9 stores no token of ours anywhere, so it is
                // something `AccessTokens` returns rather than anything the container holds.
                // `Credentials`, `CredentialProvider`, `AccessTokens`, `RevokedTokens`,
                // `JwtTokenHandler` and the two `CurrentActor` implementations beside them *are*
                // services and stay registered.
                // `Guarded` joins them for the same reason: an attribute is a declaration read by
                // reflection, never a service the container builds.
                '../src/Shared/Infrastructure/Security/{Actor.php,AccessToken.php,SecurityUser.php,Credential.php,RevokedToken.php,Guarded.php,Unguarded.php,Permission}',
                '../src/Shared/Infrastructure/Symfony/Kernel.php',
            ],
        ],

        // The classes that extend API Platform and Symfony's translator rather than this
        // application, and therefore carry those libraries' namespaces instead of `App\`. They sit
        // under `Shared/Infrastructure/` like every other adapter; only the prefix differs, so they
        // need a registration of their own rather than an entry in the glob above.
        'ApiPlatform\\' => [
            'resource' => '../src/Shared/Infrastructure/ApiPlatform/',
            'exclude' => [
                // The same reason `Ui/Api/Resources/` is excluded above: an `#[ApiResource]` class
                // is a metadata declaration read by `resource_class_directories`, never a service.
                '../src/Shared/Infrastructure/ApiPlatform/JsonRpc/JsonRpcResource.php',
            ],
        ],

        'Translation\\' => [
            'resource' => '../src/Shared/Infrastructure/Translation/',
        ],

        // add more service definitions when explicit configuration is needed
        // please note that last definitions always *replace* previous ones

        DbalConnectionFactory::class => [
            'factory' => [DbalConnection::class, 'createForManagerRegistry'],
            'arguments' => [service('doctrine'), 'default'],
        ],
    ],
]);
