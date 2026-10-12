<?php

declare(strict_types=1);

namespace App\Shared\Application\Filter;

/**
 * Which of ADR 004's two pagination mechanisms a declaration offers. There is no third, and there is
 * no "both".
 */
enum WindowKind
{
    case Pages;
    case Slice;
}
