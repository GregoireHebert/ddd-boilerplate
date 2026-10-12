<?php

declare(strict_types=1);

namespace App\Shared\Application\Filter;

use App\Shared\Application\ReadModel\Page;
use App\Shared\Application\ReadModel\Slice;
use App\Shared\Application\ReadModel\Total;

/**
 * What a {@see FilterSet} hands back: typed values, and the window, in one object.
 *
 * The accessors take the type they expect as an argument so that the call site stays checkable.
 * `$values->enum('status', ProfileStatus::class)` reads the same way a constructor parameter does
 * and PHPStan follows it through to the read port; a bare `get('status'): mixed` would have given
 * up exactly the guarantee option A has for free.
 *
 * `page()` and `slice()` throw when asked for the wrong one. That cannot happen through a delivery
 * built from the declaration, since the declaration picked; it can happen when somebody edits the
 * declaration from `pages()` to `slice()` and forgets a caller, which is the moment you want a loud
 * failure rather than a page of the wrong shape.
 */
final readonly class FilterValues
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private array $values,
        private Page|Slice $window,
        private Total $total = Total::None,
    ) {
    }

    /**
     * `Total::None` when the declaration does not offer the choice, which is most of them. Counting
     * is a separate question and this is the whole of its surface.
     */
    public function total(): Total
    {
        return $this->total;
    }

    /**
     * @template T of \UnitEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    public function enum(string $name, string $enum): ?\UnitEnum
    {
        $value = $this->values[$name] ?? null;

        return $value instanceof $enum ? $value : null;
    }

    public function text(string $name): ?string
    {
        $value = $this->values[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function bool(string $name): bool
    {
        return true === ($this->values[$name] ?? false);
    }

    public function date(string $name): ?\DateTimeImmutable
    {
        $value = $this->values[$name] ?? null;

        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    public function page(): Page
    {
        return $this->window instanceof Page
            ? $this->window
            : throw new \LogicException('This declaration paginates by cursor; ask it for a slice.');
    }

    public function slice(): Slice
    {
        return $this->window instanceof Slice
            ? $this->window
            : throw new \LogicException('This declaration paginates by page number; ask it for a page.');
    }
}
