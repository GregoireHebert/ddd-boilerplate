<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

/**
 * The `error` member of a Response, and exactly the three fields §5.1 defines.
 *
 * It does not carry an `id`. The correlation token belongs to the Response envelope
 * ({@see RpcResponse}), and duplicating it here would publish a member the specification does not
 * define, which is the kind of thing a strict client rejects.
 *
 * `jsonSerialize()` omits `data` when there is none, because §5.1 says the member "may be omitted"
 * and the global `skip_null_values: false` in `config/packages/api_platform.php` would otherwise
 * emit `"data": null` on every error. That setting is right for the REST resources and wrong for
 * this protocol, which is the first of several reasons the RPC surface serialises itself rather
 * than going through API Platform's normalizers.
 */
final readonly class RpcError implements \JsonSerializable
{
    private function __construct(
        public ErrorCode $code,
        public string $message,
        public mixed $data,
    ) {
    }

    public static function of(ErrorCode $code, ?string $message = null, mixed $data = null): self
    {
        return new self($code, $message ?? $code->message(), $data);
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $error = [
            'code' => $this->code->value,
            'message' => $this->message,
        ];

        if (null !== $this->data) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
