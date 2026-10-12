<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Name to method, and the only door onto the buses that `/rpc` has.
 *
 * `#[AutoconfigureTag]` on {@see RpcMethod} tags every implementation, so adding a method is
 * adding a class and nothing else: no entry in a map, no compiler pass, no configuration file that
 * somebody forgets. Both documents follow from the same tag, so a method that exists is a method
 * that is documented.
 *
 * ## Two namespaces, and the application only owns one
 *
 * §4 reserves names beginning `rpc.` "for rpc-internal methods and extensions". It reserves them
 * from *applications*, which is a narrower thing than forbidding them, and the difference matters
 * as soon as a JSON-RPC extension is implemented: `rpc.discover` is OpenRPC's service-discovery
 * method and its name is not negotiable.
 *
 * So the rule is not "no reserved names", it is **"no reserved names from outside the protocol"**,
 * and that is what {@see self::all()} checks: a class in this namespace may claim one, a class
 * under `Ui/Api/JsonRpc/` may not, and trying throws at wiring time with the reason. Both arrive
 * through the same tag and land in the same map, so there is still one enumeration of everything
 * callable, which is what keeps the OpenAPI operation, the OpenRPC document and the dispatcher from
 * drifting apart.
 *
 * The map is built eagerly over the tagged iterator, which instantiates every method service on
 * first use. That is deliberate rather than overlooked: a lazier `#[AutowireLocator]` would have to
 * index on a service-locator key computed at compile time, and the names would then live in two
 * places. These are five objects holding one dependency each, and the container has already built a
 * great deal more than that by the time a request reaches a processor.
 */
final class MethodRegistry
{
    public const string TAG = 'app.rpc.method';

    /**
     * §4's reserved namespace. Nothing an application writes may begin with it.
     */
    public const string RESERVED_PREFIX = 'rpc.';

    /** @var array<string, RpcMethod>|null */
    private ?array $byName = null;

    /**
     * A tagged iterator is a lazy argument, which is load-bearing here and not an optimisation:
     * {@see DiscoverMethod} reads this registry back through {@see OpenRpcFactory}, so the graph
     * has a loop in it. Symfony does not follow references inside an `IteratorArgument` when it
     * looks for circular references, and the factory takes its side of the loop as a lazy proxy, so
     * the cycle is broken twice and neither break is accidental.
     *
     * @param iterable<RpcMethod> $methods
     */
    public function __construct(
        #[AutowireIterator(self::TAG)]
        private readonly iterable $methods,
    ) {
    }

    /**
     * @throws RpcException when nothing answers to that name
     */
    public function get(string $name): RpcMethod
    {
        return $this->all()[$name]
            ?? throw RpcException::of(
                ErrorCode::MethodNotFound,
                \sprintf('No method named "%s" is exposed.', $name),
                ['methods' => array_keys($this->all())],
            );
    }

    /**
     * @return array<string, RpcMethod>
     */
    public function all(): array
    {
        if (null !== $this->byName) {
            return $this->byName;
        }

        $byName = [];

        foreach ($this->methods as $method) {
            $name = $method::name();

            // §4: "Method names that begin with the word rpc followed by a period character are
            // reserved for rpc-internal methods and extensions and MUST NOT be used for anything
            // else." An application method is "anything else"; a JSON-RPC extension implemented in
            // this namespace, such as OpenRPC's `rpc.discover`, is the thing the prefix was
            // reserved *for*. Where the class lives is how those two are told apart, and it is
            // settled here, at wiring time, rather than shadowed at dispatch time.
            if (str_starts_with($name, self::RESERVED_PREFIX) && !str_starts_with($method::class, __NAMESPACE__.'\\')) {
                throw new \LogicException(\sprintf(
                    '%s claims "%s". JSON-RPC 2.0 §4 reserves the "%s" prefix for the protocol and its extensions, so only a method in %s may take one.',
                    $method::class,
                    $name,
                    self::RESERVED_PREFIX,
                    __NAMESPACE__,
                ));
            }

            if (isset($byName[$name])) {
                throw new \LogicException(\sprintf(
                    '%s and %s both claim the method "%s".',
                    $byName[$name]::class,
                    $method::class,
                    $name,
                ));
            }

            $byName[$name] = $method;
        }

        ksort($byName);

        return $this->byName = $byName;
    }
}
