<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\TargetExcludeOrBuildStep;
use PHPat\Test\PHPat;

/**
 * `Shared` is the kernel every bounded context is allowed to reach into (see ADR 001's
 * "Communication from within"), which means the inverse can never be true: naming `Membership`,
 * `Matching` or `Ui` here would be a shared kernel that knows about its own consumers.
 */
final class SharedArchitectureTest extends ArchitectureTestCase
{
    #[TestRule]
    public function domain_independence(): TargetExcludeOrBuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(namespace: 'App\Shared\Domain'))
            ->canOnlyDependOn()
            ->classes(...[
                ...$this->domain(),
                Selector::inNamespace(namespace: 'App\Shared\Domain'),
            ]);
    }

    /**
     * `Shared\Application` is the read side's shared vocabulary (ADR 004): the two page-request
     * shapes, the two page-result shapes, and the parse failure they raise. It sits here rather
     * than in `Shared\Domain` because a page is a delivery constraint and no aggregate has ever had
     * an opinion about page size, and rather than in a context because both contexts answer lists.
     *
     * It may name `Shared\Domain` (it implements a marker from it) and nothing else of ours. A
     * vocabulary that could name `Membership`, `Matching` or `Ui` would stop being one.
     */
    #[TestRule]
    public function application_independence(): TargetExcludeOrBuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(namespace: 'App\Shared\Application'))
            ->canOnlyDependOn()
            ->classes(...[
                ...$this->application(),
                Selector::inNamespace(namespace: 'App\Shared\Domain'),
                Selector::inNamespace(namespace: 'App\Shared\Application'),
            ]);
    }

    #[TestRule]
    public function infrastructure_independence(): TargetExcludeOrBuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(namespace: 'App\Shared\Infrastructure'))
            ->canOnlyDependOn()
            ->classes(...[
                ...$this->infrastructure(),
                Selector::inNamespace(namespace: 'App\Shared\Domain'),
                Selector::inNamespace(namespace: 'App\Shared\Application'),
                Selector::inNamespace(namespace: 'App\Shared\Infrastructure'),
            ]);
    }

    /**
     * ADR 007's narrowing, and the second one in this repository's history after ADR 005 pulled
     * `Symfony\Component\Validator` out of `miscellaneous()`.
     *
     * `domain()` and `application()` already keep translation out by omission, so nothing new is
     * needed there. `infrastructure()` is the hole: it allows all of `Symfony` through `vendors()`,
     * which would let the shared kernel reach a translator.
     *
     * Nothing should. Under ADR 007 a failure carries a declared code and named parameters, and
     * turning that into words is a delivery's job, exactly as ADR 005 made turning a domain
     * exception into a `ConstraintViolation` a delivery's job. `App\Ui` has the only class in the
     * application that calls `trans()`.
     *
     * Worth enforcing precisely because the violation looks so reasonable: a notification adapter
     * that wants to put a sentence in a payload is one autowired argument away, and that is the
     * moment the design stops holding.
     */
    #[TestRule]
    public function no_translator_outside_ui(): TargetExcludeOrBuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(namespace: 'App\Shared'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(namespace: 'Symfony\Component\Translation'),
                Selector::inNamespace(namespace: 'Symfony\Contracts\Translation'),
                // `Translation\FailureMessages`, which is ours and no longer lives under `App\Ui`.
                // The component selectors above stopped being the whole rule the day the renderer
                // moved into `Shared/Infrastructure/`: a context that cannot reach `trans()` but
                // can reach the one class in the tree that calls it has not been stopped from
                // rendering, it has been stopped from importing. ADR 007's sentence is "only `Ui`
                // renders", and this is the selector that still makes that true.
                Selector::inNamespace(namespace: 'Translation'),
            );
    }

    /**
     * ADR 008, and the third narrowing this codebase has made rather than the third widening.
     *
     * ADR 005 took `Symfony\Component\Validator` out of `miscellaneous()`, where it had been
     * allowed all the way into a Domain. ADR 007 added `no_translator_outside_ui`. This closes the
     * same hole for the same reason, on the component where the mistake is easiest to make and
     * hardest to see.
     *
     * `domain()` and `application()` already keep it out by omission. `infrastructure()` is the
     * hole: it allows all of `Symfony` through `vendors()`.
     *
     * **The security vocabulary moved out of `Ui` and into `Shared\Infrastructure\Security`, so
     * this rule now carries an exclusion where it used to carry none.** That is worth stating
     * plainly because it is the rule getting weaker, not the tree getting tidier: before the move,
     * `App\Shared` naming `Symfony\Component\Security` was wrong without exception, and the
     * selector said so with nothing to read around it. The exclusion is one namespace wide and it
     * is the namespace that *is* the adapter, so the failure the rule was written for is still
     * caught; but a reader can no longer check the guarantee by reading the selector alone, and
     * ADR 008's "four questions, four homes" owes a paragraph about where the vocabulary now
     * lives. See "What this ADR still owes".
     *
     * The violation to be afraid of is not a malicious one, it is
     * `Symfony\Component\Security\Core\Security` autowired into a service by somebody in a
     * hurry so that a handler can find out who is asking. That compiles, it works in a request,
     * and it returns null in the worker and on the console, which are two of this tree's four
     * deliveries. ADR 008's second rule exists because of exactly that failure:
     *
     * > The actor is an argument, never ambient.
     *
     * Everything a delivery legitimately needs lives in `App\Shared\Infrastructure\Security`, and an
     * `Actor` reaches a context as an `Id<Member>` inside a command, which is a value and not a
     * principal.
     */
    #[TestRule]
    public function no_security_outside_ui(): TargetExcludeOrBuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(namespace: 'App\Shared'))
            // The security adapter itself, which is the one directory in `Shared` whose whole job
            // is to name the component. It is an exclusion and not a widening of `infrastructure()`:
            // `Shared\Infrastructure\Persistence` and `Shared\Infrastructure\Ecotone` are still
            // held to the rule, so the autowired `Security::getUser()` this exists to catch is
            // still caught everywhere it would actually be written.
            ->excluding(Selector::inNamespace(namespace: 'App\Shared\Infrastructure\Security'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(namespace: 'Symfony\Component\Security'),
                Selector::inNamespace(namespace: 'Symfony\Bundle\SecurityBundle'),
            );
    }
}
