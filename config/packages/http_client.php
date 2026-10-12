<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/**
 * One scoped client per external service, rather than one shared `HttpClientInterface` that every
 * gateway then configures by hand.
 *
 * A scoped client called `billing.client` becomes a service of that name *and* an autowiring alias
 * on the argument name, so a constructor asking for `HttpClientInterface $billingClient` gets this
 * one and cannot accidentally get another service's credentials. That is the actual reason to
 * bother: base URI and auth token stop being something each gateway remembers to apply.
 *
 * `retry_failed` is on everywhere and is safe to leave on, because Symfony only retries idempotent
 * methods (GET, HEAD, PUT, DELETE, OPTIONS, TRACE) and only on 423/425/429/5xx. POST and PATCH are
 * never retried, which matters here: retrying `POST /subscriptions` would open a second
 * subscription, and retrying a `PATCH` guarded by `If-Match` would fail the second time anyway.
 *
 * The delivery retry that *does* cover writes is the outbox's, one attempt per relay run, with the
 * row recording how many there have been.
 */
return App::config([
    'framework' => [
        'http_client' => [
            'default_options' => [
                'timeout' => 5,
                'max_duration' => 15,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ],
            'scoped_clients' => [
                'billing.client' => [
                    'base_uri' => env('BILLING_BASE_URI'),
                    'auth_bearer' => env('BILLING_TOKEN'),
                    'retry_failed' => ['max_retries' => 2],
                ],
                'catalogue.client' => [
                    'base_uri' => env('CATALOGUE_BASE_URI'),
                    'auth_bearer' => env('CATALOGUE_TOKEN'),
                    // Read-mostly and cacheable upstream, so it is the one worth retrying hardest.
                    'retry_failed' => ['max_retries' => 3],
                ],
                'moderation.client' => [
                    'base_uri' => env('MODERATION_BASE_URI'),
                    'auth_bearer' => env('MODERATION_TOKEN'),
                    'retry_failed' => ['max_retries' => 2],
                ],
                'verification.client' => [
                    'base_uri' => env('IDENTITY_BASE_URI'),
                    'auth_bearer' => env('IDENTITY_TOKEN'),
                    'retry_failed' => ['max_retries' => 2],
                ],
                'notification.client' => [
                    'base_uri' => env('NOTIFICATIONS_BASE_URI'),
                    'auth_bearer' => env('NOTIFICATIONS_TOKEN'),
                    // Deliberately none. Delivery is a POST, so Symfony would not retry it anyway,
                    // and the outbox is already the retry mechanism for this one.
                ],
            ],
        ],
    ],
]);
