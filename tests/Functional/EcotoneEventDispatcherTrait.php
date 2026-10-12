<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Ecotone\Lite\Test\FlowTestSupport;

/**
 * Dispatches an internal event.
 *
 * Usage:
 * <code>
 *     self::sendQuery(new GetFooCollection()); // dispatch a query
 *     self::sendCommand(new CreateFoo()); // dispatch a command
 *     self::publishEvent(new FooCreated()); // dispatch an event
 * </code>
 */
trait EcotoneEventDispatcherTrait
{
    private static function sendQuery(object $query): void
    {
        self::getContainer()->get(FlowTestSupport::class)->sendQuery(query: $query);
    }

    private static function sendCommand(object $command): void
    {
        self::getContainer()->get(FlowTestSupport::class)->sendCommand(command: $command);
    }

    private static function publishEvent(object $event): void
    {
        self::getContainer()->get(FlowTestSupport::class)->publishEvent(event: $event);
    }
}
