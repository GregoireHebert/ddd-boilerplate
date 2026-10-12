<?php

declare(strict_types=1);

namespace App\Shared\Application\Validation;

/**
 * What {@see Violations::orThrow()} hands back: every field, parsed, keyed by the pointer it was
 * parsed under.
 *
 * This class exists for one reason and the ADR would rather say it than let the next reader guess:
 * **it is here so PHPStan can see that nothing is null.** The obvious design has `at()` return
 * `T|null` and a separate `throwIfAny()`, which reads better by one line and then hands
 * `Handle|null` to a constructor that wants `Handle`. PHPStan cannot narrow that, because the
 * narrowing fact lives in a side effect on another object. At level 6 it is an error per field.
 *
 * Suppressing those would be the same mistake `InspectPairCommand`
 * is kept around to warn about: ADR 002's whole bet is that `Id<A>` holds because PHPStan checks
 * it, not because PHP does. A validation helper that has to be silenced to compile would be
 * arguing the opposite. So the pointer is named twice, once to parse and once to read, and
 * {@see self::get()} carries the generic that makes the second one exact.
 */
final readonly class ParsedPayload
{
    /**
     * @param array<string, object> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function get(string $pointer, string $type): object
    {
        $value = $this->values[$pointer]
            ?? throw new \LogicException(\sprintf('Nothing was parsed at "%s".', $pointer));

        if (!$value instanceof $type) {
            throw new \LogicException(\sprintf(
                '"%s" parsed into %s, not %s.',
                $pointer,
                $value::class,
                $type,
            ));
        }

        return $value;
    }

    /**
     * Both throws above are `LogicException` and neither implements a marker, so neither is ever an
     * HTTP status: they fire when the pointer read does not match the pointer parsed, which is a
     * typo in the calling adapter and not something a caller can provoke. Whatever a caller sent
     * either parsed or is already in a `ConstraintViolationList` by the time this object exists.
     *
     * There is deliberately no accessor for scalars. A value that survived parsing and is still an
     * `int` or a `string` is a value object someone has not written yet, and ADR 005 would rather
     * that be inconvenient than routine.
     */
    public function has(string $pointer): bool
    {
        return isset($this->values[$pointer]);
    }
}
