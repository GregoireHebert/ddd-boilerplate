<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

/**
 * One Response object.
 *
 * > Either the result member or error member MUST be included, but both members MUST NOT be
 * > included.
 *
 * That sentence (§5) is why there are two named constructors and no public one, and why this class
 * serialises itself. A single class holding both members as properties cannot express "absent",
 * only "null", and a client written against the specification branches on whether `error` is
 * *present*.
 *
 * `result` is emitted even when it is `null`, and that is not the same mistake: a method that
 * returns nothing still succeeded, and §5 requires the member on success. `null` is the result.
 *
 * `id` is `string|int|float|null` and is echoed byte for byte from the Request. It is deliberately
 * not an {@see \App\Shared\Domain\Models\Id}: §4 lets the client choose "a String, Number, or NULL
 * value", the token means nothing to this application beyond correlating a batch, and re-rendering
 * it into any canonical form would break the one rule the member has, which is that it comes back
 * identical.
 */
final readonly class RpcResponse implements \JsonSerializable
{
    private const string VERSION = '2.0';

    private function __construct(
        private mixed $result,
        private ?RpcError $error,
        private string|int|float|null $id,
    ) {
    }

    public static function success(mixed $result, string|int|float|null $id): self
    {
        return new self($result, null, $id);
    }

    /**
     * `id` is null when the request could not be read far enough to find one, which §5 requires,
     * and is the caller's own token whenever it could be.
     */
    public static function failure(RpcError $error, string|int|float|null $id = null): self
    {
        return new self(null, $error, $id);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'jsonrpc' => self::VERSION,
            ...(null === $this->error ? ['result' => $this->result] : ['error' => $this->error]),
            'id' => $this->id,
        ];
    }
}
