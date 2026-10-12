<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

/**
 * A failure of the protocol itself: the envelope did not parse, the method is not exposed, the
 * parameters could not be laid out. It carries a finished {@see RpcError}, so
 * {@see JsonRpcServer} has nothing to decide when it catches one.
 *
 * Everything a *handler* raises is a domain failure and arrives as one of the marker interfaces in
 * `Shared/Domain/Exception/`; {@see RpcErrorFactory} is where those are translated. Nothing in the
 * `Membership` or `Matching` namespaces knows this class exists, and nothing should: the protocol is
 * a delivery, and ADR 001 keeps deliveries out of the contexts.
 */
final class RpcException extends \RuntimeException
{
    private function __construct(private readonly RpcError $error)
    {
        parent::__construct($error->message);
    }

    public static function of(ErrorCode $code, ?string $message = null, mixed $data = null): self
    {
        return new self(RpcError::of($code, $message, $data));
    }

    public function error(): RpcError
    {
        return $this->error;
    }
}
