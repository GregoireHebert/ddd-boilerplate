<?php

declare(strict_types=1);

namespace ApiPlatform\Metadata;


/**
 * Points a resource at the {@see \App\Shared\Application\Filter\FilterSet} that says what can be
 * asked of it.
 *
 * It exists because of a plain PHP limitation that ADR 004's option B sketch walks straight into:
 * attribute arguments must be constant expressions, so
 *
 *     #[GetCollection(parameters: MemberDirectoryFilters::declaration()->asQueryParameters())]
 *
 * does not compile. A class name is a constant expression, so this attribute carries the reference
 * and {@see DeclaredFiltersFactory} resolves it when metadata is built.
 *
 * It turns out to be the better shape anyway. The declaration never learns that API Platform exists,
 * which is what its own architecture rule requires, and the delivery that does know reads it.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class DeclaredFilters
{
    /**
     * @param class-string $declaration a class with a static `declaration(): FilterSet`
     */
    public function __construct(public string $declaration)
    {
    }
}
