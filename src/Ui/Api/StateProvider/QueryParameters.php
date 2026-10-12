<?php

declare(strict_types=1);

namespace App\Ui\Api\StateProvider;

use App\Shared\Application\ReadModel\BadFilterInput;
use App\Shared\Domain\Exception\InvalidIdentifierException;
use App\Shared\Domain\Models\Id;

/**
 * Turning `$context['filters']`, which is all strings, into the typed values a query object takes.
 *
 * This is shared between providers, and it is worth being precise about what that does and does not
 * say about ADR 004's option A. What is shared here is the *mechanics*: how a string becomes an
 * integer, what happens when it will not, and the fact that the failure is a `BadFilterInput`
 * rather than a default. What is emphatically not shared is the vocabulary: every provider still
 * names its own parameters, one call at a time, and so does every console command. That restatement
 * is option A's cost, and it survives this class intact.
 *
 * An empty value is read as an absent one throughout (`?color=` means "no colour filter"), because
 * a form that submits its untouched fields is the common case and rejecting it would make a browser
 * unusable for no gain.
 */
final class QueryParameters
{
    private function __construct()
    {
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T>      $enum
     * @param array<string, mixed> $filters
     *
     * @return T|null
     */
    public static function enum(string $enum, string $key, array $filters): ?\BackedEnum
    {
        $raw = self::text($key, $filters);

        if (null === $raw) {
            return null;
        }

        return $enum::tryFrom($raw) ?? throw BadFilterInput::unknownValue(
            $key,
            $raw,
            array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases()),
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function text(string $key, array $filters): ?string
    {
        $raw = $filters[$key] ?? null;

        return \is_string($raw) && '' !== $raw ? $raw : null;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function bool(string $key, array $filters, bool $default = false): bool
    {
        $raw = self::text($key, $filters);

        if (null === $raw) {
            return $default;
        }

        if (!\in_array($raw, ['true', 'false', '1', '0'], true)) {
            throw BadFilterInput::notABoolean($key, $raw);
        }

        return 'true' === $raw || '1' === $raw;
    }

    /**
     * No clamping, ever. `Page` and `Slice` refuse anything below 1 and the query handler applies
     * the ceiling, so this layer never repairs a value it was given.
     *
     * @param array<string, mixed> $filters
     */
    public static function integer(string $key, array $filters, int $default): int
    {
        $raw = self::text($key, $filters);

        if (null === $raw) {
            return $default;
        }

        $value = filter_var($raw, \FILTER_VALIDATE_INT);

        if (false === $value) {
            throw BadFilterInput::notAnInteger($key, $raw);
        }

        return $value;
    }

    /**
     * An identifier out of a query string or a URI segment, and the one place in this class where
     * ADR 004 and ADR 005 have to be reconciled rather than merely coexist.
     *
     * `Id::fromString()` refuses a malformed ULID with an `UnprocessablePayloadThrowable`, which
     * `exception_to_status` answers **422**, and that is right for a request body: the request was
     * understood and its content is unacceptable. It is wrong here. ADR 004 rule 11 says a value
     * that will not parse out of the *request line* is a **400**, and the OpenAPI description
     * promises callers exactly one status for that class of mistake. `?memberId=banana` and
     * `?page=0` are the same kind of wrong.
     *
     * So the edge decides, which is what ADR 005 says an edge is for: the same failure, rethrown as
     * the status that fits where the value came from. A write processor reading the same identifier
     * out of a JSON body does not call this, lets the 422 stand, and collects it with its siblings.
     *
     * `$of` names what the identifier identifies, and ADR 002 is why it has to be said out loud:
     * `Id` is one class with a phantom `@template`, so nothing at runtime distinguishes an
     * `Id<Member>` from an `Id<Pair>` and only PHPStan can hold the difference. It cannot infer the
     * parameter from a string, so the call site states it, exactly as
     * {@see \App\Shared\Application\Validation\ParsedPayload::get()} does.
     *
     * @template A of object
     *
     * @param array<string, mixed> $filters
     * @param class-string<A>      $of
     *
     * @return Id<A>
     */
    public static function identifier(string $key, array $filters, string $of): Id
    {
        $raw = self::text($key, $filters)
            ?? throw BadFilterInput::missing($key);

        return self::identifierFrom($key, $raw, $of);
    }

    /**
     * The same, for a value that arrived as a URI variable rather than a query parameter. Same
     * status for the same reason: a path segment is part of what was asked, not part of the content.
     *
     * @template A of object
     *
     * @param class-string<A> $of
     *
     * @return Id<A>
     */
    public static function identifierFrom(string $key, string $raw, string $of): Id
    {
        try {
            /** @var Id<A> */
            return Id::fromString($raw);
        } catch (InvalidIdentifierException) {
            throw BadFilterInput::notAnIdentifier($key, $raw);
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function date(string $key, array $filters): ?\DateTimeImmutable
    {
        $raw = self::text($key, $filters);

        if (null === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\DateMalformedStringException) {
            throw BadFilterInput::notADate($key, $raw);
        }
    }
}
