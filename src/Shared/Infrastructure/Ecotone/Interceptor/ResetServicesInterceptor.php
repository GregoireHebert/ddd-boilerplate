<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone\Interceptor;

use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Precedence;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

/**
 * Resets all `kernel.reset`-tagged services before an asynchronous message is handled on a
 * long-running worker (e.g. `php bin/console ecotone:run async`).
 *
 * `messenger:consume` runs Symfony's ResetServicesListener between messages (on WorkerRunningEvent),
 * returning stateful services; validator, security token storage, profiler, monolog processors,
 * etc. to a pristine state, just like the kernel does between HTTP requests. Ecotone's own consumer
 * loop never triggers that listener, so those services accumulate per-message state across the whole
 * worker lifetime. This restores the same "fresh request" guarantee.
 *
 * Doctrine identity maps are cleared separately by ecotone/dbal's ObjectManagerModule (wired in
 * EcotoneDbalConfiguration) — the service resetter does not empty the UnitOfWork, mirroring how
 * `messenger:consume` also pairs the service reset with a dedicated EntityManager clear.
 *
 * Runs once per consumed message just before the database transaction opens (pointcut
 * {@see AsynchronousRunningEndpoint}). Ecotone runs every `#[Before]` ahead of any `#[Around]`,
 * so the reset only clears the previous message's residue and never wipes the current message's identity.
 *
 * @see https://docs.ecotone.tech/modelling/extending-messaging-middlewares/interceptors#before-interceptor
 *
 * @author Grégoire Hébert <contact@gheb.dev>
 */
final readonly class ResetServicesInterceptor
{
    public function __construct(
        #[Autowire(service: 'services_resetter')]
        private ServicesResetterInterface $servicesResetter,
    ) {
    }

    #[Before(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: AsynchronousRunningEndpoint::class)]
    public function reset(): void
    {
        $this->servicesResetter->reset();
    }
}
