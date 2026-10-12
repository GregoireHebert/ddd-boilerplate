<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

/**
 * What goes back over the wire, once, holding the two rules §6 attaches to batches that a plain
 * `list<RpcResponse>` cannot express.
 *
 * **A batch answers with an array, a single call with an object.** One call is not a batch of one,
 * and a client that sent `{...}` and received `[{...}]` has to special-case us.
 *
 * **Nothing at all is a legitimate answer.** "If there are no Response objects contained within the
 * Response array as it is to be sent to the client, the server MUST NOT return an empty Array and
 * should return nothing at all." A batch of notifications, or a single notification, produces
 * {@see self::silent()}, which {@see \App\Ui\Api\StateProcessor\JsonRpcProcessor} turns into
 * `204 No Content`.
 */
final readonly class RpcReply implements \JsonSerializable
{
    /**
     * @param list<RpcResponse> $responses
     */
    private function __construct(
        private array $responses,
        private bool $batch,
    ) {
    }

    public static function single(RpcResponse $response): self
    {
        return new self([$response], false);
    }

    /**
     * @param list<RpcResponse> $responses
     */
    public static function batch(array $responses): self
    {
        return new self($responses, true);
    }

    public static function silent(): self
    {
        return new self([], false);
    }

    public function isEmpty(): bool
    {
        return [] === $this->responses;
    }

    /**
     * @return list<RpcResponse>|RpcResponse
     */
    #[\Override]
    public function jsonSerialize(): array|RpcResponse
    {
        return $this->batch
            ? $this->responses
            : $this->responses[0] ?? throw new \LogicException('A silent reply has no body; ask isEmpty() first.');
    }
}
