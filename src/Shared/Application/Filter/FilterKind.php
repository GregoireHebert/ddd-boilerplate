<?php

declare(strict_types=1);

namespace App\Shared\Application\Filter;

/**
 * The closed set of things a declared filter can be.
 *
 * Closed is the load-bearing word. ADR 004's option C is an expression tree, and its failure mode is
 * that the node set only ever grows until every store has to implement every node or throw at
 * runtime. This enum is the same shape of thing with the growth stopped: four kinds, each of which
 * every store in the project can answer with an equality or a comparison, and no `Expression` case.
 *
 * The day somebody adds one, option B has become option C without anyone deciding to.
 */
enum FilterKind
{
    case Enumeration;
    case Text;
    case Boolean;
    case Date;
}
