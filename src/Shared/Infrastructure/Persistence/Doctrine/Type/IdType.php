<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine\Type;

use App\Shared\Domain\Models\Id;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Webmozart\Assert\Assert;

final class IdType extends Type
{
    public const string NAME = 'Id';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if ($this->hasNativeGuidType($platform)) {
            return $platform->getGuidTypeDeclarationSQL($column);
        }

        return $platform->getBinaryTypeDeclarationSQL([
            'length' => 16,
            'fixed' => true,
        ]);
    }

    protected function getUidClass(): string
    {
        return Id::class;
    }

    /**
     * `Id<object>` and not `Id<A>`, because a Doctrine type is handed a column and has no idea what
     * the row is a row of. The phantom parameter is recovered at the property it is assigned to,
     * which is the whole bargain ADR 002 strikes: the type is real at the seams that matter and
     * unknowable here.
     *
     * @return Id<object>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Id
    {
        if ($value instanceof Id || null === $value) {
            return $value;
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string', Id::class]);
        }

        try {
            return Id::fromString($value);
        } catch (\InvalidArgumentException $e) {
            throw ConversionException::conversionFailed($value, $this->getName(), $e);
        }
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        // A nullable Id column is a perfectly ordinary thing (membership_subscription.referred_by),
        // and the return type already said so; the assertion below did not.
        if (null === $value) {
            return null;
        }

        // In principle, $value should arrive here as an Id instance.
        // Although ecotone transports it normalized and does not process it back when
        // loading an aggregate with the metadata aggregate.id.
        if (is_string($value) && Id::isValid($value)) {
            $value = Id::fromString($value);
        }

        Assert::isInstanceOf($value, Id::class);

        $toString = $this->hasNativeGuidType($platform) ? 'toRfc4122' : 'toBinary';

        return $value->$toString();
    }

    private function hasNativeGuidType(AbstractPlatform $platform): bool
    {
        return $platform->getGuidTypeDeclarationSQL([]) !== $platform->getStringTypeDeclarationSQL(['fixed' => true, 'length' => 36]);
    }
}
