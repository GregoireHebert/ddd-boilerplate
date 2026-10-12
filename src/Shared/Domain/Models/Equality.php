<?php

declare(strict_types=1);

namespace App\Shared\Domain\Models;

/**
 * Structural equality for value objects, which PHP's `==` gets wrong often enough to be worth
 * spelling out: two `Id`s wrapping the same ULID are the same identifier, and two `Handle`s
 * holding the same string are the same handle, regardless of which code path built them.
 *
 * It lived under `Shared\Domain\Validation` until ADR 005 deleted that module, and it was the only
 * thing anyone ever imported from it. It was never validation: comparing two values that already
 * exist is the opposite of deciding whether one may be built.
 */
interface Equality
{
    /**
     * @param mixed $that
     */
    public function equals($that): bool;
}
