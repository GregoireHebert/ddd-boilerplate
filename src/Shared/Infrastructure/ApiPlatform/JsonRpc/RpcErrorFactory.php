<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

use App\Shared\Application\Validation\InvalidPayload;
use App\Shared\Application\Validation\Violations;
use App\Shared\Domain\Exception\BadRequestThrowable;
use App\Shared\Domain\Exception\ConflictThrowable;
use App\Shared\Domain\Exception\DomainNotFoundThrowable;
use App\Shared\Domain\Exception\DomainUnprocessableThrowable;
use App\Shared\Domain\Exception\FailedDependencyThrowable;
use App\Shared\Domain\Exception\ForbiddenThrowable;
use App\Shared\Domain\Exception\UnprocessablePayloadThrowable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Translation\FailureMessages;

/**
 * The RPC half of `exception_to_status`.
 *
 * `config/packages/api_platform.php` maps the marker interfaces in `Shared/Domain/Exception/` onto
 * HTTP statuses for the REST surface. This maps the same markers onto {@see ErrorCode}s, and the
 * two tables are the whole difference between the deliveries: one domain, one set of exceptions,
 * two vocabularies. A new marker interface is added in both places or it is answered as
 * `-32603 Internal error`, loudly, in the logs.
 *
 * ## `data` carries what the status line cannot
 *
 * ADR 005 promises that every failure a caller can act on comes back as a `violations` array with a
 * `propertyPath` and a `code`. That promise is not about HTTP, so it holds here: stage 1 and stage 2
 * both land in `-32602`, and `data.violations` is the array API Platform's
 * `AbstractConstraintViolationListNormalizer` would have rendered, field for field. A client that
 * already parses the REST `422` body parses this one.
 *
 * ## The third renderer
 *
 * ADR 007 makes that identity go one level deeper. Every arm below asks
 * {@see FailureMessages} for its sentence, which is the same object the REST `422` and the console
 * ask, so `-32602` over `POST /rpc` and `422` over `POST /likes` are now the same words in the same
 * language for the same mistake, and the language is whichever one the caller's `Accept-Language`
 * negotiated. Nothing in this class knows what that language is; the translator is request-scoped
 * by the framework.
 *
 * ## The one message a caller never sees
 *
 * Everything reaching the last arm failed in a way this application did not name, so it answers the
 * specification's flat "Internal error" and says nothing else. `APP_DEBUG` reopens it, exactly as
 * {@see \ApiPlatform\Provider\ErrorTypingProvider} reopens the RFC 9457
 * `detail` for a `5xx`, and for the same reason: the person who needs the class name is running the
 * thing locally, not calling it.
 */
final readonly class RpcErrorFactory
{
    public function __construct(
        private FailureMessages $messages,
        #[Autowire('%kernel.debug%')]
        private bool $debug = false,
    ) {
    }

    public function from(\Throwable $failure): RpcError
    {
        return match (true) {
            // The protocol already decided; it arrives carrying its own answer.
            $failure instanceof RpcException => $failure->error(),

            // ADR 005 stage 1: every field attempted, everything that refused collected.
            $failure instanceof InvalidPayload => RpcError::of(
                ErrorCode::InvalidParams,
                $this->messages->render($failure),
                self::violations($this->messages->translate($failure->getConstraintViolationList())),
            ),

            // Stage 2, reported in stage 1's shape. `Violations::of()` carries the reasoning: the
            // failure belongs to the relationship between two fields, so its `propertyPath` is
            // empty rather than one of them picked at random.
            $failure instanceof UnprocessablePayloadThrowable => RpcError::of(
                ErrorCode::InvalidParams,
                $this->messages->render($failure),
                self::violations($this->messages->translate(Violations::of($failure)->getConstraintViolationList())),
            ),

            // Stage 3. These needed the store, so they are never lists and they never carry a
            // `violations` array. Under ADR 007 the domain no longer wrote a message either: it
            // declared a code, and the sentence comes out of the `failures` catalogue in whichever
            // language the caller asked for.
            $failure instanceof DomainNotFoundThrowable => RpcError::of(ErrorCode::NotFound, $this->messages->render($failure)),
            $failure instanceof ConflictThrowable => RpcError::of(ErrorCode::Conflict, $this->messages->render($failure)),
            $failure instanceof FailedDependencyThrowable => RpcError::of(ErrorCode::FailedDependency, $this->messages->render($failure)),
            $failure instanceof DomainUnprocessableThrowable => RpcError::of(ErrorCode::UnprocessableState, $this->messages->render($failure)),
            $failure instanceof BadRequestThrowable => RpcError::of(ErrorCode::BadRequest, $this->messages->render($failure)),

            // ADR 008 question 2, refused. It carries no `violations` array and no parameters at
            // all: naming the permission would publish the shape of the policy to somebody who has
            // just demonstrated they are not entitled to know it, so it goes in the audit line at
            // the edge instead.
            //
            // `UnauthenticatedThrowable` has no arm here on purpose. It is the one failure this
            // endpoint does not answer with a `200`; {@see ErrorCode::Forbidden} carries the
            // argument, and `JsonRpcServer` re-throws it so the status line can hold the
            // `WWW-Authenticate` challenge JSON-RPC has nowhere to put.
            $failure instanceof ForbiddenThrowable => RpcError::of(ErrorCode::Forbidden, $this->messages->render($failure)),

            // Nothing below here is ours, so there is no code to look up and nothing to render.
            // `getMessage()` is whatever vendor or SPL wrote, in English, and it only leaves the
            // process at all when `APP_DEBUG` is on.
            default => RpcError::of(
                ErrorCode::InternalError,
                data: $this->debug ? ['exception' => $failure::class, 'message' => $failure->getMessage()] : null,
            ),
        };
    }

    /**
     * The shape `AbstractConstraintViolationListNormalizer` emits, key for key, so the RPC `data`
     * and the REST `violations` are the same document in two envelopes.
     *
     * @return array{violations: list<array{propertyPath: string, message: string, code: string|null}>}
     */
    private static function violations(ConstraintViolationListInterface $list): array
    {
        $violations = [];

        foreach ($list as $violation) {
            $violations[] = [
                'propertyPath' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
                'code' => $violation->getCode(),
            ];
        }

        return ['violations' => $violations];
    }
}
