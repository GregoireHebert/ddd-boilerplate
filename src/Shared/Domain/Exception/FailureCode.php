<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * ADR 007: the published name of a failure, declared as an enum case so that the set of them is
 * *enumerable*.
 *
 * ADR 005 already required the name to be declared rather than derived, stable across refactorings,
 * namespaced by context and dot-separated (a `/` breaks `ValidationException::getId()`, which
 * composes the problem's IRI out of violation codes). What ADR 007 adds is the reason it cannot
 * stay a string literal at the throw site: the code is now also the translation key, the English is
 * in `translations/failures+intl-icu.en.xlf` and nowhere else, and a code with no entry ships its
 * own raw code to a caller.
 *
 * That makes the catalogue's completeness a correctness property, and a correctness property needs
 * something that can fail. An AST extractor that found nine codes out of ten reports success;
 * `cases()` over every implementation of this interface, checked against the catalogue, reports the
 * gap and names it. `bin/console app:messages` is that check.
 *
 * Extending `\BackedEnum` is what lets a caller hold the class string and still call `cases()`,
 * which is the whole mechanism {@see \App\Ui\Console\MessagesCommand} relies on.
 *
 * One enum per bounded context, next to that context's exceptions:
 *
 * @see SharedFailure
 * @see \App\Membership\Domain\Exception\MembershipFailure
 * @see \App\Matching\Domain\Exception\MatchingFailure
 */
interface FailureCode extends \BackedEnum
{
    /**
     * The wire name. It is the enum's own backing value; the method exists so that a
     * {@see TranslatableThrowable} can return a `non-empty-string` without every implementation
     * restating the narrowing.
     *
     * @return non-empty-string
     */
    public function code(): string;
}
