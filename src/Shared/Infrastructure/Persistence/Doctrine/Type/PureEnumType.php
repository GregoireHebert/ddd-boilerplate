<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine maps a *backed* enum for free through `enum-type`. A pure enum it cannot, because there
 * is nothing to write in the column.
 *
 * Which leaves a choice, and it is the path-1-versus-path-2 choice in miniature: give the enum a
 * backing value so storage concerns reach into the domain declaration, or leave the enum as the
 * domain wants it and pay for a type. This class is the second answer, made cheap enough that
 * paying for it is not an argument against it.
 *
 * The case *name* is the stored value. Renaming a case is then a data migration, which is fair:
 * a pure enum has no other stable identity to key on.
 *
 * @see \App\Matching\Domain\Model\BlockReason for the other answer
 */
abstract class PureEnumType extends Type
{
    /**
     * @return class-string<\UnitEnum>
     */
    abstract protected function enumClass(): string;

    #[\Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => 32]);
    }

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        $enumClass = $this->enumClass();

        if (!$value instanceof $enumClass) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', $enumClass]);
        }

        return $value->name;
    }

    #[\Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\UnitEnum
    {
        if (null === $value || $value instanceof \UnitEnum) {
            return $value;
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string']);
        }

        foreach ($this->enumClass()::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw ConversionException::conversionFailed($value, $this->getName());
    }
}
