<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

/**
 * The `params` member, after by-position calls have been turned into by-name ones.
 *
 * §4.2 allows both shapes and a server that only accepts one of them is not conformant, so the
 * positional list is folded onto the names {@see RpcMethod::parameters()} declares, in order. That
 * declaration is the only place the order exists, which means it is also the only place that can
 * break a client by changing, and the generated document publishes it.
 *
 * ## Why the accessors are this blunt
 *
 * `string()` answers `''` for a parameter that is absent or is not a scalar, and never throws. That
 * looks careless next to the rest of this codebase and is ADR 005 working exactly as intended: a
 * missing `memberId` and a malformed one are the same fact from the caller's side, and both should
 * come back as one violation naming `memberId`. Making the accessor throw would report the first
 * missing field and hide the others, which is the round trip per field ADR 005 exists to avoid.
 *
 * So nothing here decides anything. `''` reaches `Id::fromString('')`, that refuses, and
 * {@see \App\Shared\Application\Validation\Violations} collects the refusal under the pointer the
 * method named. The domain stays the authority over what a parameter may be, and this class stays
 * a bag of strings.
 */
final readonly class RpcParams
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private array $values)
    {
    }

    /**
     * @param array<array-key, mixed> $raw   the `params` member as it was decoded
     * @param list<string>            $names the declared parameter names, in positional order
     *
     * @throws RpcException when a by-position call carries more values than the method declares
     */
    public static function of(array $raw, bool $positional, array $names): self
    {
        if (!$positional) {
            /** @var array<string, mixed> $raw */
            return new self($raw);
        }

        if (\count($raw) > \count($names)) {
            throw RpcException::of(
                ErrorCode::InvalidParams,
                \sprintf(
                    'This method takes %d positional parameter(s) and %d were given.',
                    \count($names),
                    \count($raw),
                ),
                ['parameters' => $names],
            );
        }

        return new self(array_combine(\array_slice($names, 0, \count($raw)), array_values($raw)));
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    /**
     * Every parse in this codebase starts from a string, so this is what a method calls. A value
     * that is not a scalar (an object, an array, `null`) becomes `''` rather than a `TypeError`;
     * see the class docblock for why that is the answer and not a shortcut.
     */
    public function string(string $name): string
    {
        $value = $this->values[$name] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
