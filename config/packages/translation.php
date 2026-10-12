<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/**
 * ADR 007. The whole of locale resolution, and none of it is code.
 *
 * `Symfony\Component\HttpKernel\EventListener\LocaleListener` reads `Accept-Language`, negotiates it
 * against `enabled_locales`, falls back to `default_locale`, and sets the result on the request and
 * on the translator. Writing that ourselves would mean reimplementing a quality-value grammar with
 * a wildcard, a range-matching rule and a fallback chain, worse.
 *
 * The consequence downstream is the one that matters: **no signature anywhere carries a locale for
 * hard-coded text**. The translator is request-scoped by the framework, so
 * {@see \Translation\FailureMessages} asks for a message without saying in
 * which language and gets the caller's. That is safe only because phpat narrows
 * `Symfony\Contracts\Translation` to `App\Ui`: the ambient locale has exactly one reader.
 */
return App::config([
    'framework' => [
        // What the console renders in, what a message consumer would render in if one ever did,
        // and where `Accept-Language` lands when it asks for something we do not have.
        'default_locale' => 'en',

        // The closed set `Accept-Language` is negotiated against, and the set
        // `bin/console app:messages` checks every declared failure code against.
        //
        // This literal is the single home for it *today*, and that is a consequence of ADR 007
        // deciding not to ship a `Locale` value object yet. Nothing in either bounded context holds
        // a locale: no aggregate stores one, no command carries one, and the notification gateway
        // hands a recipient reference to a provider that knows its own users' languages. An enum
        // whose only consumer is a config file is what ADR 005 deleted `Shared/Domain/Validation/`
        // for. The day a `LocalizedText` lands, this becomes
        // `array_column(Locale::cases(), 'value')` and the home moves inward, which is the other
        // half of why every file in `config/` is PHP.
        'enabled_locales' => ['en', 'fr'],

        // A preference, not a parameter. Unlike ADR 004's filter values, an unparseable or
        // unsupported `Accept-Language` is never a 400: RFC 9110 §12.5.4 lets a server ignore the
        // header entirely, so it negotiates down to `default_locale` instead of refusing.
        'set_locale_from_accept_language' => true,

        // Negotiation the caller cannot observe is indistinguishable from a bug, so the response
        // says which language it picked. Its other half is `Accept-Language` in the `vary` list in
        // `config/packages/api_platform.php`, without which a shared cache stores one language and
        // serves it to everybody.
        'set_content_language_from_locale' => true,

        'translator' => [
            'default_path' => '%kernel.project_dir%/translations',
            'providers' => [],
        ],
    ],
]);
