<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

use App\Shared\Infrastructure\Security\Permission;

/**
 * `rpc.discover`: the surface describing itself, over itself.
 *
 * OpenRPC's service-discovery method. The name is fixed by that specification ("The method MUST be
 * named `rpc.discover`"), it takes no parameters, and it answers with an OpenRPC document. That is
 * the whole contract.
 *
 * ## Why it is a method and not a route
 *
 * It would have been half a line to hang the document off `GET /rpc/openrpc.json`, and it would
 * have made the discovery of a JSON-RPC API something you do with a different protocol. As a method
 * it goes through the same envelope as everything else: the same `id` echo, the same error codes if
 * something goes wrong, and it composes inside a batch, so a client can discover and call in one
 * round trip. A protocol that can describe itself in its own terms is worth more than one that
 * needs a second door.
 *
 * ## Why it may claim the reserved prefix
 *
 * §4 reserves `rpc.` "for rpc-internal methods and extensions and MUST NOT be used for anything
 * else". OpenRPC is exactly such an extension, so this is the intended inhabitant of that namespace
 * rather than a hole poked in the rule. {@see MethodRegistry} draws the line where the
 * specification draws it: the protocol's own directory may declare a reserved name, an application
 * method under `Ui/Api/JsonRpc/` may not, and the registry throws at wiring time if one tries.
 *
 * ## Why it lives here and not in `Ui/Api/JsonRpc/`
 *
 * That directory is what this application does; this directory is what JSON-RPC does. `rpc.discover`
 * touches no bus, no command, no aggregate, and would be identical in a project with a different
 * domain. The placement is the statement.
 */
final readonly class DiscoverMethod implements RpcMethod
{
    public function __construct(private OpenRpcFactory $document)
    {
    }

    #[\Override]
    public static function name(): string
    {
        return MethodRegistry::RESERVED_PREFIX.'discover';
    }

    #[\Override]
    public static function summary(): string
    {
        return 'Return the OpenRPC document describing every method on this endpoint.';
    }

    /**
     * None, and this is the method the nullable return type exists for.
     *
     * There is no permission to name. Discovery is not a capability this application exposes over a
     * context; it is the protocol describing itself, and ADR 008 is explicit that a case may not be
     * added to a `Permission` enum for anything that is not an operation a delivery exposes. A
     * `platform.rpc.discover` invented here would be a permission with no card, no audience and no
     * refusal, granted to everybody in {@see \App\Shared\Infrastructure\Security\RoleMap} because there
     * is nobody to refuse it to.
     *
     * What actually decides whether the document is reachable is the firewall, which is ADR 008
     * question 1 and the only question a path regex can answer honestly. `/rpc` needs a credential
     * or it does not, and the ADR accepts the consequence out loud: if the endpoint is protected,
     * discovery needs one too, and that is fine, because the human-readable description of every
     * method is in the public OpenAPI export and an OpenRPC document for an endpoint the reader
     * cannot call is a curiosity.
     *
     * `bin/console app:access` lists this method as unguarded and does not fail over it, because it
     * lives in the protocol's namespace rather than under `Ui/Api/JsonRpc/`. That is the same line
     * {@see MethodRegistry} draws to decide who may claim the `rpc.` prefix, read a second time.
     */
    #[\Override]
    public static function permission(): ?Permission
    {
        return null;
    }

    /**
     * None, and the specification is specific about it: `params` is the empty array.
     */
    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public static function example(): array
    {
        return [];
    }

    /**
     * Described in place, and **not** as a `$ref` to the meta-schema.
     *
     * OpenRPC's own discovery section shows `"schema": {"$ref": "https://.../meta-schema/..."}`, and
     * following it broke the API documentation outright: a `$ref` is a JSON Pointer into *this*
     * document, every consumer resolves it locally, and Redoc stops with
     * `Invalid ref JSON pointer: https://meta.open-rpc.org/` before rendering a single endpoint.
     * The rule has no exception worth taking: a document that cannot be read offline, from itself,
     * is not a document.
     *
     * Inlining the meta-schema instead would be this project maintaining a copy of somebody else's
     * specification, and it describes a schema rather than a payload, so nothing generated from it
     * would be useful anyway. The URL belongs in prose, where a reader can follow it and a parser
     * is not asked to.
     */
    #[\Override]
    public static function result(): array
    {
        return [
            'type' => 'object',
            'description' => \sprintf(
                'An OpenRPC document describing every method on this endpoint, conforming to version %s of the specification. Validate it against the meta-schema at %s.',
                OpenRpcFactory::OPENRPC_VERSION,
                OpenRpcFactory::META_SCHEMA,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function __invoke(RpcParams $params): array
    {
        // Nothing is read off `$params`. A caller who sends some is not refused: the specification
        // says the member is omitted, not that sending it is an error, and answering `-32602` to a
        // client that helpfully passed `{}` would be pedantry with a cost.
        return ($this->document)();
    }
}
