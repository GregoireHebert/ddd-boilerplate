<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

use App\Shared\Infrastructure\Security\Permission;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One exposed method. Implementations live in `src/Ui/Api/JsonRpc/` and are the RPC counterpart of
 * `src/Ui/Api/StateProcessor/`: parse the payload with the domain's own constructors, put one
 * command or query on the bus, shape what comes back.
 *
 * ## The five statics are the published contract
 *
 * They are static because the generated document is built without running anything, and because
 * there is then exactly one place a method's name, its parameter order and its result shape are
 * written down. `MethodRegistry` indexes on {@see self::name()}, the OpenAPI decorator reads the
 * other three, and a method that renames a parameter cannot forget to say so.
 *
 * {@see self::result()} is the answer to what a wrapper protocol usually gets wrong. `result` is
 * `mixed` in the envelope, so without a per-method declaration the document degrades to "an
 * object", and the surface quietly publishes whatever the handler happened to return. Here the
 * method maps the handler's return into a shape it declares, which is also how no aggregate ever
 * reaches the wire: `ViewReputationMethod` receives a `Reputation` and
 * hands back five scalars.
 *
 * ## Exposure is a decision, not a consequence
 *
 * A routing key is already an RPC method name (`membership.acquireBadge`), so it is tempting to let
 * `method` be one and route straight onto the bus. That would expose every handler in the container
 * the moment somebody adds one, including `membership.advanceErasure`, which the erasure saga calls
 * and nobody else may. One class per exposed method keeps the surface a list somebody wrote.
 */
#[AutoconfigureTag(MethodRegistry::TAG)]
interface RpcMethod
{
    /**
     * The wire name. Dots, matching the routing-key convention; §4 reserves the `rpc.` prefix and
     * {@see MethodRegistry} refuses to register one.
     */
    public static function name(): string;

    /**
     * One sentence for the generated document.
     */
    public static function summary(): string;

    /**
     * ADR 008 option B on the RPC surface: **the permission this method asks for, declared and
     * never derived.**
     *
     * The ReST side declares the same thing with {@see \App\Shared\Infrastructure\Security\Guarded} on
     * the resource class, because an API Platform operation is metadata and an attribute is how you
     * hang a declaration off metadata. A method is already a class with five static declarations on
     * it, so a sixth is cheaper than an attribute and reads in the same place as the parameter
     * order and the result shape. One declaration, four deliveries; two spellings, because the two
     * things being declared on are not the same kind of thing.
     *
     * `null` says **unguarded, on purpose**, and it is not a hole left for convenience. ADR 008
     * rule 7: an operation with no actor is unguarded rather than privileged, and the absence of a
     * principal is not a principal. The one method that answers `null` today is
     * {@see DiscoverMethod}, which serves the OpenRPC document over `rpc.discover` and has no
     * permission it could name: inventing one would be inventing a case in an enum ADR 008 forbids
     * adding to without an operation behind it, and the firewall already decides whether `/rpc` is
     * reachable at all.
     *
     * `bin/console app:access` reads this, and it treats the two namespaces the way
     * {@see MethodRegistry} already does: a protocol method under this namespace may answer `null`,
     * and an application method under `Ui/Api/JsonRpc/` that answers `null` is a gap it reports and
     * exits non-zero over. That is the same line drawn in the same place for the second time, which
     * is the argument for drawing it there at all.
     *
     * **{@see JsonRpcServer::authorize()} asks this, before the method is invoked**, which is ADR
     * 008's per-delivery table in its own words. It is asked there and nowhere else, for a
     * structural reason rather than a tidy one: `POST /rpc` is one path carrying every method, so
     * neither `access_control` nor a metadata factory has anything to attach a decision to, and the
     * check cannot happen until the envelope has named a method.
     *
     * A `null` is honoured at runtime as unguarded and judged nowhere near it: `bin/console
     * app:access` is what decides whether a given `null` was a decision or an oversight, by the
     * namespace line above.
     */
    public static function permission(): ?Permission;

    /**
     * The declared parameters, **in positional order**, which is the order a by-position call is
     * folded onto and therefore the only thing here that can break a client by changing.
     *
     * @return list<Param>
     */
    public static function parameters(): array;

    /**
     * A `params` object that would actually work, for the generated document.
     *
     * It is a separate declaration from {@see self::parameters()} because the schema has to admit
     * every method at once and can therefore never show a concrete call. The example is the part a
     * reader copies, and CLAUDE.md's fourth OpenAPI rule is why it is an example and not a
     * `schema['default']`: a default written for documentation becomes behaviour the first time
     * `ParameterProvider` reads it.
     *
     * @return array<string, mixed>
     */
    public static function example(): array;

    /**
     * The JSON Schema of this method's `result` member. `['type' => 'null']` for a method that
     * answers nothing, which is a shape like any other and not an omission.
     *
     * @return array<string, mixed>
     */
    public static function result(): array;

    /**
     * @throws \App\Shared\Application\Validation\InvalidPayload            stage 1, accumulated
     * @throws \App\Shared\Domain\Exception\UnprocessablePayloadThrowable   stage 2, alone
     */
    public function __invoke(RpcParams $params): mixed;
}
