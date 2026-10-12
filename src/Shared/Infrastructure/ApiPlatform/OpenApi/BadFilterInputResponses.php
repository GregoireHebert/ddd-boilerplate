<?php

declare(strict_types=1);

namespace ApiPlatform\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * ADR 004's rule 11 in the generated document: "a parse failure is a 400".
 *
 * Every read endpoint in this project can answer 400, and not one of them said so. API Platform
 * documents the responses it can infer, which for a `GET` is the success status plus a 404 on item
 * operations; it has no way to know that {@see \App\Shared\Application\ReadModel\BadFilterInput}
 * exists, because the only thing standing between a query string and that exception is a provider's
 * body.
 *
 * This is a decorator rather than a `responses:` entry repeated on each operation, and the reason is
 * that the rule is not a property of any one endpoint. It is one sentence about the whole API:
 * anything that will not parse is a 400, whichever parameter carried it. Written five times it would
 * read as five local decisions that happen to agree, and the sixth endpoint would forget. Written
 * here it is what it actually is, and a new resource inherits it without anybody remembering to.
 *
 * The condition is "declares at least one query parameter", which is the honest one: an operation
 * with nothing to parse has nothing to reject. Today that is all five of them.
 *
 * `constraints: []` is why the status is 400 everywhere and never 422. Left to derive assertions
 * from each parameter's schema, API Platform would validate `enum` and `minimum` itself and answer
 * 422 for some of these mistakes and 400 for the rest, split by which JSON Schema keyword the
 * parameter happened to carry. `CandidateFeedResource`
 * argues that at length. This class documents the outcome of that choice; if the choice is ever
 * reversed, this becomes a lie and has to go.
 *
 * The response body mirrors the 404 that `OpenApiFactory` emits next to it, media type for media
 * type, so the two read as the same kind of answer rather than two conventions in one document.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class BadFilterInputResponses implements OpenApiFactoryInterface
{
    private const string DESCRIPTION = <<<'MARKDOWN'
        A filter value or window parameter could not be parsed.

        The body is an RFC 7807 problem document naming the parameter at fault. Nothing was
        defaulted and nothing was widened: the request was refused whole. Typical causes are a value
        outside a listed `enum`, a non-numeric `page`, `size` or `itemsPerPage`, a `page` below 1, a
        malformed date, and an `after` cursor this endpoint did not mint.

        A window *larger* than the documented maximum is not one of them; that is clamped and served.
        MARKDOWN;

    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $inner,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function __invoke(array $context = []): OpenApi
    {
        $openApi = $this->inner->__invoke($context);

        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            $operation = $pathItem->getGet();

            if (!$operation instanceof Operation || !self::parsesAnything($operation)) {
                continue;
            }

            $responses = $operation->getResponses() ?? [];

            if (isset($responses['400'])) {
                continue;
            }

            $openApi->getPaths()->addPath($path, $pathItem->withGet(
                $operation->withResponse('400', new Response(
                    description: self::DESCRIPTION,
                    content: new \ArrayObject([
                        'application/ld+json' => ['schema' => ['$ref' => '#/components/schemas/Error.jsonld']],
                        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Error']],
                        'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']],
                    ]),
                )),
            ));
        }

        return $openApi;
    }

    private static function parsesAnything(Operation $operation): bool
    {
        foreach ($operation->getParameters() ?? [] as $parameter) {
            if ('query' === $parameter->getIn()) {
                return true;
            }
        }

        return false;
    }
}
