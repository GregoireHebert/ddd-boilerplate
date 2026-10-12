<?php

declare(strict_types=1);

namespace App\Shared\Application\Filter;

/**
 * ADR 004, option B: what can be asked of one read model, declared once, somewhere all three
 * deliveries can point at.
 *
 * What it does *not* do is generate a surface. Asking a `FilterSet` for its API Platform
 * `QueryParameter`s, as the ADR's own sketch does, would mean importing `ApiPlatform` into a shared
 * Application namespace, and the phpat rule on `App\Shared\Application` refuses that for the good
 * reason that a declaration which knows about one delivery is no longer delivery-agnostic. So the
 * arrow runs the other way: each delivery reads this and builds its own surface.
 *
 * Parsing is the half that genuinely is shared, because "what does a bad value mean" is one rule and
 * not three. It runs here, it throws {@see \App\Shared\Application\ReadModel\BadFilterInput}, and
 * each delivery renders that as a 400 or as exit 22.
 *
 * ADR 005 owns validation next, and when it lands this method is where the applicative validator
 * replaces the first-failure-wins behaviour below, so that a request with three bad values reports
 * three instead of one.
 */
final readonly class FilterSet
{
    /**
     * @param array<string, Filter> $filters keyed by name
     */
    private function __construct(
        public array $filters,
        public Window $window,
    ) {
    }

    public static function of(Filter|Window ...$parts): self
    {
        $filters = [];
        $window = null;

        foreach ($parts as $part) {
            if ($part instanceof Window) {
                if (null !== $window) {
                    throw new \LogicException('A declaration offers one pagination mechanism, not two (ADR 004, rule 8).');
                }

                $window = $part;

                continue;
            }

            $filters[$part->name] = $part;
        }

        return new self($filters, $window ?? throw new \LogicException('A declaration must say how its list is paginated; add a Window::pages() or Window::slice().'));
    }

    /**
     * @param array<string, mixed> $raw whatever the delivery collected, all of it strings
     */
    public function parse(array $raw): FilterValues
    {
        $values = [];

        foreach ($this->filters as $name => $filter) {
            $values[$name] = $filter->parse($raw[$name] ?? null);
        }

        // Unknown keys are ignored rather than refused. A query string carries things that are none
        // of this declaration's business, and a delivery that had to strip them first would be
        // doing the declaration's job badly.
        return new FilterValues($values, $this->window->parse($raw), $this->window->parseTotal($raw));
    }
}
