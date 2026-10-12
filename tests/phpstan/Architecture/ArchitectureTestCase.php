<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;

abstract class ArchitectureTestCase
{
    /**
     * Allowed even in Domain, and deliberately so: none of these carry framework behaviour a
     * bounded context's meaning depends on.
     *
     * - `Symfony\Component\Uid` backs `Id` (see ADR 002) the way a hand-rolled ULID value object
     *   would; nothing here is a delivery concern.
     * - `Psr\Container` is the one framework-agnostic seam a named validation-group callable would
     *   be resolved through; a PSR interface has no vendor behind it to leak.
     * - `Psr\Clock` is how an aggregate or a handler asks what time it is without reaching for
     *   `new \DateTimeImmutable()`; same argument as above, a PSR interface with no vendor behind
     *   it, and the one that makes "when did this happen" testable.
     * - `Doctrine\Common\Collections` is how an aggregate holds a child collection (`Member::badges()`)
     *   without naming the ORM: an interface package, not a persistence mechanism. It used to be
     *   justified here by a shared `Filter\Specification` and `Pagination`; ADR 004 removed those,
     *   and the `Criteria` half of this package went with them. Nothing may reach for it again.
     * - `Webmozart\Assert` is an assertion helper with no framework behind it.
     */
    protected function miscellaneous(): iterable
    {
        return [
            Selector::inNamespace(namespace: 'Symfony\Component\Uid'),
            Selector::inNamespace(namespace: 'Psr\Container'),
            Selector::inNamespace(namespace: 'Psr\Clock'),
            Selector::inNamespace(namespace: 'Doctrine\Common\Collections'),
            Selector::inNamespace(namespace: 'Webmozart\Assert'),
        ];
    }

    /**
     * The infrastructure ring: what an `Infrastructure` layer may name, in any namespace.
     *
     * `Twig` is the fourth entry and the newest, added by
     * {@see docs/ADR/001_architectural-organization.md} "Twig is a vendor a delivery may name".
     * The narrower option was available and deliberately not taken: allowing it only under
     * `App\Ui`, the shape ADR 007's `no_translator_outside_ui` and ADR 008's
     * `no_security_outside_ui` both use. Those two exist because naming their component outside a
     * delivery is a bug that compiles and then returns null in the worker. Twig has no such failure
     * mode, and a rule set that also collects preferences stops carrying weight for the three cases
     * that genuinely bite. The ADR holds the argument in full.
     *
     * `Lexik` is the fifth, added by ADR 009 rule 9 for
     * {@see \App\Shared\Infrastructure\Security\AccessTokens}, and it is here on exactly the criterion
     * the paragraph above sets out rather than on a new one. A JWT library named in a context's
     * `Infrastructure` would be odd, and it would be *visible*: it fails at the type, not at
     * runtime in a worker, so it is a preference and not one of the three cases that bite. If a
     * narrowing is ever wanted for it, the shape is `no_security_outside_ui`'s and the argument
     * would have to be a failure mode, not tidiness.
     *
     * It took `Firebase`'s place rather than joining it, which is the part worth noticing: the
     * entry moved because the library behind one class changed, and the criterion did not have to
     * be reopened to let the new one in or to throw the old one out. An allow-list whose entries
     * each rest on a stated sentence survives a dependency swap; one that has collected a line per
     * vendor has to be argued again every time, and nobody does.
     *
     * **`Lexik` is a bundle, and allowing it here is wider than allowing a library was.** `Firebase`
     * was three classes with no framework behind them. This namespace also contains a firewall
     * authenticator, a user provider and an API Platform integration, none of which is enabled and
     * all of which `phpat` would now let an `Infrastructure` class name. That is the admitted cost
     * of the swap. It is survivable on the same sentence as above, because reaching for Lexik's
     * authenticator from a context would be the kind of wrong a reviewer sees rather than the kind
     * that compiles and returns null in a worker, and `config/packages/lexik_jwt_authentication.php`
     * states in one place that none of it is switched on.
     */
    protected function vendors(): iterable
    {
        return [
            Selector::inNamespace(namespace: 'ApiPlatform'),
            Selector::inNamespace(namespace: 'Doctrine'),
            Selector::inNamespace(namespace: 'Ecotone'),
            Selector::inNamespace(namespace: 'Lexik'),
            Selector::inNamespace(namespace: 'Psr'),
            Selector::inNamespace(namespace: 'Symfony'),
            Selector::inNamespace(namespace: 'Twig'),
        ];
    }

    /**
     * Domain's default allow-list: the framework-free conveniences above, plus itself.
     *
     * `Ecotone\Modelling\Attribute` is in there for exactly one aggregate,
     * {@see \App\Membership\Domain\Model\Subscription}, and the line it draws is narrow on purpose.
     * `#[Aggregate]` and `#[Identifier]` are declarations *about* the object (it is a consistency
     * boundary; this field identifies it) that would still be true with no framework installed.
     * `#[CommandHandler]` and `#[TargetIdentifier]` are routing, and allowing them here is the
     * admitted cost of ADR 003's option A, paid once so the alternative stays visible by contrast.
     *
     * The rest of Ecotone stays out: no bus, no interceptor, no message channel in a Domain.
     */
    protected function domain(): iterable
    {
        return [
            ...$this->miscellaneous(),
            Selector::inNamespace(namespace: 'Ecotone\Modelling\Attribute'),
        ];
    }

    /**
     * What an `#[CommandHandler]`/`#[QueryHandler]` service is allowed to import per "Key
     * mechanisms" in CLAUDE.md: the CQS attributes plus whatever Domain already allows.
     *
     * `Ecotone\Modelling` (the namespace root, not just `Attribute`) is in there for the two
     * runtime seams a handler legitimately holds: `CommandBus`/`EventBus` when a handler has to
     * dispatch onward, and `Ecotone\Modelling\Attribute\Interceptor` pointcuts. Nothing else from
     * Ecotone belongs at this level, and the Domain allow-list below deliberately stays narrower.
     *
     * `Symfony\Component\Validator` is here and *not* in `miscellaneous()`, which is ADR 005
     * narrowing a rule rather than widening one. It used to be allowed all the way into a Domain,
     * justified by what the deleted `Shared\Domain\Validation` returned. What needs it now is
     * {@see \App\Shared\Application\Validation\Violations}, which accumulates parse failures into a
     * `ConstraintViolationList` so that both of ADR 005's designs answer with the same type. A
     * Domain never names it: a domain exception says what is wrong, and turning that into a
     * violation is the edge's job.
     */
    protected function application(): iterable
    {
        return [
            ...$this->miscellaneous(),
            Selector::inNamespace(namespace: 'Ecotone\Modelling'),
            Selector::inNamespace(namespace: 'Ecotone\Messaging\Attribute'),
            Selector::inNamespace(namespace: 'Symfony\Component\Validator'),
        ];
    }

    /**
     * Infrastructure is the outermost ring: every vendor plus the conveniences already allowed
     * inward.
     */
    protected function infrastructure(): iterable
    {
        return [
            ...$this->miscellaneous(),
            ...$this->vendors(),
        ];
    }
}
