<?php

declare(strict_types=1);

namespace App\Shared\Application\Filter;

use App\Shared\Application\ReadModel\BadFilterInput;

/**
 * One declared filter: its name, its kind, what it is for, and how a string becomes its value.
 *
 * Parsing lives here rather than in a parser class because the kind and its conversion are the same
 * fact said twice, and splitting them is how a declaration and its parser drift. A delivery asks
 * the declaration what can be asked and gets back objects that already know how to read themselves.
 *
 * `enum()` accepts a pure enum as readily as a backed one, which is not a nicety: `ProfileStatus` in
 * this project is pure, deliberately, so the "what is its string form" question has no answer until
 * something decides one. `PureEnumType` decided the case *name* for storage, so this agrees with it,
 * case-insensitively, and the two stay in step because there is only one reasonable answer.
 */
final readonly class Filter
{
    /**
     * @param list<string>                 $allowed
     * @param class-string<\UnitEnum>|null $enum
     */
    private function __construct(
        public string $name,
        public FilterKind $kind,
        public string $description,
        public array $allowed = [],
        public ?string $enum = null,
    ) {
    }

    /**
     * @param class-string<\UnitEnum> $enum
     */
    public static function enum(string $name, string $enum, string $description): self
    {
        return new self($name, FilterKind::Enumeration, $description, self::labels($enum), $enum);
    }

    public static function text(string $name, string $description): self
    {
        return new self($name, FilterKind::Text, $description);
    }

    public static function bool(string $name, string $description): self
    {
        return new self($name, FilterKind::Boolean, $description);
    }

    public static function date(string $name, string $description): self
    {
        return new self($name, FilterKind::Date, $description);
    }

    /**
     * @param class-string<\UnitEnum> $enum
     *
     * @return list<string>
     */
    public static function labels(string $enum): array
    {
        return array_map(
            static fn (\UnitEnum $case): string => $case instanceof \BackedEnum ? (string) $case->value : $case->name,
            $enum::cases(),
        );
    }

    /**
     * An absent value and an empty one mean the same thing: this filter was not used. A form that
     * submits its untouched fields is the common case, and refusing it would buy nothing.
     */
    public function parse(mixed $raw): mixed
    {
        $text = \is_string($raw) && '' !== $raw ? $raw : null;

        if (null === $text) {
            return FilterKind::Boolean === $this->kind ? false : null;
        }

        return match ($this->kind) {
            FilterKind::Text => $text,
            FilterKind::Boolean => $this->toBoolean($text),
            FilterKind::Date => $this->toDate($text),
            FilterKind::Enumeration => $this->toCase($text),
        };
    }

    private function toCase(string $raw): \UnitEnum
    {
        $enum = $this->enum ?? throw new \LogicException('An enumeration filter with no enum behind it; use Filter::enum() to build one.');

        foreach ($enum::cases() as $case) {
            $label = $case instanceof \BackedEnum ? (string) $case->value : $case->name;

            if (0 === strcasecmp($label, $raw)) {
                return $case;
            }
        }

        throw BadFilterInput::unknownValue($this->name, $raw, $this->allowed);
    }

    private function toBoolean(string $raw): bool
    {
        if (!\in_array($raw, ['true', 'false', '1', '0'], true)) {
            throw BadFilterInput::notABoolean($this->name, $raw);
        }

        return 'true' === $raw || '1' === $raw;
    }

    private function toDate(string $raw): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($raw);
        } catch (\DateMalformedStringException) {
            throw BadFilterInput::notADate($this->name, $raw);
        }
    }
}
