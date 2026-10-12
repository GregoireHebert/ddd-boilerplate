<?php

declare(strict_types=1);

namespace App\Ui\Api\StateProcessor;

use ApiPlatform\JsonRpc\JsonRpcServer;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole of `/rpc`'s integration with API Platform, and it is four lines because
 * {@see \ApiPlatform\JsonRpc\JsonRpcResource} turned everything else off.
 *
 * ## Why the pipeline is empty
 *
 * The operation declares `deserialize: false`, `validate: false` and `serialize: false`, so nothing
 * runs between the router and this method. That is not a shortcut around the framework; it is the
 * only correct wiring, because each of those steps has its own failure channel and JSON-RPC has
 * exactly one.
 *
 * Left on, a malformed body would be a `SerializerException` rendered as an RFC 9457 `400`, a
 * missing `method` would be a `ValidationException` rendered as a `422` with a `violations` array,
 * and an unknown method would be a `404` from the router. Three shapes, none of them JSON-RPC, for
 * three failures the specification names `-32700`, `-32600` and `-32601` and requires inside a
 * `200`. Turning the steps off moves the whole error channel into {@see JsonRpcServer}, which is
 * the only place that can answer in the protocol's own vocabulary.
 *
 * What stays on is `Accept` negotiation, and that one is the framework's to keep: asking for
 * `application/ld+json` from a protocol endpoint is a mistake about the transport rather than about
 * JSON-RPC, and a `406` says so better than any error code could.
 *
 * The request `Content-Type` is *not* checked, which is a consequence of `deserialize: false` and is
 * left that way on purpose. A body arrives, it either decodes as JSON or it does not, and "it does
 * not" already has an answer in `-32700`. Enforcing the header would add a second way to say the
 * same thing, in a different vocabulary, at a different status.
 *
 * ## Returning a Response object
 *
 * `RespondProcessor` passes a `Symfony\Component\HttpFoundation\Response` through untouched, which
 * is what makes `204` reachable. The operation still declares `status: 200`, and that number is
 * documentation rather than behaviour: the generated document needs a success status, and this
 * method is the runtime authority.
 *
 * @implements ProcessorInterface<null, Response>
 */
final readonly class JsonRpcProcessor implements ProcessorInterface
{
    public function __construct(private JsonRpcServer $server)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $request = $context['request'] ?? null;

        if (!$request instanceof Request) {
            throw new \LogicException('The JSON-RPC endpoint reads the raw body and therefore needs the HTTP request.');
        }

        $reply = $this->server->handle($request->getContent());

        // §6: a batch that produced no Response objects, and a lone notification, answer with
        // "nothing at all" rather than with an empty array.
        return $reply->isEmpty()
            ? new Response(status: Response::HTTP_NO_CONTENT)
            : new JsonResponse($reply, Response::HTTP_OK);
    }
}
