<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * When should I use this?
 *
 * 422 Unprocessable Entity is typically reserved for cases where:
 *
 * The request is syntactically correct;
 * The server understands the request structure;
 * But the request cannot be processed due to semantic errors (like business logic violations);
 *
 * For example, `?isActive=ture` represents a client-side error in request formation rather than a
 * semantic/business logic issue. The client sent malformed data that doesn't conform to the
 * expected format.
 *
 * This marker states which kind of failure occurred. What the API answers with is the delivery
 * adapter's decision, declared in `config/packages/api_platform.php` under `exception_to_status`.
 *
 * ## What ADR 007 took off it
 *
 * This interface used to declare its own `getMessageTemplate()` and `getMessageParameters()` pair,
 * with a `vsprintf` template and *positional* parameters. That pair is the reason the
 * implementation this ADR was started from needed a sprintf-to-ICU converter: the sentences were
 * written for `vsprintf` and the catalogue wanted ICU, so ninety-five lines of regex over printf's
 * grammar stood between the two.
 *
 * {@see TranslatableThrowable} replaces it with a code and a *named* map, so there is nothing to
 * convert and a translator reordering a sentence is reordering `{fromMemberId}` rather than
 * `{arg1}`. This marker is now as pure as its five neighbours.
 */
interface DomainUnprocessableThrowable extends TranslatableThrowable
{
}
