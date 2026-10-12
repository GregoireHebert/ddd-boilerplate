<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

use App\Shared\Application\Security\Forbidden;
use App\Shared\Application\Security\NotAuthenticated;
use App\Shared\Domain\Exception\UnauthenticatedThrowable;
use App\Shared\Infrastructure\Security\CurrentActor;
use App\Shared\Infrastructure\Security\Gate;
use App\Shared\Infrastructure\Security\TokenCurrentActor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * JSON-RPC 2.0, whole, in one class, because the protocol is one decision tree and splitting it
 * across listeners is how implementations end up answering `[]` to an all-notification batch.
 *
 * It takes a raw body and answers a {@see RpcReply}. It throws exactly one thing, and the rest of
 * this docblock is worth reading with that exception in mind: every other arm below ends in a
 * Response object or in deliberate silence, which is what lets `/rpc` keep the promise the
 * specification makes and HTTP does not, namely that a caller who reached the endpoint gets a
 * JSON-RPC document back.
 *
 * ## Reading the tree
 *
 * ```text
 * body does not decode                     -> one response, -32700, id null
 * decodes to a scalar                      -> one response, -32600, id null
 * decodes to an object                     -> one call
 * decodes to an empty array                -> one response, -32600, id null        (§6)
 * decodes to an array                      -> one call per member, array answer    (§6)
 *   a member that is not an object         -> -32600, id null
 * every answer suppressed                  -> nothing at all, 204                  (§6)
 * a call names a method, anonymous         -> nothing at all: a 401 leaves in the status line
 * a call names a method, gate refuses      -> one response, -32005, id as sent
 * a call raises UnauthenticatedThrowable   -> nothing at all: a 401 leaves in the status line
 * ```
 *
 * `json_decode(..., true)` renders `{}` as an empty PHP array, so an empty object and an empty
 * batch are indistinguishable here. They are also the same answer, `-32600`, which is why the
 * ambiguity costs nothing and is worth knowing about before somebody "fixes" it with
 * `JSON_OBJECT_AS_ARRAY` off and rewrites everything downstream to take `stdClass`.
 *
 * ## The gate is asked here, and here is the only place it can be asked
 *
 * ADR 008's per-delivery table says {@see RpcMethod::permission()} is "asked by `JsonRpcServer`
 * before the method is invoked", and {@see self::authorize()} is that sentence. It is here rather
 * than anywhere more convenient for one structural reason: **`POST /rpc` is one path carrying every
 * method there will ever be**, so `access_control` can say nothing about any one of them and a
 * metadata factory has no operation to hang a declaration off. The ReST surface has
 * {@see \ApiPlatform\Metadata\GuardedOperationsFactory} writing an expression
 * API Platform evaluates before a processor runs; this endpoint has one operation for the whole
 * surface, so the check has to happen after the envelope names a method and that is this class.
 *
 * The actor is an **argument** (ADR 008 rule 2): it comes from a {@see CurrentActor}, which is the
 * only thing in the application allowed to read an ambient token, and it is then handed to
 * {@see Gate::isGranted()} rather than left for the gate to find. The gate takes no subject, which
 * is ADR 008 rule 4, so nothing here can ask whether the badge collection being written to is the caller's:
 * that is question 3, it needs our store, and it is the aggregate's, answering `404`.
 *
 * ## The one thing this endpoint does not answer with a `200`
 *
 * ADR 006 is categorical that the whole failure taxonomy of `/rpc` lives inside a `200`, and
 * ADR 008 amends it by one case: {@see UnauthenticatedThrowable} is re-thrown by {@see self::call()}
 * rather than rendered, because a `401` is not this endpoint deciding. It is a property of the
 * connection, RFC 9110 requires the answer to carry a `WWW-Authenticate` challenge, and a JSON-RPC
 * response object has nowhere to put one. Everything else this class meets, a forbidden call
 * included, stays a `200`; {@see RpcErrorFactory} is where each of those is given its integer and
 * where the absence of an arm for this one is recorded.
 *
 * ## One transaction per member, and no pretence otherwise
 *
 * `EcotoneDbalConfiguration` opens a transaction per command-bus dispatch, so a batch of five
 * writes is five transactions and the third one failing leaves the first two committed. §6 asks for
 * nothing more, the REST surface behaves identically across five calls, and nowhere in this project
 * does a cross-store write pretend to be atomic (ADR 003, path 5). The document says so; a caller
 * who needs all-or-nothing needs a command that means all-or-nothing.
 *
 * ## Notifications
 *
 * A notification runs, and then its answer is dropped, errors included: "the Client would not be
 * aware of any errors". Dropped is not the same as unnoticed, so a failure is logged at error level
 * with the method name; otherwise a typo in a fire-and-forget call is invisible on both sides of
 * the wire.
 *
 * They are dispatched synchronously, which is worth being explicit about because notification looks
 * exactly like a case for `#[Asynchronous]`. Two reasons it is not, today: the async transport does
 * not work outside the test environment (see CLAUDE.md), and a queued notification would move the
 * failure out of this log line into a dead letter nobody is reading. Silence towards the caller is
 * the specification's decision; silence towards us would be ours.
 */
final readonly class JsonRpcServer
{
    /**
     * A batch is not a bulk endpoint. Every member is a separate transaction and, for a write, a
     * separate aggregate, so this bounds what one request can cost rather than expressing anything
     * about the protocol. §6 sets no limit; exceeding this one is answered as a single
     * `-32600`, whole, because a partially honoured batch would be worse than a refused one.
     */
    private const int MAX_BATCH = 50;

    /**
     * The `CurrentActor` implementation is **named rather than autowired by interface**, which is
     * the ceremony ADR 008 asks of every consumer: three implementations of a one-method interface
     * and none of them aliased, because aliasing one delivery's would make the other two exceptions
     * to it. This endpoint sits on the stateless firewall beside the ReST API, so it is
     * {@see TokenCurrentActor}, which is the same class and the same row in ADR 008's table that
     * `CastLikeProcessor` names.
     */
    public function __construct(
        private MethodRegistry $methods,
        private RpcErrorFactory $errors,
        private Gate $gate,
        #[Autowire(service: TokenCurrentActor::class)]
        private CurrentActor $actors,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws UnauthenticatedThrowable mapped to a `401` by `exception_to_status`, which is the
     *                                  whole of ADR 008's amendment to ADR 006
     */
    public function handle(string $body): RpcReply
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            return RpcReply::single(RpcResponse::failure(
                RpcError::of(ErrorCode::ParseError, data: ['reason' => $failure->getMessage()]),
            ));
        }

        if (!\is_array($decoded)) {
            return RpcReply::single(RpcResponse::failure(RpcError::of(
                ErrorCode::InvalidRequest,
                'The body must be a request object or an array of request objects.',
            )));
        }

        if (!array_is_list($decoded)) {
            $response = $this->call($decoded);

            return null === $response ? RpcReply::silent() : RpcReply::single($response);
        }

        return $this->batch($decoded);
    }

    /**
     * @param list<mixed> $members
     */
    private function batch(array $members): RpcReply
    {
        if ([] === $members) {
            return RpcReply::single(RpcResponse::failure(RpcError::of(
                ErrorCode::InvalidRequest,
                'A batch must contain at least one request object.',
            )));
        }

        if (\count($members) > self::MAX_BATCH) {
            return RpcReply::single(RpcResponse::failure(RpcError::of(
                ErrorCode::InvalidRequest,
                \sprintf('A batch is limited to %d request objects.', self::MAX_BATCH),
                ['limit' => self::MAX_BATCH, 'given' => \count($members)],
            )));
        }

        $responses = [];

        foreach ($members as $member) {
            $response = \is_array($member) && !array_is_list($member)
                ? $this->call($member)
                : RpcResponse::failure(RpcError::of(
                    ErrorCode::InvalidRequest,
                    'A batch member must be a request object.',
                ));

            if (null !== $response) {
                $responses[] = $response;
            }
        }

        return [] === $responses ? RpcReply::silent() : RpcReply::batch($responses);
    }

    /**
     * @param array<array-key, mixed> $member
     *
     * @return RpcResponse|null null when the member was a notification and must not be answered
     *
     * @throws UnauthenticatedThrowable the one failure that leaves in the status line, below
     */
    private function call(array $member): ?RpcResponse
    {
        try {
            $call = RpcCall::from($member);
        } catch (RpcException $failure) {
            // Not a notification: an invalid envelope never became a request object, so §4.1's
            // "MUST NOT reply" does not apply to it. §6's `[1, 2, 3]` example is this line.
            return RpcResponse::failure($failure->error(), RpcCall::idOf($member));
        }

        try {
            $method = $this->methods->get($call->method);

            // Before the parameters are folded, and not only before the method runs. A caller who
            // may not reach a method learns nothing about its parameters either, which is ADR 008
            // rule 14 read one step earlier than it had to be: an invalid-params answer to a call
            // the gate was going to refuse tells somebody the method exists and what it wants.
            $this->authorize($method);

            $result = $method($call->parameters(Param::names($method::parameters())));
        } catch (UnauthenticatedThrowable $failure) {
            // ADR 008's single amendment to ADR 006, and the reason `RpcErrorFactory` has no arm
            // for this marker: there is no `ErrorCode` to render it with, by decision. Left to the
            // arm below it would fall through to `-32603 Internal error`, which is the wrong answer
            // twice over: it says the server broke, and it says it inside a `200`.
            //
            // Two consequences, both deliberate. A **notification** that raises it is not silenced:
            // §4.1's "MUST NOT reply" is about the method's answer, and this is the connection
            // being refused rather than the method answering. And a **batch** stops here, which is
            // the one place a member's failure abandons the rest; every member up to this one was
            // its own transaction and is committed, exactly as §6 and this class's own docblock
            // already say, so the caller re-sends whatever the `401` was worth re-sending.
            //
            // Two things reach this line, and neither is the firewall, which answers first whenever
            // `access_control` demands a credential for `/rpc` at all. The first is
            // {@see self::authorize()} meeting an anonymous caller at a guarded method. The second
            // is {@see \App\Shared\Infrastructure\Security\Actor::subject()}, inside a method that was
            // reached unguarded and then asked who was asking.
            throw $failure;
        } catch (\Throwable $failure) {
            $error = $this->errors->from($failure);

            if ($call->isNotification) {
                $this->logger->error('A JSON-RPC notification failed and its caller will not be told.', [
                    'method' => $call->method,
                    'code' => $error->code->value,
                    'exception' => $failure,
                ]);

                return null;
            }

            return RpcResponse::failure($error, $call->id);
        }

        return $call->isNotification ? null : RpcResponse::success($result, $call->id);
    }

    /**
     * ADR 008 question 2, and only question 2: *may somebody like this actor do this at all.*
     *
     * ## `null` is unguarded, and it is a decision rather than a hole
     *
     * {@see RpcMethod::permission()} is nullable because a **protocol** method is not a capability
     * of this application: `rpc.discover` serves the OpenRPC document and there is no permission it
     * could name without inventing an enum case with no operation behind it. ADR 008 rule 7 is the
     * general form, and it is the same sentence ADR 009 rule 13 applies to `POST /members`: an
     * operation with no actor is *unguarded* rather than privileged.
     *
     * What this method does **not** do is decide whether a given `null` was deliberate. That is
     * `bin/console app:access`, which already draws the line by namespace: a protocol method under
     * `Shared/Infrastructure/ApiPlatform/JsonRpc/` may answer `null`, and an application method under
     * `Ui/Api/JsonRpc/` that answers `null` is a gap it exits non-zero over. Two readings of one
     * line, and the runtime is not the one that reports.
     *
     * ## Two refusals, because there are two questions
     *
     * An **anonymous** caller is refused with {@see NotAuthenticated}, which leaves `POST /rpc` in
     * the status line as a `401`: ADR 006 is categorical that everything this endpoint decides is a
     * `200`, and this is the one case it is not this endpoint deciding. RFC 9110 wants a
     * `WWW-Authenticate` challenge and a JSON-RPC response object has nowhere to put one. The arm
     * in {@see self::call()} is what lets it out.
     *
     * An **authenticated** caller the gate refuses is {@see Forbidden}, which stays inside the
     * `200` as {@see ErrorCode::Forbidden}, because the actor is known and there is nothing to
     * challenge. {@see RpcErrorFactory} renders it, and it carries no `violations` array, no
     * parameters and no permission name: ADR 008 rule 14, a refusal says nothing.
     *
     * The order matters and it is ADR 008's refusal table rather than an optimisation. Asking the
     * gate about an anonymous actor answers `false`, so without the first arm every unauthenticated
     * call would be told it is forbidden, which is the one answer that does not tell a caller their
     * next move is to authenticate.
     *
     * ## The audit line
     *
     * ADR 008 asks for one line, written by the thing that decided, at the edge, before the command
     * goes on the bus. This is that thing, and it is where the permission name goes precisely
     * because it never reaches the caller: naming it in the refusal would publish the shape of the
     * policy to somebody who has just demonstrated they are not entitled to know it.
     * {@see \App\Shared\Infrastructure\Security\Actor::describe()} is the fragment carrying the
     * provenance, which is the whole reason {@see \App\Shared\Infrastructure\Security\Authority} is a
     * field.
     *
     * @throws \App\Shared\Domain\Exception\UnauthenticatedThrowable nobody is asking
     * @throws \App\Shared\Domain\Exception\ForbiddenThrowable       somebody is, and may not
     */
    private function authorize(RpcMethod $method): void
    {
        $permission = $method::permission();

        if (null === $permission) {
            return;
        }

        $actor = $this->actors->actor();

        if ($actor->isAnonymous()) {
            throw NotAuthenticated::required();
        }

        $granted = $this->gate->isGranted($actor, $permission);

        $this->logger->notice('A JSON-RPC call was decided at the gate. {actor} permission={permission} operation={operation} granted={granted}', [
            'actor' => $actor->describe(),
            'permission' => $permission->wire(),
            'operation' => 'rpc:'.$method::name(),
            'granted' => $granted ? 'yes' : 'no',
        ]);

        if (!$granted) {
            throw Forbidden::refused();
        }
    }
}
