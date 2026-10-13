<?php
declare(strict_types=1);

namespace App\Domain\Model\ValueObjects;

use InvalidArgumentException;

/**
 * Value Object: GoogleId
 * 
 * Representa un Google OAuth ID (sub claim del JWT).
 * - Validación básica
 * - Inmutable
 * - Específico para User (autenticación OAuth)
 */
final class GoogleId
{
    private string $value;

    private function __construct(string $googleId)
    {
        $this->validate($googleId);
        $this->value = $googleId;
    }

    /**
     * Crea un GoogleId desde un string
     * 
     * @throws InvalidArgumentException si el ID no es válido
     */
    public static function fromString(string $googleId): self
    {
        return new self($googleId);
    }

    /**
     * Crea desde nullable
     */
    public static function fromNullableString(?string $googleId): ?self
    {
        return $googleId !== null ? new self($googleId) : null;
    }

    /**
     * Convierte a string
     */
    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Compara dos GoogleIds
     */
    public function equals(GoogleId $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Obtiene una versión truncada para logs (GDPR-friendly)
     */
    public function toMasked(): string
    {
        if (strlen($this->value) <= 8) {
            return '***';
        }
        
        return substr($this->value, 0, 4) . '***' . substr($this->value, -4);
    }

    /**
     * Valida el Google ID
     * 
     * @throws InvalidArgumentException
     */
    private function validate(string $googleId): void
    {
        if (empty(trim($googleId))) {
            throw new InvalidArgumentException('Google ID cannot be empty');
        }

        // Google IDs suelen ser strings numéricos de 21 dígitos
        // Pero pueden variar, así que validación flexible
        if (strlen($googleId) < 10 || strlen($googleId) > 255) {
            throw new InvalidArgumentException(
                'Google ID must be between 10 and 255 characters'
            );
        }

        // Validar que no contenga caracteres sospechosos
        if (preg_match('/[<>"\']/', $googleId)) {
            throw new InvalidArgumentException(
                'Google ID contains invalid characters'
            );
        }
    }
}
