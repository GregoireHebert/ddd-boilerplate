<?php

declare(strict_types=1);

namespace ApiPlatform\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Tag;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * One description per tag, written once, in an order nobody has to guess.
 *
 * ADR 005 put every write on its own resource class, which is the right model (a registration is not
 * a member) and leaves several classes sharing one tag: `/members` is served by `MemberResource`,
 * `MemberRegistrationResource`, `MemberHandleResource`, `MatchingPreferencesResource` and
 * `MemberErasureResource`, all tagged `Member` so the rendered document keeps them together.
 *
 * `OpenApiFactory` builds a tag's description as
 * `$tags[$name] = new Tag(description: $resource->getDescription())`, once per resource, keyed by
 * name. So whichever class the metadata factory reaches last supplies the description for the whole
 * group, silently and in class-iteration order. And the descriptions cannot simply be left off the
 * resources: CLAUDE.md's first OpenAPI rule exists because without `description:` API Platform falls
 * back to the class PHPDoc and publishes the teaching docblock to callers.
 *
 * The configured `openapi.tags` option looks like the way out, and is not: API Platform turns those
 * arrays into `Tag` objects inside the extension, and the container refuses to dump a parameter
 * holding an object ("Unable to dump a service container if a parameter is an object or a resource,
 * got ApiPlatform\OpenApi\Model\Tag"). So it happens here, after the document is built, which is
 * where {@see BadFilterInputResponses} already puts the other fact that is about the API rather than
 * about any one endpoint.
 *
 * A tag an operation uses and this class does not name keeps whatever description it inherited,
 * which is the failure mode to watch for: it will be some resource's `description:`, and which one
 * depends on iteration order. The check is that this list and the `tags:` in the resources name the
 * same set.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class TagDescriptions implements OpenApiFactoryInterface
{
    /**
     * @var array<string, string>
     */
    private const array DESCRIPTIONS = [
        // **One entry per tag, declared here and nowhere else**, because `OpenApiFactory`
        // otherwise lets whichever resource is processed last supply the description for a whole
        // tag. The `openapi.tags` config option cannot be used instead: the extension builds `Tag`
        // objects and the container refuses to dump an object parameter.
        //
        //     'Member'  => 'The directory, one profile, and everything a member can change about themselves.',
        //     'Badge'   => 'What a member owns, read. The writes are methods on `POST /rpc`, because a badge is a child of the `Member` aggregate and has no address of its own (ADR 006).',
        //
        // A description is written for a CALLER. The class docblock is for a maintainer, and the
        // reason `description:` is mandatory on every `#[ApiResource]` is that without it API
        // Platform publishes the docblock here instead.
        'JSON-RPC' => 'One endpoint, JSON-RPC 2.0, for the operations that are verbs rather than resources. Failures arrive as `200` with an `error.code`; a call with no `id` is a notification and answers `204`.',
    ];

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
        $openApi = ($this->inner)($context);

        $tags = array_map(
            static fn (Tag $tag): Tag => isset(self::DESCRIPTIONS[$tag->getName()])
                ? $tag->withDescription(self::DESCRIPTIONS[$tag->getName()])
                : $tag,
            $openApi->getTags(),
        );

        return $openApi->withTags($tags);
    }
}
