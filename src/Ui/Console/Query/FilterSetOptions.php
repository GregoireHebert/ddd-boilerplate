<?php

declare(strict_types=1);

namespace App\Ui\Console\Query;

use App\Shared\Application\Filter\Filter;
use App\Shared\Application\Filter\FilterKind;
use App\Shared\Application\Filter\FilterSet;
use App\Shared\Application\Filter\Window;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Option B's console surface, and the companion to
 * {@see \ApiPlatform\Metadata\DeclaredFiltersFactory}.
 *
 * One declaration, two surfaces, and neither is a translation of the other: `excludeLiked` becomes a
 * `boolean` query parameter over there and a `VALUE_NONE` flag here, because that is what each
 * delivery's users expect. `itemsPerPage` becomes `--items-per-page`, because a console option that
 * was camelCase would look like a mistake. ADR 004 puts that knowledge in the UI layer on purpose;
 * this class is where it sits for the console.
 *
 * {@see ListMembersCommand} is three lines of `configure()` as a result, and gains a filter the day
 * the declaration does.
 */
final class FilterSetOptions
{
    private function __construct()
    {
    }

    public static function applyTo(Command $command, FilterSet $set): void
    {
        foreach ($set->filters as $filter) {
            $command->addOption(
                self::option($filter->name),
                null,
                FilterKind::Boolean === $filter->kind ? InputOption::VALUE_NONE : InputOption::VALUE_REQUIRED,
                self::describe($filter),
            );
        }

        foreach ($set->window->parameters() as $name) {
            $command->addOption(self::option($name), null, InputOption::VALUE_REQUIRED, self::describeWindow($name, $set->window));
        }
    }

    /**
     * Back into the shape {@see FilterSet::parse()} takes, which is "whatever the delivery collected,
     * all of it strings". A `VALUE_NONE` flag is `true`/`false` rather than a string, so it is
     * spelled out here; everything else passes through untouched, because interpreting it is the
     * declaration's job and not this one's.
     *
     * @return array<string, mixed>
     */
    public static function rawFrom(InputInterface $input, FilterSet $set): array
    {
        $raw = [];

        foreach ($set->filters as $filter) {
            $value = $input->getOption(self::option($filter->name));

            $raw[$filter->name] = FilterKind::Boolean === $filter->kind
                ? (true === $value ? 'true' : 'false')
                : $value;
        }

        foreach ($set->window->parameters() as $name) {
            $raw[$name] = $input->getOption(self::option($name));
        }

        return $raw;
    }

    /**
     * `itemsPerPage` to `items-per-page`. The declaration names a parameter once; what a console
     * calls it is a console concern.
     */
    private static function option(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }

    private static function describe(Filter $filter): string
    {
        return FilterKind::Enumeration === $filter->kind
            ? \sprintf('%s One of: %s.', $filter->description, implode(', ', $filter->allowed))
            : $filter->description;
    }

    private static function describeWindow(string $name, Window $window): string
    {
        return match ($name) {
            Window::PAGE => 'Which page, 1-based',
            Window::ITEMS_PER_PAGE, Window::SIZE => \sprintf('How many per page (max %d)', $window->maxSize),
            Window::AFTER => 'A cursor printed by a previous run',
            Window::TOTAL => 'How hard to count: none, exact or estimated',
            default => '',
        };
    }
}
