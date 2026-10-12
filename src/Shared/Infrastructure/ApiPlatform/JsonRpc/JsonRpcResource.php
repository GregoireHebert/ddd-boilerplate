<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Ui\Api\StateProcessor\JsonRpcProcessor;

/**
 * `POST /rpc`. One route, for every method, forever.
 *
 * ## Why there is one operation and not one per method
 *
 * The tempting design is an `#[Action]` attribute extending `HttpOperation`, so that each method
 * becomes its own route under `/rpc` and keeps API Platform's router, serializer, validator and
 * schema generation. It produces a usable API. It does not produce JSON-RPC, and the gap is not
 * closeable by configuration:
 *
 * - **the protocol dispatches on a member of the body**, at one URI. With a route per method, the
 *   `method` member is either redundant with the path or contradicts it, and there is no honest
 *   answer to which one wins;
 * - **batching needs the single endpoint.** §6 sends an array of calls to *one* URI and expects an
 *   array of answers. A router that has already chosen an operation from the path cannot fan out;
 * - **the error channel is a `200`.** API Platform renders failures as RFC 9457 documents at 4xx
 *   and 5xx statuses, which is right for the REST surface and wrong for every JSON-RPC error.
 *
 * So the framework keeps what it is good at (routing, content negotiation, the generated document,
 * the place a processor plugs in) and the protocol keeps the rest. API Platform 4.3 makes the same
 * split for MCP, which is JSON-RPC too: `ApiPlatform\Metadata\McpTool` is an `HttpOperation`
 * subclass used as *metadata*, and a registry and a server do the dispatching. The route-per-method
 * design is the one this project tried first; ADR 006 records why it was dropped.
 *
 * ## What every flag here is for
 *
 * `deserialize`, `validate` and `serialize` are off because each of them owns a failure channel and
 * JSON-RPC has one; {@see JsonRpcProcessor} explains what each would otherwise have answered.
 * `output: false` stops the schema factory minting a `JsonRpc` schema out of this empty class,
 * which would describe nothing. `status: 200` is documentation, and has to be said out loud
 * because API Platform's default for `POST` is `201 Created`, which is not a status JSON-RPC ever
 * sends. `formats` is `json` alone: there is no JSON-LD rendering of a protocol envelope, and
 * `hideHydraOperation` keeps `/rpc` out of the Hydra documentation for the same reason.
 *
 * This class has no properties on purpose. It is the anchor an `#[ApiResource]` needs, not a
 * representation of anything; the shapes on the wire are the protocol's, and
 * {@see \ApiPlatform\OpenApi\JsonRpcDocumentation} writes them.
 */
#[ApiResource(
    shortName: 'JsonRpc',
    description: 'A JSON-RPC 2.0 endpoint for the operations that are verbs rather than resources.',
    operations: [
        new Post(
            uriTemplate: '/rpc',
            status: 200,
            formats: ['json' => ['application/json']],
            inputFormats: ['json' => ['application/json']],
            outputFormats: ['json' => ['application/json']],
            processor: JsonRpcProcessor::class,
            output: false,
            read: false,
            deserialize: false,
            validate: false,
            serialize: false,
            hideHydraOperation: true,
            openapi: new Operation(
                tags: ['JSON-RPC'],
                summary: 'Call one or more methods',
                description: <<<'MARKDOWN'
                    A single endpoint implementing [JSON-RPC 2.0](https://www.jsonrpc.org/specification).
                    Send one request object or an array of them; the `method` member says what to
                    call, never the path.

                    **Everything this endpoint decides comes back as `200`**, including every
                    failure. The status line describes the transport, the `error.code` describes what
                    happened. That is the opposite of the REST surface, where the status *is* the
                    answer, and the two are not a matter of taste: a batch can contain one success
                    and one failure, and there is no status code for that.

                    **`204 No Content` is the other answer.** A request object with no `id` member is
                    a notification: it is executed and nothing is returned, errors included. A batch
                    of nothing but notifications answers `204` as well.

                    The table of methods and the table of error codes are below, both generated from
                    what is actually registered.
                    MARKDOWN,
            ),
        ),
    ],
)]
final class JsonRpcResource
{
}
