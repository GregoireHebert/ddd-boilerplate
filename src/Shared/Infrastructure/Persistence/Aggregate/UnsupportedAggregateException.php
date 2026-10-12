<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Aggregate;

/**
 * A wiring mistake, not a domain failure, so it carries none of the `Shared\Domain\Exception`
 * markers: there is no HTTP status that means "this application was assembled wrong".
 */
final class UnsupportedAggregateException extends \LogicException
{
    /**
     * @param class-string $aggregateClass
     */
    public static function noReaderFor(string $aggregateClass): self
    {
        return new self(\sprintf('No AggregateReader claims "%s"; is one tagged app.aggregate_reader?', $aggregateClass));
    }

    /**
     * @param class-string $aggregateClass
     */
    public static function noWriterFor(string $aggregateClass): self
    {
        return new self(\sprintf('No AggregateWriter claims "%s"; is one tagged app.aggregate_writer?', $aggregateClass));
    }

    /**
     * @param class-string $aggregateClass
     */
    public static function readerReturnedWrongClass(string $aggregateClass, object $returned): self
    {
        return new self(\sprintf('An AggregateReader claimed "%s" but returned "%s".', $aggregateClass, $returned::class));
    }

    /**
     * @param class-string $writerClass
     */
    public static function notWritableBy(object $aggregate, string $writerClass): self
    {
        return new self(\sprintf('"%s" cannot write a "%s"; supports() and the store disagree.', $writerClass, $aggregate::class));
    }
}
