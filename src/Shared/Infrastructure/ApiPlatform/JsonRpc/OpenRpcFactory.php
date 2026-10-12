<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The OpenRPC document, projected from {@see MethodRegistry}.
 *
 * ## Projected, never translated
 *
 * The other way to get here is to convert the OpenAPI document, and it would be both lossy and
 * backwards. OpenAPI describes a path plus a verb, which for this endpoint is one of each, so
 * everything interesting is already squeezed into prose and examples by
 * {@see \ApiPlatform\OpenApi\JsonRpcDocumentation}; converting that back into
 * structure would be reconstructing what the registry has in the first place. OpenAPI is the
 * workaround and OpenRPC is this protocol's native format, so both are projections of the same
 * source and neither is derived from the other.
 *
 * The mapping is nearly mechanical, which is the useful part: {@see Param} *is* a Content
 * Descriptor, {@see ErrorCode} *is* the `errors` array, and {@see RpcMethod::result()} is the one
 * field that needs a name invented for it (a Content Descriptor's `name` is required and a result
 * has no natural one).
 *
 * ## Three judgement calls
 *
 * **`rpc.discover` lists itself.** The specification does not say either way. A client that just
 * called it should see it among the methods it got back, and it is the same `all()` the dispatcher
 * answers from, so leaving it out would mean maintaining a second idea of what is callable.
 *
 * **Every method advertises the whole error table.** {@see RpcErrorFactory} maps the marker
 * interfaces globally, so narrowing per method would be a claim about which markers a given handler
 * can raise that nothing checks and that would rot the first time a handler grew a guard.
 * Per-method narrowing is a refinement, not a correction.
 *
 * **`examples` carries the params half only.** OpenRPC's `ExamplePairing` wants a result example
 * too, and {@see RpcMethod} declares a result *schema* rather than a result value. Emitting half a
 * pairing is allowed and honest; adding a sixth static to the interface to fill it in is not
 * something anything has asked for yet.
 */
final readonly class OpenRpcFactory
{
    /**
     * **Not the newest, and that is checked rather than assumed.**
     *
     * The specification repository has tagged 1.4.0 and 1.4.1 (February 2026), but the published
     * meta-schema at {@see self::META_SCHEMA} carries a *generated* `enum` of accepted values for
     * this field, and that enum still stops at 1.3.2. A document saying `"openrpc": "1.4.1"` is
     * therefore rejected by the schema every tool actually fetches, for the one reason that has
     * nothing to do with the document.
     *
     * Nothing emitted below uses anything newer than 1.3.2 anyway: `info`, `servers`, `methods`,
     * `params`, `result`, `errors`, `examples` and `paramStructure` are all 1.0-era. So this names
     * the newest revision a consumer can verify us against, and it moves when the meta-schema does.
     * Re-check with:
     *
     * ```shell
     * curl -s https://meta.open-rpc.org/ | jq '.properties.openrpc.enum[0]'
     * ```
     */
    public const string OPENRPC_VERSION = '1.3.2';

    /**
     * The canonical published meta-schema, cited in prose and never as a `$ref`;
     * {@see DiscoverMethod::result()} carries the reasoning.
     *
     * The specification's discovery section names
     * `raw.githubusercontent.com/open-rpc/meta-schema/master/schema.json` instead, and that file is
     * the pre-release source: its `openrpc` enum holds the literal string "GENERATED FIELD: Do Not
     * Edit", so a client validating against it fails on every document ever written. This is the
     * released build of the same schema.
     */
    public const string META_SCHEMA = 'https://meta.open-rpc.org/';

    public function __construct(
        // Lazy because {@see MethodRegistry} constructs {@see DiscoverMethod}, which reaches this
        // factory, which reads the registry. The read happens when a document is built, long after
        // both objects exist, and the proxy is how that is said to the container rather than left
        // as a cycle for it to discover.
        #[Autowire(service: MethodRegistry::class, lazy: true)]
        private MethodRegistry $methods,
        private UrlGeneratorInterface $router,
        // The same two parameters API Platform builds `info` from, so the two documents cannot
        // disagree about what this API is called.
        #[Autowire('%api_platform.title%')]
        private string $title,
        #[Autowire('%api_platform.version%')]
        private string $version,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'openrpc' => self::OPENRPC_VERSION,
            'info' => [
                'title' => \sprintf('%s, JSON-RPC surface', $this->title),
                'version' => $this->version,
                'description' => 'The operations that are verbs rather than resources. The REST surface is documented separately, in OpenAPI, and nothing is exposed on both.',
            ],
            'servers' => [[
                'name' => 'default',
                'url' => $this->router->generate('_api_/rpc_post', referenceType: UrlGeneratorInterface::ABSOLUTE_URL),
            ]],
            'methods' => array_map(self::method(...), array_values($this->methods->all())),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function method(RpcMethod $method): array
    {
        $params = $method::parameters();

        return [
            'name' => $method::name(),
            'summary' => $method::summary(),
            // The specification's own default, and true of every method here: `RpcParams` folds a
            // positional call onto the declared order and is otherwise indifferent.
            'paramStructure' => 'either',
            'params' => array_map(static fn (Param $param): array => $param->toContentDescriptor(), $params),
            'result' => [
                // A Content Descriptor's `name` is required and a result has no natural one, so it
                // is derived. Dots are stripped because generators turn this into a type name, and
                // it stays derived from the full method name so two contexts cannot collide on a
                // shared verb.
                'name' => \sprintf('%sResult', lcfirst(str_replace('.', '', ucwords($method::name(), '.')))),
                'schema' => $method::result(),
            ],
            'errors' => array_map(
                static fn (ErrorCode $code): array => ['code' => $code->value, 'message' => $code->message()],
                ErrorCode::cases(),
            ),
            'examples' => [] === $params ? [] : [[
                'name' => 'example',
                'params' => array_map(
                    static fn (Param $param): array => ['name' => $param->name, 'value' => $method::example()[$param->name] ?? null],
                    array_values(array_filter($params, static fn (Param $param): bool => \array_key_exists($param->name, $method::example()))),
                ),
            ]],
        ];
    }

}
