<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * ADR 007: a failure carries a declared code and named parameters. It does not carry a sentence,
 * in any language, including English.
 *
 * ## Two questions, two interfaces
 *
 * The six markers in this directory answer *what kind of failure is this*, and
 * `config/packages/api_platform.php` turns that into a status while
 * {@see \ApiPlatform\JsonRpc\RpcErrorFactory} turns it into an integer. This
 * one answers *how is it rendered*, which is an orthogonal question with a single answer for the
 * whole tree: look the code up in the `failures` catalogue for the reader's locale.
 *
 * Every one of the six extends it, because there is no marker for which rendering is optional:
 * `RpcErrorFactory` has an arm reading a message for each of them. What does *not* extend it is a
 * failure with no marker at all, and {@see \App\Shared\Infrastructure\Persistence\Aggregate\UnsupportedAggregateException}
 * is the one in the tree: "this application was assembled wrong" has no status and no reader, so it
 * has no code either.
 *
 * ## Why the message is not here
 *
 * The shape is PSR-3's, and Symfony's security component has kept its own domain free of sentences
 * for fifteen years with the same pair (`AuthenticationException::getMessageKey()` and
 * `getMessageData()`). Keeping an English sentence here *as well as* in the catalogue was the
 * alternative, and it loses for the reason ADR 005 rejects every other duplicated predicate: two
 * Englishes for one failure drift, and the one that drifts is the one nobody reads.
 *
 * So `getMessage()` returns the code. A stack trace reads `membership.handle-too-short` with the
 * parameters next to it, which is greppable in a way a sentence is not.
 *
 * ## The parameters
 *
 * Named, never positional. Positional parameters are what forced the sprintf-to-ICU converter this
 * ADR deleted before writing: `{arg1}` and `{arg2}` cannot be reordered by a translator who has no
 * way to know what either one is.
 *
 * Their values cite domain constants, never literals repeating them. ADR 005 lock 9 says a Symfony
 * constraint's arguments must be `Handle::MIN_LENGTH` rather than `3`; a message parameter is the
 * same rule with a translation department in front of it.
 */
interface TranslatableThrowable extends \Throwable
{
    /**
     * Declared, never derived. Deriving it from `static::class` would publish our namespace as
     * public contract and turn every rename into a breaking change for callers who never asked how
     * we file our exceptions (ADR 005, and it is why
     * {@see \ApiPlatform\Provider\ErrorTypingProvider} no longer reflects on
     * class names).
     *
     * It travels three ways: into the `code` of every violation API Platform renders, into the
     * RFC 9457 `type` as the specific part of an RFC 4151 tag URI, and into the `failures`
     * catalogue as the key the reader's language is found under.
     *
     * @return non-empty-string
     */
    public function code(): string;

    /**
     * The ICU arguments for this failure's catalogue entry, keyed by the name the entry uses.
     *
     * @return array<string, scalar>
     */
    public function parameters(): array;
}
