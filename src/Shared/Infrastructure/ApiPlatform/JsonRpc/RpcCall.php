<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

/**
 * One Request object, validated as an envelope and nothing more.
 *
 * "Nothing more" is the line: this class knows §4's four members and has never heard of a member.
 * Whether `params` makes sense is {@see RpcMethod}'s question, asked after the method is known,
 * which is also why an unknown method is `-32601` and a bad parameter is `-32602` rather than both
 * collapsing into one answer.
 *
 * ## A notification is a *valid* request with no id
 *
 * The distinction costs one line here and decides real behaviour. §4.1 forbids replying to a
 * notification, but §6's own batch example answers `[1, 2, 3]` with three `-32600`s, none of which
 * had an id to be a notification with. So the envelope is checked first: what fails
 * {@see self::from()} is answered, with `id: null`, whether or not an id was there; only something
 * that passed and still has no `id` member is silent.
 *
 * That also rules out the reflection trick an earlier version used to tell "absent" from "null".
 * `array_key_exists()` on the decoded body is the honest test, because "absent" is a fact about the
 * JSON document and not about a PHP object built from it. `"id": null` is therefore a request that
 * gets a reply with `"id": null`, which §4 discourages and permits; a client that means
 * "notification" omits the member.
 */
final readonly class RpcCall
{
    private const string VERSION = '2.0';

    /**
     * @param array<array-key, mixed> $params
     */
    private function __construct(
        public string $method,
        public array $params,
        public bool $positional,
        public string|int|float|null $id,
        public bool $isNotification,
    ) {
    }

    /**
     * @param array<array-key, mixed> $member
     *
     * @throws RpcException with {@see ErrorCode::InvalidRequest} for anything §4 refuses
     */
    public static function from(array $member): self
    {
        if (self::VERSION !== ($member['jsonrpc'] ?? null)) {
            throw RpcException::of(
                ErrorCode::InvalidRequest,
                \sprintf('The "jsonrpc" member must be exactly "%s".', self::VERSION),
            );
        }

        $method = $member['method'] ?? null;

        if (!\is_string($method) || '' === $method) {
            throw RpcException::of(ErrorCode::InvalidRequest, 'The "method" member must be a non-empty string.');
        }

        $params = $member['params'] ?? [];

        // §4.2: "parameters ... MUST be provided as a Structured value. Either by-position through
        // an Array or by-name through an Object." A string or a number is neither, and §4's own
        // example of an invalid request is exactly that.
        if (!\is_array($params)) {
            throw RpcException::of(ErrorCode::InvalidRequest, 'The "params" member must be an array or an object.');
        }

        $hasId = \array_key_exists('id', $member);

        if ($hasId && !self::isUsableId($member['id'])) {
            throw RpcException::of(ErrorCode::InvalidRequest, 'The "id" member must be a string, a number or null.');
        }

        return new self(
            method: $method,
            params: $params,
            positional: [] !== $params && array_is_list($params),
            id: $hasId ? $member['id'] : null,
            isNotification: !$hasId,
        );
    }

    /**
     * The id to answer an *invalid* request with. §5 wants null "if there was an error in detecting
     * the id", so an id that is present and usable is still echoed: a caller who batched twenty
     * calls and mistyped one needs to know which.
     *
     * @param array<array-key, mixed> $member
     */
    public static function idOf(array $member): string|int|float|null
    {
        $id = $member['id'] ?? null;

        return self::isUsableId($id) ? $id : null;
    }

    /**
     * @param list<string> $names
     *
     * @throws RpcException
     */
    public function parameters(array $names): RpcParams
    {
        return RpcParams::of($this->params, $this->positional, $names);
    }

    /**
     * @phpstan-assert-if-true string|int|float|null $id
     */
    private static function isUsableId(mixed $id): bool
    {
        return null === $id || \is_string($id) || \is_int($id) || \is_float($id);
    }
}
