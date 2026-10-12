<?php

declare(strict_types=1);

namespace ApiPlatform\Metadata;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\OpenApi\Attributes\Webhook;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response;
use App\Shared\Infrastructure\Security\Guarded;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * ADR 008 option B's ReST surface: a resource carrying {@see Guarded} gets its operations'
 * `security` expression written here, and nowhere else.
 *
 * It is {@see DeclaredFiltersFactory} again, one seam over and for the other enumerable set. There,
 * a declaration that never learns API Platform exists is read by the delivery that does. Here the
 * declaration is an enum case and the delivery that reads it is this class, which is the **only
 * place in the application allowed to write an `is_granted()` string**. Everything else names a
 * {@see \App\Shared\Infrastructure\Security\Permission}.
 *
 * ## What "the document gets it for free" means
 *
 * The fifth of ADR 008's five reasons for an attribute of our own: a factory that reads the
 * declaration can also say, once, that a guarded operation answers `401` and `403`. Written
 * per-resource in an `openapi:` block it would read as a dozen local decisions that happen to
 * agree, and the thirteenth would forget; written here it is what it actually is, which is one
 * sentence about every guarded operation in the API. {@see \ApiPlatform\OpenApi\BadFilterInputResponses}
 * does the same thing for ADR 004's `400`, from the other end of the pipe.
 *
 * The two it does **not** add are as deliberate as the two it does. A `404` for an operation whose
 * subject is not yours is ownership, which is question 3, answered by the aggregate or by a scoped
 * provider; API Platform already documents a `404` on item operations, and it means the same thing
 * there by design (ADR 008 rule 11: a caller cannot tell the two cases apart, including from the
 * text). A `422` for an entitlement is question 4, raised by a handler that read a projection, and
 * a metadata factory has no way to know which operations can meet one.
 *
 * ## Why it refuses rather than merges when both are present
 *
 * `Ui` may name API Platform, so a hand-written `security:` expression compiles and works. ADR 008
 * is explicit that nothing structurally prevents one and that the enforcement is `app:access`
 * reporting the operation as unguarded. That is true of an operation with *only* an expression. An
 * operation with **both** is a different animal: two policies with one name, of which
 * `app:access` can see one and the runtime obeys the other. So it throws, at metadata build time,
 * naming both.
 *
 * ## Two decorators on one factory
 *
 * {@see DeclaredFiltersFactory} decorates the same service. They touch different fields of an
 * operation, so the order between them does not change the outcome; the explicit priority is there
 * so that it stays a decision rather than a property of the order two attributes happened to be
 * discovered in. This one sits **outside**, which is the useful way round if a later declaration
 * ever wants to read parameters in order to decide something.
 *
 * Like its neighbour, it decorates the public alias and therefore runs on every `create()`, outside
 * API Platform's metadata cache. That is affordable because it is attribute reading and array
 * building, and it is idempotent: setting the same expression twice is setting it once, and
 * `withResponse()` replaces the status it is given rather than appending to it.
 */
#[AsDecorator(decorates: 'api_platform.metadata.resource.metadata_collection_factory', priority: -10)]
final readonly class GuardedOperationsFactory implements ResourceMetadataCollectionFactoryInterface
{
    private const string UNAUTHENTICATED = <<<'MARKDOWN'
        No credential was presented, or the one presented is not recognised.

        The body is an RFC 9457 problem document and the response carries `WWW-Authenticate`.
        Authenticating is the next move, which is the one thing this answer says; a `403` is what
        comes back when the credential is fine and the permission is not.
        MARKDOWN;

    private const string FORBIDDEN = <<<'MARKDOWN'
        The caller is authenticated and does not hold the permission this operation asks for.

        The body names no permission and no identifier, deliberately: telling a caller which
        permission they lack publishes the shape of the policy to somebody who has just demonstrated
        they are not entitled to know it. That detail is in the server's log, next to the actor and
        the operation.

        This answer is about the caller and never about an object of ours. A subject that exists and
        is not yours answers `404`, identically to one that never existed.
        MARKDOWN;

    public function __construct(
        #[AutowireDecorated]
        private ResourceMetadataCollectionFactoryInterface $inner,
    ) {
    }

    #[\Override]
    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->inner->create($resourceClass);

        if (!class_exists($resourceClass)) {
            return $collection;
        }

        $guard = Guarded::on($resourceClass);

        if (null === $guard) {
            return $collection;
        }

        foreach ($collection as $index => $resource) {
            $operations = $resource->getOperations();

            if (null === $operations) {
                continue;
            }

            foreach ($operations as $name => $operation) {
                $operations->add($name, self::guard($operation, $guard, $resourceClass));
            }

            $collection[$index] = $resource->withOperations($operations);
        }

        return $collection;
    }

    private static function guard(Operation $operation, Guarded $guard, string $resourceClass): Operation
    {
        $expression = $guard->expression();
        $existing = $operation->getSecurity();

        if (null !== $existing && $existing !== $expression) {
            throw new \LogicException(\sprintf(
                '%s declares #[Guarded(%s)] and its "%s" operation also carries a hand-written security expression (%s). One operation cannot hold two policies: delete the expression, or delete the attribute and accept that `app:access` reports the operation as unguarded.',
                $resourceClass,
                $guard->permission->wire(),
                $operation->getName() ?? '(unnamed)',
                $existing,
            ));
        }

        $operation = $operation->withSecurity($expression);

        if (!$operation instanceof HttpOperation) {
            return $operation;
        }

        $openapi = $operation->getOpenapi();

        // `false` hides the operation from the document and a `Webhook` is a different shape
        // altogether; neither has responses to add one to. `null` means the resource declared no
        // prose, which is legal and rare here, and an operation built with nothing but these two
        // responses still merges: `OpenApiFactory` falls back to its own summary and tags for every
        // member left null.
        if (false === $openapi || $openapi instanceof Webhook) {
            return $operation;
        }

        $openapi ??= new OpenApiOperation();

        return $operation->withOpenapi(
            $openapi
                ->withResponse(401, new Response(description: self::UNAUTHENTICATED, content: self::problem()))
                ->withResponse(403, new Response(description: self::FORBIDDEN, content: self::problem())),
        );
    }

    /**
     * The same three media types `OpenApiFactory` emits next to a `404`, and the same three
     * {@see \ApiPlatform\OpenApi\BadFilterInputResponses} emits for ADR 004's
     * `400`, so every refusal in the document reads as one kind of answer rather than as three
     * conventions sharing a file.
     *
     * @return \ArrayObject<string, array{schema: array{"$ref": string}}>
     */
    private static function problem(): \ArrayObject
    {
        return new \ArrayObject([
            'application/ld+json' => ['schema' => ['$ref' => '#/components/schemas/Error.jsonld']],
            'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Error']],
            'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']],
        ]);
    }
}
