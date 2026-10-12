<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The base for a state-level 422: the payload was fine and the world is not in the state the
 * request assumed.
 *
 * All it adds to {@see DeclaredFailure} is the marker and the SPL parent, and `\DomainException` is
 * the right one for exactly the reason this class exists: the failure is about the domain's state
 * rather than about an argument.
 *
 * It used to carry a `vsprintf` template and positional parameters of its own. ADR 007 replaced
 * that with the code and the named map every other failure in the tree now carries; see
 * {@see DomainUnprocessableThrowable} for what the old pair cost.
 */
abstract class AbstractDomainUnprocessableException extends \DomainException implements DomainUnprocessableThrowable
{
    use DeclaredFailure;
}
