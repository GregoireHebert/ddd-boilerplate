<?php

declare(strict_types=1);

namespace App\Ui\Console\Query;

use App\Shared\Application\ReadModel\BadFilterInput;
use App\Shared\Domain\Exception\InvalidIdentifierException;
use App\Shared\Domain\Models\Id;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The console's half of {@see \App\Ui\Api\StateProvider\QueryParameters}, and deliberately a second
 * class rather than one shared with it.
 *
 * They do the same conversions and they cannot be the same code, because what they convert *from*
 * is different in kind: a query string is always strings, while `InputInterface::getOption()`
 * returns a string for `VALUE_REQUIRED`, a bool for `VALUE_NONE` and an array for `IS_ARRAY`. ADR
 * 004 puts that difference in the UI layer on purpose ("the only layer that distinguishes a query
 * string from an `InputOption` from a form field"), and merging these two would be the first step
 * towards a generic input abstraction that pretends the distinction is not there.
 *
 * They do agree on the failure, because that is one rule and not two: the same `BadFilterInput`,
 * rendered as a 400 over there and as exit 22 here.
 */
final class InputOptions
{
    private function __construct()
    {
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    public static function enum(string $enum, string $option, InputInterface $input): ?\BackedEnum
    {
        $raw = self::text($option, $input);

        if (null === $raw) {
            return null;
        }

        return $enum::tryFrom($raw) ?? throw BadFilterInput::unknownValue(
            $option,
            $raw,
            array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases()),
        );
    }

    public static function text(string $option, InputInterface $input): ?string
    {
        $raw = $input->getOption($option);

        return \is_string($raw) && '' !== $raw ? $raw : null;
    }

    public static function integer(string $option, InputInterface $input, int $default): int
    {
        $raw = self::text($option, $input);

        if (null === $raw) {
            return $default;
        }

        $value = filter_var($raw, \FILTER_VALIDATE_INT);

        if (false === $value) {
            throw BadFilterInput::notAnInteger($option, $raw);
        }

        return $value;
    }

    /**
     * An identifier out of a console *argument*, which is the one input this class reads that is not
     * an option; `app:likes <memberId>` names the subject of the question rather than narrowing it.
     *
     * {@see \App\Ui\Api\StateProvider\QueryParameters::identifier()} carries the reasoning for why
     * this rethrows as a `BadFilterInput` instead of letting ADR 005's 422 stand: an identifier the
     * caller typed on the command line is part of what was asked, so it fails the way a bad
     * `--page` fails, with exit code 22.
     *
     * @template A of object
     *
     * @param class-string<A> $of
     *
     * @return Id<A>
     */
    public static function identifierArgument(string $argument, InputInterface $input, string $of): Id
    {
        $raw = (string) $input->getArgument($argument);

        try {
            /** @var Id<A> */
            return Id::fromString($raw);
        } catch (InvalidIdentifierException) {
            throw BadFilterInput::notAnIdentifier($argument, $raw);
        }
    }

    public static function date(string $option, InputInterface $input): ?\DateTimeImmutable
    {
        $raw = self::text($option, $input);

        if (null === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\DateMalformedStringException) {
            throw BadFilterInput::notADate($option, $raw);
        }
    }
}
