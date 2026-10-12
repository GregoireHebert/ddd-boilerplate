<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

/**
 * The only thing in this application allowed to work out who is asking.
 *
 * One implementation per delivery, and that is the entire interface. Everything else receives an
 * {@see Actor} as an argument, which is ADR 008's second rule and the reason the console works at
 * all:
 *
 * | delivery | the implementation reads | the `Authority` it stamps |
 * |---|---|---|
 * | ReST, RPC | the bearer token on the request, through Symfony's token storage | {@see Authority::Token} |
 * | Twig | the session, through the same storage | {@see Authority::Session} |
 * | console, `--as` | an `InputOption`, and the user provider | {@see Authority::Assertion} |
 * | console, everything else | nothing at all | {@see Authority::None} |
 *
 * That last row is the one that keeps the design honest, and it is why the return type is `Actor`
 * rather than `?Actor`: {@see Actor::anonymous()} is a value, an operation reached with it is
 * *unguarded* rather than privileged, and there is no `Actor::system()` for it to be privileged as.
 * A nullable return would push that decision to every call site, and one of them would get it
 * wrong in the direction that does not fail.
 *
 * ## The three implementations, and why none of them is aliased to this interface
 *
 * {@see TokenCurrentActor} (ReST and RPC, one class for two rows), {@see SessionCurrentActor} (Twig)
 * and {@see \App\Ui\Console\Security\AssertedActor} (the console's `--as`). Four rows, three classes,
 * four {@see Authority} cases.
 *
 * Nothing carries `#[AsAlias(CurrentActor::class)]`, and that is a decision rather than an omission:
 * aliasing one of them would make the other two exceptions to it, and the whole point of the table
 * above is that no delivery is the default. A consumer names the one it is, which also means a
 * processor that was written for the API cannot be reused by the console without somebody noticing:
 *
 * ```php
 * public function __construct(
 *     #[Autowire(service: TokenCurrentActor::class)] private CurrentActor $actors,
 * ) {}
 * ```
 *
 * All four ship and all four are called. `TokenCurrentActor` is read by the state processors and by
 * `JsonRpcServer` before it invokes a method; `SessionCurrentActor` by the web delivery;
 * `AssertedCurrentActor` by a console command given `--as`. This paragraph used to say that nothing
 * called any of them, which was the honest state when ADR 008 shipped the vocabulary ahead of its
 * callers and is why the container removed `Gate` as unused; that flip is the card's own proof it
 * is wired.
 */
interface CurrentActor
{
    public function actor(): Actor;
}
