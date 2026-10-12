<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;


/**
 * **One declaration, read by every delivery.** ADR 008's option B, and the second user of a
 * mechanism this tree already pays for.
 *
 * ```php
 * #[Post(uriTemplate: '/likes', processor: CastLikeProcessor::class)]
 * #[Guarded(MatchingPermission::CastLike)]
 * final class LikeCastResource { }
 * ```
 *
 * ADR 004's option B is the shape: {@see \ApiPlatform\Metadata\DeclaredFilters}
 * points a resource at a declaration and
 * {@see \ApiPlatform\Metadata\DeclaredFiltersFactory} turns it into the
 * surface of one delivery while {@see \App\Ui\Console\Query\FilterSetOptions} turns the same
 * declaration into the surface of another. This attribute is that mechanism again, for the other
 * enumerable set:
 *
 * | delivery | what reads it                                                                       |
 * |----------|-------------------------------------------------------------------------------------|
 * | ReST     | {@see \ApiPlatform\Metadata\GuardedOperationsFactory}, which writes the `security` expression and the `401`/`403` responses |
 * | console  | {@see \App\Ui\Console\Security\ConsoleGuard}, which contributes `--as` and the gate call |
 * | RPC      | nothing: an RPC method declares its permission as {@see \ApiPlatform\JsonRpc\RpcMethod::permission()}, because a method is already a class with static declarations and a second mechanism beside them would be one too many |
 * | all      | `bin/console app:access`, which is the only reason any of this is enumerable        |
 *
 * ## Why an attribute of ours, and not API Platform's `security:`
 *
 * Five reasons, and none of them is "a framework string is ugly". ADR 008 lists them; the two that
 * decide it are that **`security:` feeds one delivery** and that **an attribute holding an enum
 * case is a value** rather than a small program parsed at request time with its own error channel.
 * The third is this: a factory can read an attribute and report it, and nothing can read the intent
 * of `'is_granted("ROLE_X") and object.owner == user'`, so an API declared that way cannot answer
 * "which writes are unguarded" and therefore cannot be checked.
 *
 * ## It takes a permission and it cannot take anything else
 *
 * No closure, no expression, no subject. That is deliberate and it is the same trick as
 * {@see Gate}'s absent third argument, applied one layer out: API Platform's expression language is
 * handed the object, so ADR 008's question 3 fits inside a `security:` string and the first person
 * in a hurry will put it there. Ownership answers `404` from the aggregate or from a scoped
 * provider, and this attribute makes that rule unwritable in the metadata layer as well as in the
 * port.
 *
 * ## Why it is not repeatable, and why one per class is enough
 *
 * It is declared on the class rather than on an operation, and it applies to **every** operation of
 * that class, exactly as `#[DeclaredFilters]` does. That looks too coarse for a resource such as
 * `MemberResource`, which serves a
 * directory and an item from one class and would want two different permissions.
 *
 * It is not, because of where ADR 008 draws the line between its two options: **option B for every
 * write, without exception, and option A for a read whose audience is "anybody authenticated".**
 * Every write in this tree is already its own resource with one operation (ADR 006: a registration
 * is not a member), so a class carrying this attribute has one operation to carry it for. A
 * multi-operation resource is a read resource, and a read that needs a permission per operation is
 * asking for two declarations, which is two classes.
 *
 * The honest weakness, which ADR 008 states rather than designs away: nothing structurally prevents
 * a hand-written `security:` expression, because `Ui` is allowed to name API Platform. The
 * enforcement is that `app:access` reports that operation as unguarded, which is not a compiler
 * error and is the best a metadata layer allows. It is also the right failure: the command reports
 * what the design can see, and an operation the design cannot see is one it does not have.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Guarded
{
    public function __construct(
        /**
         * The permission this operation asks for, as a case rather than as its wire string, so a
         * rename is an IDE refactor and a typo is a compile error. The string only appears at the
         * seam, which is {@see Permission::wire()} and the two places that call it.
         */
        public Permission $permission,
    ) {
    }

    /**
     * The declaration on one class, or null when it carries none.
     *
     * Every reader goes through here rather than reflecting for itself, for a reason that is easy
     * to miss: the API Platform factory *writes* a `security` expression from this attribute, so by
     * the time anything else sees an operation, a declared permission and a hand-written expression
     * look identical. `app:access` has to be able to tell them apart, so the attribute stays the
     * single source and the generated expression is never read back.
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

    /**
     * The expression an `is_granted()` call site would have written by hand, generated in the one
     * place allowed to write one.
     *
     * {@see PermissionVoter} is what answers it, and it answers the same question
     * {@see Gate::isGranted()} asks through `AccessDecisionManagerInterface`: one policy, two doors.
     * That is why the expression names the permission's wire string and never a role, and why the
     * voter refuses to support a call that passes a subject.
     */
    public function expression(): string
    {
        return \sprintf('is_granted(%s)', var_export($this->permission->wire(), true));
    }
}
