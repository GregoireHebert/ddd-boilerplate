<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * When should I use this?
 *
 * 400 Bad Request is used when:
 *
 * The request is malformed or syntactically incorrect;
 * The server cannot understand the request due to invalid syntax;
 * The client sent data that doesn't conform to the expected format;
 *
 * For example `?date=invalid-format` represents a client-side error in the request formation.
 * The client sent malformed data that doesn't conform to the expected format.
 *
 * This marker states which kind of failure occurred. What the API answers with is the delivery
 * adapter's decision, declared in `config/packages/api_platform.php` under `exception_to_status`.
 *
 * ADR 007 makes it extend {@see TranslatableThrowable}: every failure that reaches an edge carries
 * a declared code and named parameters, and the reader's language comes out of the `failures`
 * catalogue. The two interfaces answer two questions, *what kind of failure* and *how is it
 * rendered*, and no marker gets to skip the second.
 */
interface BadRequestThrowable extends TranslatableThrowable
{
}
