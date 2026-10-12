<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * When should I use this?
 *
 * 404 Not Found is typically reserved for cases where:
 *
 * The request is syntactically correct;
 * The server understands the request structure;
 * But the request cannot be processed because either an entity does not exist or the user does not have sufficient permissions;
 *
 * This marker states which kind of failure occurred. What the API answers with is the delivery
 * adapter's decision, declared in `config/packages/api_platform.php` under `exception_to_status`.
 *
 * ADR 007 makes it extend {@see TranslatableThrowable}: every failure that reaches an edge carries
 * a declared code and named parameters, and the reader's language comes out of the `failures`
 * catalogue. The two interfaces answer two questions, *what kind of failure* and *how is it
 * rendered*, and no marker gets to skip the second.
 */
interface DomainNotFoundThrowable extends TranslatableThrowable
{
}
