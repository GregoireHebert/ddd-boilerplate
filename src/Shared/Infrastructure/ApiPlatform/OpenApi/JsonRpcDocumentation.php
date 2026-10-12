<?php

declare(strict_types=1);

namespace ApiPlatform\OpenApi;

use ApiPlatform\JsonRpc\DiscoverMethod;
use ApiPlatform\JsonRpc\ErrorCode;
use ApiPlatform\JsonRpc\MethodRegistry;
use ApiPlatform\JsonRpc\Param;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * `POST /rpc`, described from the registry rather than from the resource class.
 *
 * ## OpenAPI cannot describe this endpoint, and pretending otherwise is the trap
 *
 * OpenAPI's unit of description is a path plus a verb. JSON-RPC has one of each, and everything a
 * caller needs to know lives in a member of the body. Left to itself, API Platform would publish
 * one `POST /rpc` taking "an object" and returning "an object", which is true and useless: the
 * interesting content (four methods, their parameters, their result shapes, ten error codes) has
 * nowhere to go.
 *
 * There are two ways out, and this class is the cheaper one. The complete answer is an
 * [OpenRPC](https://spec.open-rpc.org/) document, which is the IDL this protocol actually has, and
 * ADR 006 records it as the next step rather than as a rejection. What is here instead is the most
 * OpenAPI can honestly carry:
 *
 * - a `oneOf` request body: one request object, or an array of them, so both shapes of §6 are in
 *   the schema and not only in prose;
 * - **one example per method**, which is the part a reader actually uses. Examples are also the
 *   only place a concrete `params` object can appear, since the schema has to admit all four;
 * - a `oneOf` response over one envelope per method, each with that method's declared `result`
 *   schema, so `result` is never the untyped `{}` a wrapper protocol usually degrades to;
 * - the `204` that a notification answers, which nothing in the metadata could have inferred;
 * - two generated tables in the description: the methods, and the error codes next to the REST
 *   status each one corresponds to.
 *
 * Everything above is derived from {@see MethodRegistry} and {@see ErrorCode}, so a method that is
 * added is a method that is documented, and a marker interface that gains an RPC code appears in
 * the table without anybody editing prose. That is the same rule the rest of this directory
 * follows: {@see BadFilterInputResponses} for a fact about every read endpoint,
 * {@see TagDescriptions} for a fact about every tag.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class JsonRpcDocumentation implements OpenApiFactoryInterface
{
    private const string PATH = '/rpc';

    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $inner,
        private MethodRegistry $methods,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->inner)($context);

        $pathItem = $openApi->getPaths()->getPath(self::PATH);
        $operation = $pathItem?->getPost();

        if (null === $pathItem || !$operation instanceof Operation) {
            return $openApi;
        }

        $openApi->getPaths()->addPath(self::PATH, $pathItem->withPost(
            $operation
                ->withDescription($this->description($operation->getDescription() ?? ''))
                ->withRequestBody($this->requestBody())
                // Replacing rather than adding. API Platform documents a `400 Invalid input` and a
                // `422` on every `POST`, inferred from the deserialization and validation steps this
                // operation switched off, and neither status is reachable here: a body that will not
                // parse is a `200` carrying `-32700`. What is left is the two answers that are real.
                ->withResponses([
                    '200' => $this->success(),
                    '204' => new Response(
                        description: 'Every call in the request was a notification, so there is nothing to return. §6: the server "MUST NOT return an empty Array and should return nothing at all".',
                    ),
                    '406' => new Response(
                        description: 'The `Accept` header asked for something other than `application/json`. This one is the transport\'s answer rather than the protocol\'s, so it is an RFC 9457 problem document and not a Response object.',
                        content: new \ArrayObject([
                            'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Error']],
                        ]),
                    ),
                ]),
        ));

        $components = $openApi->getComponents();
        $schemas = $components->getSchemas() ?? new \ArrayObject();

        // The schema factory mints one per resource class, and this resource class is an empty
        // anchor: the shapes on the wire are the protocol's, declared below, and a `JsonRpc` schema
        // saying `type: object` describes nothing that exists.
        unset($schemas['JsonRpc']);

        foreach ($this->schemas() as $name => $schema) {
            $schemas[$name] = $schema;
        }

        return $openApi->withComponents($components->withSchemas($schemas));
    }

    private function description(string $preamble): string
    {
        $methods = ['| Method | Parameters | What it does |', '| --- | --- | --- |'];

        foreach ($this->methods->all() as $name => $method) {
            $methods[] = \sprintf(
                '| `%s` | %s | %s |',
                $name,
                '' === ($list = implode(', ', array_map(
                    static fn (Param $param): string => \sprintf('`%s`%s', $param->name, $param->required ? '' : '?'),
                    $method::parameters(),
                ))) ? '_none_' : $list,
                $method::summary(),
            );
        }

        $errors = ['| Code | Meaning | The same failure over REST |', '| --- | --- | --- |'];

        foreach (ErrorCode::cases() as $code) {
            $errors[] = \sprintf('| `%d` | %s | `%d` |', $code->value, $code->message(), $code->restEquivalent());
        }

        return implode("\n", [
            $preamble,
            '',
            '## Methods',
            '',
            ...$methods,
            '',
            'A `?` marks an optional parameter. Parameters may be sent **by name**, as an object, or **by',
            'position**, as an array in the order above; the schemas under `components.schemas` describe the',
            'by-name form, because the two cannot be told apart by a validator and describing both would',
            'mean a union that most code generators flatten.',
            '',
            'The result shape of each method is not here either, for the same kind of reason: a Response',
            'object does not say which method it answers, so this document has nowhere to attach one. Both',
            'live in the OpenRPC document, which the endpoint serves itself: call `rpc.discover`.',
            '',
            '## Errors',
            '',
            ...$errors,
            '',
            '`-32602` carries the same `violations` array as a REST `422`, under `error.data`, with the',
            'same `propertyPath` and `code` on every entry. Nothing else carries one: the codes below',
            '`-32000` are decided by asking the database, and those answers are never lists.',
        ]);
    }

    private function requestBody(): RequestBody
    {
        $methods = $this->methods->all();
        $examples = [];

        foreach ($methods as $name => $method) {
            $examples[$name] = [
                'summary' => $method::summary(),
                'value' => ['jsonrpc' => '2.0', 'method' => $name, 'params' => $method::example(), 'id' => 1],
            ];
        }

        $batch = [];

        // Whichever two methods are registered first, one answered and one not. The point of the
        // example is the shape, so it does not matter which two they are, and picking them by name
        // would put a list of methods in a class whose whole design is not to have one.
        foreach (array_slice($methods, 0, 2, true) as $name => $method) {
            $call = ['jsonrpc' => '2.0', 'method' => $name, 'params' => $method::example()];

            $batch[] = [] === $batch ? [...$call, 'id' => 1] : $call;
        }

        $examples['batch'] = [
            'summary' => 'Two calls at once. The second has no `id`, so it is a notification: it runs and nothing comes back for it.',
            'value' => $batch,
        ];

        return new RequestBody(
            description: 'One request object, or an array of them for a batch (up to 50).',
            content: new \ArrayObject([
                'application/json' => [
                    'schema' => ['oneOf' => [
                        ['$ref' => '#/components/schemas/JsonRpc.Request'],
                        ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/JsonRpc.Request'], 'minItems' => 1, 'maxItems' => 50],
                    ]],
                    'examples' => $examples,
                ],
            ]),
            required: true,
        );
    }

    /**
     * One response schema, not one per method, and the reason is that there is nothing to
     * discriminate on. A Response object carries `result` or `error` and an `id`; it does not carry
     * the method it answers. A `oneOf` over five envelopes would therefore be an undiscriminated
     * union, which a generator flattens into one class with every branch's fields marked optional.
     * The per-method result shapes live in the OpenRPC document, attached to the method, which is
     * the only place the pairing exists.
     */
    private function success(): Response
    {
        $envelope = ['$ref' => '#/components/schemas/JsonRpc.Response'];

        return new Response(
            description: 'A Response object, or an array of them when a batch was sent. This status is returned for failures too; read `error.code`.',
            content: new \ArrayObject([
                'application/json' => [
                    'schema' => ['oneOf' => [
                        $envelope,
                        ['type' => 'array', 'items' => $envelope],
                    ]],
                ],
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        // A type array rather than a `oneOf` of three one-type branches. OpenAPI 3.1 is JSON Schema
        // 2020-12 and this is what it is for; API Platform already emits `["string", "null"]` all
        // over this document. It also keeps a union out of the schema for something that is not a
        // union of *shapes*, only of primitives.
        $id = [
            'type' => ['string', 'number', 'null'],
            'description' => 'The caller\'s own correlation token, echoed back unchanged. Omit the member entirely to make the call a notification.',
        ];

        $names = array_keys($this->methods->all());

        $schemas = [
            // One request schema per method, **discriminated on `method`**. The discriminator is
            // the whole reason this is a union rather than one loose schema: without it a generator
            // flattens the branches into a single class with every branch's fields optional, which
            // both accepts impossible payloads (a `loseBadge` carrying a `color`) and loses the
            // branch a payload came from. With it, `method` is the property that recovers the
            // branch, and a conforming generator emits a real union.
            'JsonRpc.Request' => [
                'description' => 'One call. The `method` member selects the shape of the rest.',
                'oneOf' => array_map(
                    static fn (string $name): array => ['$ref' => \sprintf('#/components/schemas/JsonRpc.Request.%s', $name)],
                    $names,
                ),
                'discriminator' => [
                    'propertyName' => 'method',
                    'mapping' => array_combine($names, array_map(
                        static fn (string $name): string => \sprintf('#/components/schemas/JsonRpc.Request.%s', $name),
                        $names,
                    )),
                ],
            ],
            'JsonRpc.Error' => [
                'type' => 'object',
                'required' => ['code', 'message'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => array_column(ErrorCode::cases(), 'value')],
                    'message' => ['type' => 'string', 'description' => 'A single sentence. Not something to parse.'],
                    'data' => ['description' => 'Present when there is more to say. For `-32602` it holds the `violations` array.'],
                ],
            ],
            // Both members are optional here and exactly one is present in any real response. That
            // is weaker than the protocol (§5 forbids both at once) and it is the strongest thing
            // a flattening-proof schema can say: the alternative is an undiscriminated two-branch
            // union, and a response carries no property to discriminate on.
            'JsonRpc.Response' => [
                'type' => 'object',
                'description' => 'Exactly one of `result` and `error` is present. Neither is ever `null` to mean the other is absent.',
                'required' => ['jsonrpc', 'id'],
                'properties' => [
                    'jsonrpc' => ['type' => 'string', 'enum' => ['2.0']],
                    'result' => [
                        'description' => \sprintf(
                            'Present on success. Its shape is decided by the method you called; the OpenRPC document has one result schema per method, and `%s` serves it.',
                            DiscoverMethod::name(),
                        ),
                    ],
                    'error' => ['$ref' => '#/components/schemas/JsonRpc.Error'],
                    'id' => $id,
                ],
            ],
        ];

        foreach ($this->methods->all() as $name => $method) {
            $params = $method::parameters();

            $request = [
                'jsonrpc' => ['type' => 'string', 'enum' => ['2.0']],
                'method' => ['type' => 'string', 'enum' => [$name]],
            ];

            if ([] !== $params) {
                // The by-name form only. `params` is genuinely an object *or* an array (§4.2), and
                // those two cannot be discriminated: there is no property to key on, so a `oneOf`
                // here is the flattening trap again, one level down. The positional form is
                // documented in the operation description and typed, in order, by the OpenRPC
                // document, which has a place to say it.
                $request['params'] = [...Param::objectSchema($params), 'description' => \sprintf(
                    'Sent by name here. The same call may be sent by position as `[%s]`.',
                    implode(', ', Param::names($params)),
                )];
            }

            $request['id'] = $id;

            $schemas[\sprintf('JsonRpc.Request.%s', $name)] = [
                'type' => 'object',
                'description' => $method::summary(),
                'required' => ['jsonrpc', 'method'],
                'properties' => $request,
            ];
        }

        return $schemas;
    }
}
