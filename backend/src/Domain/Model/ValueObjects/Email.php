<?php
declare(strict_types=1);

namespace App\Domain\Model\ValueObjects;

use InvalidArgumentException;

/**
 * Value Object: Email
 * 
 * Representa una dirección de correo electrónico.
 * - Validación con filter_var
 * - Normalización (lowercase)
 * - Inmutable
 * - Específico para User
 */
final class Email
{
    private string $value;

    private function __construct(string $email)
    {
        $normalized = $this->normalize($email);
        $this->validate($normalized);
        $this->value = $normalized;
    }

    /**
     * Crea un Email desde un string
     * 
     * @throws InvalidArgumentException si el email no es válido
     */
    public static function fromString(string $email): self
    {
        return new self($email);
    }

    /**
     * Crea desde nullable
     */
    public static function fromNullableString(?string $email): ?self
    {
        return $email !== null ? new self($email) : null;
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
     * Compara dos emails
     */
    public function equals(Email $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Obtiene el dominio del email
     */
    public function getDomain(): string
    {
        $parts = explode('@', $this->value);
        return $parts[1] ?? '';
    }

    /**
     * Obtiene la parte local (antes de @)
     */
    public function getLocalPart(): string
    {
        $parts = explode('@', $this->value);
        return $parts[0] ?? '';
    }

    /**
     * Verifica si es un dominio específico
     */
    public function isDomain(string $domain): bool
    {
        return strcasecmp($this->getDomain(), $domain) === 0;
    }

    /**
     * Verifica si es un email de Gmail
     */
    public function isGmail(): bool
    {
        return $this->isDomain('gmail.com');
    }

    /**
     * Normaliza el email (lowercase)
     */
    private function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Valida el email
     * 
     * @throws InvalidArgumentException
     */
    private function validate(string $email): void
    {
        if (empty($email)) {
            throw new InvalidArgumentException('Email cannot be empty');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(
                "Invalid email format: {$email}"
            );
        }

        // Validación adicional de longitud
        if (strlen($email) > 255) {
            throw new InvalidArgumentException(
                'Email is too long: maximum 255 characters'
            );
        }
    }
}
