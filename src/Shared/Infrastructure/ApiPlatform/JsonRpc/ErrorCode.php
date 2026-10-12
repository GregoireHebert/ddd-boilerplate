<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

/**
 * The whole error vocabulary of this API's RPC surface, in one place, because JSON-RPC puts the
 * failure taxonomy inside a `200` instead of in the status line.
 *
 * The first five are the specification's own (§5.1) and their numbers are not ours to choose. The
 * rest live in `-32000` to `-32099`, which §5.1 reserves for "implementation-defined server-error",
 * and each one is the RPC spelling of a marker interface in `Shared/Domain/Exception/`. That
 * pairing is the point: the same throwable that becomes a `404` on the REST surface becomes a
 * `-32000` here, decided once in {@see RpcErrorFactory} rather than per method.
 *
 * Two things the specification is explicit about and this enum has to respect:
 *
 * - **the code is an integer.** ADR 005's published failure names (`membership.handle-too-short`)
 *   are strings and cannot be it. They travel in `data` instead, where the violations already are,
 *   so a caller who routes on them routes on the same value over both deliveries;
 * - **`-32768` to `-32000` is reserved.** Anything outside it belongs to the application, so a new
 *   marker interface gets the next free number *below* `-32004` and inside `-32099`, never an
 *   invented positive code.
 */
enum ErrorCode: int
{
    /** Invalid JSON was received. */
    case ParseError = -32700;

    /** The JSON sent is not a valid Request object. */
    case InvalidRequest = -32600;

    /** The method does not exist or is not exposed. */
    case MethodNotFound = -32601;

    /** Invalid method parameter(s). ADR 005 stages 1 and 2 land here, violations and all. */
    case InvalidParams = -32602;

    /** Anything this application failed to name. Nothing about it reaches the caller. */
    case InternalError = -32603;

    /** {@see \App\Shared\Domain\Exception\DomainNotFoundThrowable}, the REST `404`. */
    case NotFound = -32000;

    /** {@see \App\Shared\Domain\Exception\ConflictThrowable}, the REST `409`. */
    case Conflict = -32001;

    /** {@see \App\Shared\Domain\Exception\FailedDependencyThrowable}, the REST `424`. */
    case FailedDependency = -32002;

    /** {@see \App\Shared\Domain\Exception\DomainUnprocessableThrowable}, the state-level `422`. */
    case UnprocessableState = -32003;

    /** {@see \App\Shared\Domain\Exception\BadRequestThrowable}, ADR 004's `400`. */
    case BadRequest = -32004;

    /**
     * {@see \App\Shared\Domain\Exception\ForbiddenThrowable}, the REST `403`. ADR 008's
     * question 2, refused, and the next free number below `-32004` exactly as this enum's own
     * docblock instructs.
     *
     * **There is no case for `401`, deliberately.** ADR 006 is categorical that everything this
     * endpoint decides is a `200`, and ADR 008 amends it by one case: an unauthenticated call
     * leaves in the status line, because it is not this endpoint deciding. RFC 9110 requires a
     * `401` to carry `WWW-Authenticate`, a JSON-RPC response object has nowhere to put a
     * challenge, and a caller learning they must authenticate from a field inside a `200` learns
     * it from a response every HTTP client already treated as success.
     *
     * A *forbidden* call is the opposite and belongs here: the actor is known, there is nothing to
     * challenge, and the application really did decide.
     */
    case Forbidden = -32005;

    /**
     * The short sentence §5.1 asks for. It is the default and a call site may replace it, but it is
     * never *absent*: a caller switching on `code` still gets something readable next to it.
     */
    public function message(): string
    {
        return match ($this) {
            self::ParseError => 'Parse error',
            self::InvalidRequest => 'Invalid Request',
            self::MethodNotFound => 'Method not found',
            self::InvalidParams => 'Invalid params',
            self::InternalError => 'Internal error',
            self::NotFound => 'Not found',
            self::Conflict => 'Conflict',
            self::FailedDependency => 'Failed dependency',
            self::UnprocessableState => 'Unprocessable state',
            self::BadRequest => 'Bad request',
            self::Forbidden => 'Forbidden',
        };
    }

    /**
     * What the REST surface would have answered, for the table in the generated document and for
     * nothing else. No code reads it to make a decision; it is here so the two surfaces can be
     * documented side by side from one source instead of from a table somebody keeps in sync by
     * hand.
     */
    public function restEquivalent(): int
    {
        return match ($this) {
            self::ParseError, self::InvalidRequest, self::BadRequest => 400,
            self::MethodNotFound, self::NotFound => 404,
            self::InvalidParams, self::UnprocessableState => 422,
            self::Forbidden => 403,
            self::InternalError => 500,
            self::Conflict => 409,
            self::FailedDependency => 424,
        };
    }
}
