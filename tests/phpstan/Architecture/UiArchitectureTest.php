<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\TargetExcludeOrBuildStep;
use PHPat\Test\PHPat;

/**
 * `Ui` sits outside every bounded context (ADR 001: "the UI Layer doesn't know or care about
 * 'User Management' or 'Billing'. It just expresses intentions."), so it is the one namespace
 * allowed to name every context's Domain — that is what makes it the composition point, not a
 * context of its own.
 *
 * Every context's `Application` too, and unavoidably: expressing an intention means putting a
 * command object on the bus, and command objects live there. What `Ui` still may not name is any
 * context's `Infrastructure`; a controller that can reach a gateway or a connection is a
 * controller that will.
 */
final class UiArchitectureTest extends ArchitectureTestCase
{
    #[TestRule]
    public function infrastructure_independence(): TargetExcludeOrBuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(namespace: 'App\Ui'))
            ->canOnlyDependOn()
            ->classes(...[
                ...$this->infrastructure(),
                Selector::inNamespace(namespace: 'App\Shared\Domain'),
                Selector::inNamespace(namespace: 'App\Shared\Application'),
                Selector::inNamespace(namespace: 'App\Shared\Infrastructure'),
                Selector::inNamespace(namespace: 'App\Membership\Domain'),
                Selector::inNamespace(namespace: 'App\Membership\Application'),
                Selector::inNamespace(namespace: 'App\Matching\Domain'),
                Selector::inNamespace(namespace: 'App\Matching\Application'),
                Selector::inNamespace(namespace: 'App\Ui'),
                // `Translation\FailureMessages`, ADR 007's one caller of `trans()`. It sits in
                // `Shared/Infrastructure/Translation/` and carries the renderer's own namespace
                // rather than `App\`, so neither `App\Shared\Infrastructure` above nor `Symfony`
                // in `vendors()` reaches it. Every delivery in `Ui` renders a failure, so this is
                // the one entry that has to be added by hand; the three context rule sets forbid
                // the same selector, which is what keeps "only `Ui` renders" true.
                Selector::inNamespace(namespace: 'Translation'),
            ]);
    }

    /**
     * No context's Domain (or, once it exists, Application) may reach back out to `Ui` — the
     * dependency arrow only ever points inward, per ADR 001.
     */
    #[TestRule]
    public function no_context_depends_on_ui(): TargetExcludeOrBuildStep
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace(namespace: 'App\Shared\Domain'),
                Selector::inNamespace(namespace: 'App\Membership\Domain'),
                Selector::inNamespace(namespace: 'App\Matching\Domain'),
            )
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(namespace: 'App\Ui'));
    }
}
