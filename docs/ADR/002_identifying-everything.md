# Identifying everything

For this project we are using [phpstan](https://phpstan.org/) as a type checker.

## Identifier

Let's consider a common entity of our domains, let's call it `Member`, which is distinguishable by some kind of identifier. Let's say, for the sake of the example, that we are using integers as identifiers.

To make sure not to confuse our identifiers with other integer values in our application, we decide to wrap them in a class called `Id`.

```php
final class Id implements \Stringable
{
    public function __construct(private int $value)
    {
    }
    
    public function toInt(): int
    {
        return $this->value;
    }
    
    #[\Override]
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
```

In this way we can distinguish at the type level an `Id` from any other integer in our application. For example, in our entity `Member` we are going to type hint its identifier with `Id` and not just simpl with int. This has the deficit that we have to wrap and unwrap our integer value for storage or serialization, but has the big advantage of preventing passing an age, a day, a year or any other numeric value where an identifier was expected.

Now we are happy because we have increased the type safety of our application! Until the next entity comes along…

Consider now another entity `Pair` which needs to refer to the same type of identifier `Member` had. We are immediately tempted to use the same `Id` class we were using for `Member`, it doesn't make sense to write another class which behaves exactly like `Id` does. 

Or does it?

If we reuse the same class, we'll still be able to distinguish between Ids and other integer values, but we're not going to be able to distinguish between Ids for `Member` and Ids for `Pair`. That's something we might actually want to do. Create 2 classes to distinguish between `MemberId` and `PairId`, so that we'll always know at compile and static analysis time if we're dealing with an identifier for a `Member` entity or for a `Pair` one. 

Consider for example repositories for `Member` and `Pair`; the repository `MemberRepository` could have a method `getMember(MemberId $id): Member` which accepts just identifiers for `Member`, making it impossible to pass an identifier for `Pair`. This is definitely a win for type safety, but we're basically duplicating code in two identical classes which differ only for their name.

One could save code duplication defining an abstract class `Id` and concrete classes `MemberId` and `PairId` inheriting from it.

```php
abstract class Id implements \Stringable
{
    public function __construct(private int $value)
    {
    }

    public function toInt(): int
    {
        return $this->value;
    }
    
    #[\Override]
    public function __toString(): string
    {
        return (string) $this->value;
    }
}

final class MemberId extends Id {}

final class PairId extends Id {}
```

In this way we are not duplicating code, but still every time we introduce a new entity in out system, we need to define also a new class and extends `Id` for its identifier. In the long run, for big projects, this could become cumbersome.

If we examine carefully our desire to distinguish between `MemberId` and `PairId`, we quickly realize that we are trying to communicate information at the type level. In fact, this is evident from the fact that the implementations are precisely the same. This observation suggests us that maybe we should try to use type level mechanisms to convey such information and not value level tools like inheritance.

One trick we could actually use is add a type variable to our `Id` class and use it to tag the identifier with the entity it is actually referring to.

```php
/**
 * @template A
 */
final class Id
{
    public function __construct(private int $value)
    {
    }
    
    public function toInt(): int
    {
        return $this->value;
    }
    
        #[\Override]
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
```

The only difference with our first version of `Id` is that we now added the `@template` annotations to introduce the `A` type variable. Now we can simply refer to `Id<Member>` and `Id<Pair>` to speak about identifiers for Member and Pair, respectively.

```php
interface MemberRepository
{
  /**
   * @phpstan-param Id<Member> $id
   * @return Member
   */
  public function getMember(Id $id): Member
}
```

This guarantees the same type safety as our intermediate solution using inheritance but avoids completely the need to create specific  classes for every new entity.

Such kind of type variables, which are used only at the type level and do not refer to anything at the value level, are usually called **phantom types**.

## Example

An unspecialized Id would get this error from PHPStan (configured at lvl 5 minimum).

```php
<?php 

declare(strict_types = 1);

/**
 * @template A
 */
final class Id
{
    public function __construct(private int $value)
    {
    }
    
    public function toInt(): int
    {
        return $this->value;
    }
    
        #[\Override]
    public function __toString(): string
    {
        return (string) $this->value;
    }
}

interface MemberRepositoryInterface
{
  /**
   * @phpstan-param Id<Member> $id
   * @return Member
   */
  public function getMember(Id $id): Member;
}

class Member 
{
	/**
     * @phpstan-param Id<Member> $id
     */
	public function __construct(public Id $id)
    {
    }
}

class MemberRepository implements MemberRepositoryInterface
{
	#[\Override]
	public function getMember(Id $id): Member
	{
		return new Member($id);
	}
}

$repository = new MemberRepository();
$id = new Id(12);

$Member = $repository->getMember($id); // Parameter #1 $id of method MemberRepository::getMember() expects Id<Member>, Id<mixed> given.
echo $Member->id;
```

While this would be ok:

```php
// ...

$repository = new MemberRepository();

/** @var Id<Member> $id */
$id = new Id(12);

$Member = $repository->getMember($id);
echo $Member->id;
```

And If we had the Pair equivalent classes and repositories, trying to pass an Id coming from something else than the expected, we would get a similar error.

```php
$MemberRepository = new MemberRepository();
$PairRepository = new PairRepository();

/** @var Id<Member> $id */
$id = new Id(12);

$Member = $MemberRepository->getMember($id);
echo $Member->id;

$Pair = $PairRepository->getPair($Member->id); // Parameter #1 $id of method PairRepository::getPair() expects Id<Pair>, Id<Member> given.
echo $Pair->id;
```

## The phantom type is covariant, and nothing needed that until ADR 008

`Id` was declared `@template A`, which means PHPStan treats `A` as **invariant**: an `Id<Member>` is
not an `Id<Something>` even when a `Member` is a `Something`.

Nothing noticed, because nothing asked. Every `A` in this tree was a concrete aggregate, and a
concrete aggregate is never a supertype of another one. Then [ADR 008](008_security.md) put a
principal's identifier into `App\Shared`, which may not name a bounded context, and the question
arrived all at once: what is the `A` in the identifier of "whoever is asking"?

```php
/**
 * @template-covariant A
 */
final readonly class Id implements Equality
```

The annotation is a claim about the class, not a convenience. `A` is a phantom: it appears in no
parameter and in no property, so there is no position where accepting a subtype could be unsound.
The one method where it did appear in a parameter, `sort(self $other)`, now takes `self<*>`, which
is honest rather than a workaround: an ordering reads the wrapped `Ulid` and the phantom type has
no bearing on it.

What covariance buys is the widening direction. An `Id<Member>` is usable wherever an
`Id<PrincipalAggregate>` is wanted, which is what lets `Shared` hold a principal without naming
`Membership`.

**The narrowing direction stays unavailable, deliberately.** Going from `Id<PrincipalAggregate>`
back to `Id<Member>` is a claim no type system here can check, and it is a claim only a delivery is
in a position to make, because the delivery layer is the only thing that knows this application
authenticates as a `Member`. So it is made in one place and declared rather than commented:

```php
/**
 * @template T of PrincipalAggregate
 * @param class-string<T> $principal
 * @return Id<T>
 */
public function subject(string $principal): Id
```

`$principal` is never read. Its whole job is to let PHPStan resolve `T`, so `$actor->subject(Member::class)`
returns an `Id<Member>` and the twenty three call sites downstream need no annotation at all. The
alternative was `/** @var Id<Member> */` at each of them, which is the same unproven assertion made
twenty three times rather than once, and it would have drifted the first time somebody copied a
processor.

This is the convention admitting its own limit, which is the same limit the example above
demonstrates: `Id` is one Ulid-wrapping class whatever the brackets say, PHP checks none of it, and
`InspectPairCommand` is kept broken in the tree so that the limit has a name and a file.
