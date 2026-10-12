<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Aggregate;

/**
 * PATH 4. What moved, keyed by property name, with the value as it is now.
 *
 * This is capability (3) of the four ADR 003 takes a Unit of Work apart into, and the one it says
 * to add only when the remote side is `PATCH`-shaped or writes are expensive. Both are true of the
 * billing provider behind `Subscription`, so here it is.
 *
 * The values are normalised (see {@see Snapshot}), not the live objects: a changeset is a
 * statement about a comparison that already happened, and handing a writer the live value object
 * would invite it to serialize that instead of deciding how its own store wants it written.
 */
final readonly class Changes
{
    /**
     * @param array<string, mixed> $fields
     */
    private function __construct(
        public bool $isNew,
        private array $fields,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function forNew(array $state): self
    {
        return new self(true, $state);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function between(array $before, array $after): self
    {
        $changed = [];

        foreach ($after as $field => $value) {
            if (!\array_key_exists($field, $before) || $before[$field] !== $value) {
                $changed[$field] = $value;
            }
        }

        return new self(false, $changed);
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->fields;
    }

    public function touched(string ...$fields): bool
    {
        foreach ($fields as $field) {
            if (\array_key_exists($field, $this->fields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lets a writer take only the fields its own store owns, which is how one changeset feeds two
     * writers without either of them writing the other's columns.
     *
     * @return array<string, mixed>
     */
    public function only(string ...$fields): array
    {
        return array_intersect_key($this->fields, array_flip($fields));
    }
}
