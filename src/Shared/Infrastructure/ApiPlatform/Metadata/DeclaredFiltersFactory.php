<?php

declare(strict_types=1);

namespace ApiPlatform\Metadata;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use App\Shared\Application\Filter\Filter;
use App\Shared\Application\Filter\FilterKind;
use App\Shared\Application\Filter\FilterSet;
use App\Shared\Application\Filter\Window;
use App\Shared\Application\ReadModel\Total;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Option B's API Platform surface: every operation of a resource carrying {@see DeclaredFilters}
 * gets its `QueryParameter`s built from the declaration instead of written out by hand.
 *
 * Put the result next to `CandidateFeedResource`,
 * which is option A: there, five parameters are restated in the attribute with their enum values
 * spelled out, and nothing keeps them in step with the query object. Here the OpenAPI document, the
 * accepted values and the console's options all come from one file, and adding a filter to the
 * declaration makes it appear in all three without anybody editing them.
 *
 * `constraints: []` on everything it generates, for the reason `CandidateFeedResource` spells out:
 * left to derive its own, API Platform would validate `enum` and `minimum` before the provider runs
 * and answer 422, so this one endpoint would use two status codes for one kind of mistake depending
 * on which JSON Schema keyword each parameter happened to carry. The schema stays for the
 * documentation; the rule stays in one place.
 *
 * It decorates the public alias, so it runs outside API Platform's metadata cache and therefore on
 * every `create()`. That is affordable because it is pure attribute reading and array building, and
 * replacing (never appending to) each operation's parameters keeps it idempotent when the inner
 * factory hands back a cached collection.
 */
#[AsDecorator(decorates: 'api_platform.metadata.resource.metadata_collection_factory')]
final readonly class DeclaredFiltersFactory implements ResourceMetadataCollectionFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ResourceMetadataCollectionFactoryInterface $inner,
    ) {
    }

    #[\Override]
    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->inner->create($resourceClass);
        $declaration = self::declarationOf($resourceClass);

        if (null === $declaration) {
            return $collection;
        }

        foreach ($collection as $index => $resource) {
            $operations = $resource->getOperations();

            if (null === $operations) {
                continue;
            }

            foreach ($operations as $name => $operation) {
                $operations->add($name, $operation->withParameters(self::parametersFor($declaration)));
            }

            $collection[$index] = $resource->withOperations($operations);
        }

        return $collection;
    }

    /**
     * @param class-string $resourceClass
     */
    private static function declarationOf(string $resourceClass): ?FilterSet
    {
        if (!class_exists($resourceClass)) {
            return null;
        }

        $attributes = (new \ReflectionClass($resourceClass))->getAttributes(DeclaredFilters::class);

        if ([] === $attributes) {
            return null;
        }

        $declaration = $attributes[0]->newInstance()->declaration;

        if (!method_exists($declaration, 'declaration')) {
            throw new \LogicException(\sprintf('%s points at %s, which has no static declaration(): FilterSet.', $resourceClass, $declaration));
        }

        $set = $declaration::declaration();

        if (!$set instanceof FilterSet) {
            throw new \LogicException(\sprintf('%s::declaration() must return a FilterSet.', $declaration));
        }

        return $set;
    }

    private static function parametersFor(FilterSet $set): Parameters
    {
        $parameters = new Parameters();

        foreach ($set->filters as $filter) {
            $parameters->add($filter->name, new QueryParameter(
                key: $filter->name,
                schema: self::schemaFor($filter),
                description: $filter->description,
                constraints: [],
            ));
        }

        foreach ($set->window->parameters() as $name) {
            $parameters->add($name, new QueryParameter(
                key: $name,
                schema: self::windowSchema($name, $set->window),
                description: self::windowDescription($name, $set->window),
                constraints: [],
            ));
        }

        return $parameters;
    }

    /**
     * An `examples` entry wherever the declaration already knows one, which is option B's quiet
     * advantage over option A on the documentation side: an enum filter can show a value it will
     * actually accept without anybody choosing one, and it cannot show a stale one, because the list
     * it picks from is the enum the parser uses.
     *
     * Deliberately never a `default`, even though several of these have one.
     * {@see \ApiPlatform\State\Provider\ParameterProvider} reads `schema['default']` at request time
     * and fills the value in when the parameter is absent, so a default written here would stop
     * being documentation and start being behaviour, decided in the delivery layer rather than by
     * {@see Window}. Defaults go in the prose below instead.
     *
     * @return array<string, mixed>
     */
    private static function schemaFor(Filter $filter): array
    {
        return match ($filter->kind) {
            FilterKind::Enumeration => ['type' => 'string', 'enum' => $filter->allowed, 'examples' => \array_slice($filter->allowed, 0, 1)],
            FilterKind::Text => ['type' => 'string'],
            FilterKind::Boolean => ['type' => 'boolean', 'examples' => [true]],
            FilterKind::Date => ['type' => 'string', 'format' => 'date-time', 'examples' => ['2026-09-01T00:00:00+00:00']],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function windowSchema(string $name, Window $window): array
    {
        return match ($name) {
            Window::PAGE => ['type' => 'integer', 'minimum' => 1, 'examples' => [1]],
            Window::ITEMS_PER_PAGE, Window::SIZE => ['type' => 'integer', 'minimum' => 1, 'examples' => [$window->defaultSize]],
            // `total` is not nullsafe here, and does not need to be: {@see Window::parameters()}
            // only lists this name when the window offers the choice, so reaching this arm with a
            // null default is not a state that exists. PHPStan agrees today for a stronger reason,
            // having narrowed the property across the whole program, and it is the thing that will
            // object if a second declaration ever makes it nullable again.
            Window::TOTAL => [
                'type' => 'string',
                'enum' => array_map(static fn (Total $case): string => $case->value, Total::cases()),
                'examples' => [$window->total->value],
            ],
            default => ['type' => 'string'],
        };
    }

    private static function windowDescription(string $name, Window $window): string
    {
        return match ($name) {
            Window::PAGE => 'Which page, 1-based (default 1). Page 0 is a 400, never a silent page 1.',
            Window::ITEMS_PER_PAGE, Window::SIZE => \sprintf('How many per page (default %d). Anything above %d is clamped, not refused.', $window->defaultSize, $window->maxSize),
            Window::AFTER => 'A cursor from a previous response. Opaque: not a page number and not a date.',
            Window::TOTAL => \sprintf(
                'How hard to work out how many there are (default "%s"). "none" is free and omits `totalItems` and the `last` link entirely; "exact" costs a COUNT over the filtered set; "estimated" is a planner statistic, cheap and approximate by an unbounded amount, so do not do arithmetic with it.',
                $window->total->value,
            ),
            default => '',
        };
    }
}
