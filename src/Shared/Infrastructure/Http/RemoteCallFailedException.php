<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\DeclaredFailure;
use App\Shared\Domain\Exception\FailedDependencyThrowable;
use App\Shared\Domain\Exception\SharedFailure;

/**
 * A remote service answered, and the answer was not one the gateway knows how to read.
 *
 * It carries `FailedDependencyThrowable`, which `config/packages/api_platform.php` turns into a
 * 424, and that status is the honest one: the request was fine, we were fine, somebody we depend
 * on was not. Reporting it as a 500 would send people looking through our logs for a fault that is
 * not there.
 *
 * Note what this is *not* for. A 404 usually means something specific to the endpoint ("no such
 * subscription", "never heard of this subject") and each gateway decides what that means in its
 * own terms, which is normally null or an empty value rather than a failure. A 412 on a guarded
 * write means a concurrent change and gets `BillingConflictException`.
 * This is the rest: the 500s, the 403s, the HTML error page from a misconfigured proxy.
 *
 * ADR 007 turns out to fix something here that was not the ADR's business. The old messages
 * interpolated the upstream status, path and up to two hundred bytes of its response body straight
 * into `getMessage()`, and `Error::createFromException()` publishes that as the 424's `detail`. The
 * parameters still carry all of it, so a log still has it; what a caller sees is now whatever the
 * catalogue entry chooses to say, and the entry is free to say less.
 */
final class RemoteCallFailedException extends \RuntimeException implements FailedDependencyThrowable
{
    use DeclaredFailure;

    public static function withStatus(string $service, string $method, string $path, int $status, string $body = ''): self
    {
        return new self(SharedFailure::RemoteCallFailed, [
            'service' => $service,
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'body' => '' === $body ? '' : mb_substr(trim($body), 0, 200),
        ]);
    }

    public static function unreadable(string $service, string $method, string $path, \Throwable $previous): self
    {
        return new self(
            SharedFailure::RemoteCallUnreadable,
            ['service' => $service, 'method' => $method, 'path' => $path],
            $previous,
        );
    }
}
