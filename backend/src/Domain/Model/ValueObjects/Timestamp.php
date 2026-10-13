<?php
declare(strict_types=1);

namespace App\Domain\Model\ValueObjects;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Value Object: Timestamp
 * 
 * Representa una marca de tiempo inmutable.
 * - Wrapper alrededor de DateTimeImmutable
 * - Conversión desde/hacia múltiples formatos
 * - Comparaciones de fechas
 * - Universal (usado en todas las entidades)
 */
final class Timestamp
{
    private DateTimeImmutable $dateTime;

    private function __construct(DateTimeImmutable $dateTime)
    {
        $this->dateTime = $dateTime;
    }

    /**
     * Crea un Timestamp con la fecha/hora actual
     */
    public static function now(): self
    {
        return new self(new DateTimeImmutable());
    }

    /**
     * Crea desde un objeto DateTime o DateTimeImmutable
     */
    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        if ($dateTime instanceof DateTimeImmutable) {
            return new self($dateTime);
        }
        
        return new self(DateTimeImmutable::createFromMutable($dateTime));
    }

    /**
     * Crea desde un string con formato
     */
    public static function fromString(string $date, string $format = 'Y-m-d H:i:s'): self
    {
        $dateTime = DateTimeImmutable::createFromFormat($format, $date);
        
        if ($dateTime === false) {
            throw new InvalidArgumentException(
                "Invalid date string: '{$date}' with format '{$format}'"
            );
        }
        
        return new self($dateTime);
    }

    /**
     * Crea desde nullable
     */
    public static function fromNullableString(?string $date, string $format = 'Y-m-d H:i:s'): ?self
    {
        return $date !== null ? self::fromString($date, $format) : null;
    }

    /**
     * Crea desde timestamp Unix (segundos desde epoch)
     */
    public static function fromUnixTimestamp(int $timestamp): self
    {
        $dateTime = (new DateTimeImmutable())->setTimestamp($timestamp);
        return new self($dateTime);
    }

    /**
     * Convierte a DateTimeImmutable
     */
    public function toDateTime(): DateTimeImmutable
    {
        return $this->dateTime;
    }

    /**
     * Convierte a string con formato
     */
    public function toString(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->dateTime->format($format);
    }

    /**
     * Convierte a formato ISO 8601
     */
    public function toIso8601(): string
    {
        return $this->dateTime->format(DateTimeInterface::ATOM);
    }

    /**
     * Convierte a timestamp Unix
     */
    public function toUnixTimestamp(): int
    {
        return $this->dateTime->getTimestamp();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Compara si dos timestamps son iguales
     */
    public function equals(Timestamp $other): bool
    {
        return $this->dateTime == $other->dateTime;
    }

    /**
     * Verifica si es antes que otro timestamp
     */
    public function isBefore(Timestamp $other): bool
    {
        return $this->dateTime < $other->dateTime;
    }

    /**
     * Verifica si es después que otro timestamp
     */
    public function isAfter(Timestamp $other): bool
    {
        return $this->dateTime > $other->dateTime;
    }

    /**
     * Verifica si es hoy
     */
    public function isToday(): bool
    {
        $now = new DateTimeImmutable();
        return $this->dateTime->format('Y-m-d') === $now->format('Y-m-d');
    }

    /**
     * Verifica si es en el pasado
     */
    public function isPast(): bool
    {
        return $this->dateTime < new DateTimeImmutable();
    }

    /**
     * Verifica si es en el futuro
     */
    public function isFuture(): bool
    {
        return $this->dateTime > new DateTimeImmutable();
    }

    /**
     * Añade días al timestamp (retorna nuevo Timestamp)
     */
    public function addDays(int $days): self
    {
        $newDateTime = $this->dateTime->modify("+{$days} days");
        return new self($newDateTime);
    }

    /**
     * Resta días al timestamp (retorna nuevo Timestamp)
     */
    public function subDays(int $days): self
    {
        $newDateTime = $this->dateTime->modify("-{$days} days");
        return new self($newDateTime);
    }

    /**
     * Diferencia en días con otro timestamp
     */
    public function diffInDays(Timestamp $other): int
    {
        $diff = $this->dateTime->diff($other->dateTime);
        return (int)$diff->format('%a');
    }

    /**
     * Formato para humanos (hace X tiempo)
     */
    public function toHumanReadable(): string
    {
        $now = new DateTimeImmutable();
        $diff = $now->diff($this->dateTime);

        if ($diff->y > 0) {
            return $diff->y === 1 ? '1 year ago' : "{$diff->y} years ago";
        }
        
        if ($diff->m > 0) {
            return $diff->m === 1 ? '1 month ago' : "{$diff->m} months ago";
        }
        
        if ($diff->d > 0) {
            return $diff->d === 1 ? '1 day ago' : "{$diff->d} days ago";
        }
        
        if ($diff->h > 0) {
            return $diff->h === 1 ? '1 hour ago' : "{$diff->h} hours ago";
        }
        
        if ($diff->i > 0) {
            return $diff->i === 1 ? '1 minute ago' : "{$diff->i} minutes ago";
        }
        
        return 'just now';
    }
}
