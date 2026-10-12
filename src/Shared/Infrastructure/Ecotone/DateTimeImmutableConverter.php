<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone;

use Ecotone\Messaging\Attribute\Converter;

/**
 * Round-trips `\DateTimeImmutable` across a serializing channel (the `async` transport), the other
 * half of the same gap {@see IdConverter} closes: `CloseOneExpiredSubscription` carries one of each
 * and neither had a converter, so it never left the in-memory channel that `APP_ENV=test` swaps in.
 *
 * The wire shape is `DATE_ATOM`, both ways. That is not a new format chosen for this converter: it is
 * what `LikeService` already formats an instant with when it puts one
 * on `LikeCast`/`LikeReciprocated` (`$now->format(\DATE_ATOM)`), confirmed by reading that class
 * before writing this one. The two agree by construction, because this converter's `toString` side
 * is the same call, not a second format that happens to look compatible today and drift tomorrow.
 */
final class DateTimeImmutableConverter
{
    #[Converter]
    public function dateTimeImmutableToString(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format(\DATE_ATOM);
    }

    #[Converter]
    public function stringToDateTimeImmutable(string $value): \DateTimeImmutable
    {
        $dateTime = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);

        if (false === $dateTime) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a DATE_ATOM instant.', $value));
        }

        return $dateTime;
    }
}
