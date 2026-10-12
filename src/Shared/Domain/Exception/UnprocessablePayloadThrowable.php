<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * When should I use this?
 *
 * The request was understood and its content is unacceptable, and *that is knowable from the
 * payload alone*. No store was consulted, no aggregate was loaded, nothing about the state of the
 * world was assumed. A handle that is three characters too long, an identifier that is not a
 * ULID, a like whose two members are the same person: each of those is a pure function of what the
 * caller sent.
 *
 * ADR 005 calls that purity, and it is the whole line the ADR draws. It is what makes these
 * failures *collectable*: independent facts about independent fields, so an edge can evaluate all
 * of them and answer with every one at once. Everything else fails alone.
 *
 * How it differs from its two neighbours, which is the part that is easy to get wrong:
 *
 * - {@see BadRequestThrowable} is "I could not work out what you asked". The payload never became
 *   a command, so there is nothing to have an opinion about. ADR 004's query parameters are the
 *   whole of it, and {@see \App\Shared\Application\ReadModel\BadFilterInput} the only implementor.
 * - {@see DomainUnprocessableThrowable} is "your payload was fine, the world is not in the state
 *   your request assumed". It needs the store, so it is never collectable, and never raised at an
 *   edge.
 *
 * Both of those answer with a single failure. This one answers with a list, which is why
 * {@see \App\Shared\Application\Validation\InvalidPayload} implements it too: the container and its
 * contents are the same kind of thing, so they get the same status without a second entry in
 * `exception_to_status`.
 *
 * This marker states which kind of failure occurred. What the API answers with is the delivery
 * adapter's decision, declared in `config/packages/api_platform.php` under `exception_to_status`.
 *
 * ## Where `code()` went
 *
 * It used to be declared here, and ADR 007 moved it up onto {@see TranslatableThrowable} with its
 * argument intact. Nothing about the contract changed: this interface still guarantees a code, so
 * {@see \App\Shared\Application\Validation\Violations} still reads one off everything it catches.
 *
 * What changed is that the guarantee stopped being special. The code is now also the key the
 * reader's language is found under, and every marker's exceptions produce text a caller reads, so
 * there was no longer a reason for this one marker to be the only one that promised a name.
 */
interface UnprocessablePayloadThrowable extends TranslatableThrowable
{
}
