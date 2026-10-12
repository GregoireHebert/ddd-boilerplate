<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Outbox;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a topic actually does once the relay picks the row up. This is where the HTTP call lives,
 * safely outside anybody's transaction.
 *
 * `handle()` must be idempotent, because the relay is at-least-once: a handler that succeeds and
 * then loses the connection before the row is marked sent will be called again with the same
 * payload. There is no configuration that removes that; it is the price of not having distributed
 * transactions, and it is cheaper than the alternative.
 */
#[AutoconfigureTag('app.outbox_handler')]
interface OutboxHandler
{
    public function supports(string $topic): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
