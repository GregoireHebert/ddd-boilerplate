<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;


/**
 * **A decision, written down where the oversight would have been.** ADR 009 rule 13, and the
 * sibling {@see Guarded} needed before `bin/console app:access` could tell the two apart.
 *
 * ```php
 * #[Post(uriTemplate: '/members', processor: RegisterMemberProcessor::class)]
 * #[Unguarded(because: 'registration is the transition from nobody to a member, so there is no actor to ask a question about')]
 * final class MemberRegistrationResource { }
 * ```
 *
 * {@see Guarded} takes a permission, so the absence of it is exactly the ambiguity in question:
 * `app:access` could see that an operation named no permission and could not see whether anybody
 * had thought about it. A write with no declaration of either kind stays a gap and still exits
 * non-zero; this one is reported with its sentence in the row and does not.
 *
 * ## Why an attribute rather than a list inside the command
 *
 * Three reasons, and ADR 009 gives all three by pointing at decisions this tree has already taken.
 *
 * It is a **declaration beside the operation**, so it moves when the operation moves, which is the
 * argument ADR 008 made for putting the permission enums in `Ui` next to the resources rather than
 * in each context.
 *
 * It is **enumerable**, so `app:access` walks the set rather than parsing anything, which is the
 * argument ADR 007 made for `FailureCode` cases over an AST extractor and ADR 006 made for one
 * class per RPC method.
 *
 * And a **required sentence makes the decision cost something to take**. A hardcoded path list
 * inside the command would let the next unguarded write join it silently, which is the drift the
 * command exists to catch.
 *
 * ## Matched, not diverged
 *
 * `TARGET_CLASS` and not repeatable, which is {@see Guarded}'s target and {@see Guarded}'s
 * repeatability exactly. The sibling framing is the point: a reader looking for the policy of a
 * resource finds either attribute in the same place, and a factory or a command reading one reads
 * the other with the same call.
 *
 * The coarseness argument is {@see Guarded}'s too, and it holds here for the same reason. ADR 006
 * makes every write its own resource with one operation, so a class carrying this has one
 * operation to carry it for. A multi-operation resource is a read resource, and a read that names
 * no permission is already ADR 008 option A's territory: it is listed and it was never a gap, so
 * it has nothing to declare here.
 *
 * ## What it deliberately does not do
 *
 * It writes nothing. {@see \ApiPlatform\Metadata\GuardedOperationsFactory}
 * turns its sibling into a `security` expression and into the `401` and `403` of the published
 * document; there is no expression that means "anybody" and no response an unguarded operation
 * gains, so nothing in the metadata layer reads this. Its only reader is `app:access`, which is
 * the whole of why it exists.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Unguarded
{
    public function __construct(
        /**
         * Why this operation has no actor to ask a question about, as a sentence somebody wrote.
         *
         * It is prose and not a code, and that is the one place this attribute parts company with
         * ADR 007's vocabulary: a failure code is a key a caller may one day read in their own
         * language, and this is an argument addressed to the next maintainer, printed by a console
         * command that renders at `default_locale` and shown to nobody else.
         */
        public string $because,
    ) {
        if ('' === trim($because)) {
            throw new \InvalidArgumentException(\sprintf(
                'An operation declared #[Unguarded] with no reason. The argument is the declaration: without it this says exactly what the absence of #[%s] already said, which is nothing, and `app:access` would report a decision it cannot read.',
                Guarded::class,
            ));
        }
    }

    /**
     * The declaration on one class, or null when it carries none.
     *
     * The mirror of {@see Guarded::on()}, so that a reader asking "what does this class say about
     * who may reach it" makes two calls of one shape and never reflects for itself.
     *
     * @param class-string $class
     */
    public static function on(string $class): ?self
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(self::class);

        if ([] === $attributes) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
