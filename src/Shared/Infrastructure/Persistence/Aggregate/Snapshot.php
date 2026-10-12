<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Aggregate;

/**
 * PATH 4. Flattens an aggregate into something two copies of which can be compared with `!==`.
 *
 * The branch this work grew out of used `get_object_vars()` and left "a deep diff should be
 * implemented" as a TODO in the class docblock. That TODO is why: every aggregate in this project
 * holds value objects, and a shallow snapshot compares object identity, so replacing a
 * `BadgeDesign` with an equal one reads as a change and mutating one in place reads as nothing
 * happening. Both are wrong, and the second is the dangerous one.
 *
 * So it recurses, and the rules are deliberately boring:
 *
 *  - enums become "Class::Case", because two cases of the same enum are the same object anyway but
 *    two enums of different classes are not comparable;
 *  - dates become a string with microseconds, because two DateTimeImmutable at the same instant
 *    are `==` but not `===`, and we compare with `===` everywhere else;
 *  - anything else that is an object becomes an array of its properties, private ones included;
 *  - a cycle stops at the object already being walked.
 *
 * It is not free. A snapshot per tracked aggregate per message, walked twice, is the price of not
 * having Doctrine's change tracker; for the handful of aggregates on this path that is nothing,
 * and for a thousand it would be the argument for going back to an ORM.
 */
final class Snapshot
{
    private const int MAX_DEPTH = 8;

    /**
     * @return array<string, mixed>
     */
    public static function of(object $aggregate): array
    {
        /** @var array<string, mixed> $state */
        $state = self::walk($aggregate, [], 0);

        return $state;
    }

    /**
     * @param array<int, true> $seen
     */
    private static function walk(mixed $value, array $seen, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '…';
        }

        if ($value instanceof \UnitEnum) {
            return $value::class.'::'.$value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if (\is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::walk($item, $seen, $depth + 1), $value);
        }

        if (!\is_object($value)) {
            return $value;
        }

        if (isset($seen[spl_object_id($value)])) {
            return '↻'.$value::class;
        }

        $seen[spl_object_id($value)] = true;
        $state = ['__class' => $value::class];

        foreach (self::properties($value) as $property) {
            if (!$property->isInitialized($value)) {
                continue;
            }

            $state[$property->getName()] = self::walk($property->getValue($value), $seen, $depth + 1);
        }

        return $state;
    }

    /**
     * @return list<\ReflectionProperty>
     */
    private static function properties(object $value): array
    {
        $properties = [];

        for ($class = new \ReflectionClass($value); false !== $class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $properties[$property->getName()] ??= $property;
            }
        }

        return array_values($properties);
    }
}
