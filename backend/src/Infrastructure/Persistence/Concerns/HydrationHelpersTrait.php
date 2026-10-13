<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Concerns;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Trait: HydrationHelpersTrait
 * 
 * Proporciona métodos auxiliares para hidratar entidades desde datos de base de datos.
 * Maneja conversiones de tipos, valores nullables y parseo de JSON.
 * 
 * Uso:
 * ```php
 * class BookDataMapper {
 *     use HydrationHelpersTrait;
 *     
 *     public function toDomain(array $dbRow): Book {
 *         return new Book(
 *             isbn: $this->extractString($dbRow, 'isbn'),
 *             pages: $this->extractInt($dbRow, 'pages'),
 *             rating: $this->extractFloat($dbRow, 'rating'),
 *             isActive: $this->extractBool($dbRow, 'is_active', true)
 *         );
 *     }
 * }
 * ```
 */
trait HydrationHelpersTrait
{
    /**
     * Extrae un entero de un array, con valor por defecto
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a extraer
     * @param int|null $default Valor por defecto si no existe o es null
     * @return int|null
     */
    protected function extractInt(array $data, string $key, ?int $default = null): ?int
    {
        if (!isset($data[$key])) {
            return $default;
        }
        
        $value = $data[$key];
        
        if ($value === null) {
            return $default;
        }
        
        return (int)$value;
    }

    /**
     * Extrae un entero no nullable (lanza excepción si falta)
     * 
     * @throws InvalidArgumentException si el campo no existe o es null
     */
    protected function extractRequiredInt(array $data, string $key): int
    {
        if (!isset($data[$key]) || $data[$key] === null) {
            throw new InvalidArgumentException("Required field '{$key}' is missing or null");
        }
        
        return (int)$data[$key];
    }

    /**
     * Extrae un float de un array, con valor por defecto
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a extraer
     * @param float|null $default Valor por defecto
     * @return float|null
     */
    protected function extractFloat(array $data, string $key, ?float $default = null): ?float
    {
        if (!isset($data[$key])) {
            return $default;
        }
        
        $value = $data[$key];
        
        if ($value === null) {
            return $default;
        }
        
        return (float)$value;
    }

    /**
     * Extrae un float no nullable
     * 
     * @throws InvalidArgumentException si el campo no existe o es null
     */
    protected function extractRequiredFloat(array $data, string $key): float
    {
        if (!isset($data[$key]) || $data[$key] === null) {
            throw new InvalidArgumentException("Required field '{$key}' is missing or null");
        }
        
        return (float)$data[$key];
    }

    /**
     * Extrae un string de un array, con valor por defecto
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a extraer
     * @param string|null $default Valor por defecto
     * @return string|null
     */
    protected function extractString(array $data, string $key, ?string $default = null): ?string
    {
        if (!isset($data[$key])) {
            return $default;
        }
        
        $value = $data[$key];
        
        if ($value === null) {
            return $default;
        }
        
        return (string)$value;
    }

    /**
     * Extrae un string no nullable
     * 
     * @throws InvalidArgumentException si el campo no existe o es null
     */
    protected function extractRequiredString(array $data, string $key): string
    {
        if (!isset($data[$key]) || $data[$key] === null) {
            throw new InvalidArgumentException("Required field '{$key}' is missing or null");
        }
        
        return (string)$data[$key];
    }

    /**
     * Extrae un booleano de un array
     * Maneja conversiones desde int (0/1), string ('true'/'false', '1'/'0')
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a extraer
     * @param bool $default Valor por defecto
     * @return bool
     */
    protected function extractBool(array $data, string $key, bool $default = false): bool
    {
        if (!isset($data[$key])) {
            return $default;
        }
        
        $value = $data[$key];
        
        if ($value === null) {
            return $default;
        }
        
        // Conversión flexible
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_int($value)) {
            return $value !== 0;
        }
        
        if (is_string($value)) {
            $lower = strtolower($value);
            return in_array($lower, ['true', '1', 'yes', 'on'], true);
        }
        
        return (bool)$value;
    }

    /**
     * Extrae y decodifica un JSON
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a extraer
     * @param array<mixed> $default Array por defecto si no existe o falla el parseo
     * @return array<mixed>
     */
    protected function extractJson(array $data, string $key, array $default = []): array
    {
        if (!isset($data[$key])) {
            return $default;
        }
        
        $value = $data[$key];
        
        if ($value === null || $value === '') {
            return $default;
        }
        
        // Si ya es un array, retornarlo
        if (is_array($value)) {
            return $value;
        }
        
        // Intentar decodificar JSON
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        
        return $default;
    }

    /**
     * Extrae una fecha/hora
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a extraer
     * @param string $format Formato de fecha esperado
     * @return DateTime|null
     */
    protected function extractDateTime(array $data, string $key, string $format = 'Y-m-d H:i:s'): ?DateTime
    {
        if (!isset($data[$key])) {
            return null;
        }
        
        $value = $data[$key];
        
        if ($value === null || $value === '') {
            return null;
        }
        
        // Si ya es un DateTime, retornarlo
        if ($value instanceof DateTime) {
            return $value;
        }
        
        if ($value instanceof DateTimeImmutable) {
            return DateTime::createFromImmutable($value);
        }
        
        // Intentar parsear string
        if (is_string($value)) {
            $dateTime = DateTime::createFromFormat($format, $value);
            
            if ($dateTime !== false) {
                return $dateTime;
            }
            
            // Intentar parseo genérico
            try {
                return new DateTime($value);
            } catch (\Exception $e) {
                // Falló el parseo, retornar null
                return null;
            }
        }
        
        return null;
    }

    /**
     * Extrae una fecha/hora inmutable
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a extraer
     * @param string $format Formato de fecha esperado
     * @return DateTimeImmutable|null
     */
    protected function extractDateTimeImmutable(
        array $data, 
        string $key, 
        string $format = 'Y-m-d H:i:s'
    ): ?DateTimeImmutable {
        $dateTime = $this->extractDateTime($data, $key, $format);
        
        if ($dateTime === null) {
            return null;
        }
        
        return DateTimeImmutable::createFromMutable($dateTime);
    }

    /**
     * Extrae un array de strings desde una columna separada por comas
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a extraer
     * @param string $separator Separador (por defecto coma)
     * @return array<int, string>
     */
    protected function extractStringArray(
        array $data, 
        string $key, 
        string $separator = ','
    ): array {
        $value = $this->extractString($data, $key);
        
        if ($value === null || $value === '') {
            return [];
        }
        
        $parts = explode($separator, $value);
        
        return array_map('trim', array_filter($parts, fn($v) => $v !== ''));
    }

    /**
     * Verifica si un campo existe y no es null
     * 
     * @param array<string, mixed> $data Array de datos
     * @param string $key Clave a verificar
     * @return bool
     */
    protected function hasValue(array $data, string $key): bool
    {
        return isset($data[$key]) && $data[$key] !== null;
    }

    /**
     * Extrae múltiples campos en un array asociativo
     * Útil para extraer un subconjunto de datos
     * 
     * @param array<string, mixed> $data Array de datos fuente
     * @param array<int, string> $keys Array de claves a extraer
     * @return array<string, mixed>
     */
    protected function extractFields(array $data, array $keys): array
    {
        $result = [];
        
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $result[$key] = $data[$key];
            }
        }
        
        return $result;
    }

    /**
     * Convierte un valor a formato de base de datos
     * null → NULL, bool → 0/1, arrays → JSON
     * 
     * @param mixed $value Valor a convertir
     * @return mixed
     */
    protected function toDbValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        
        if (is_array($value)) {
            return json_encode($value);
        }
        
        if ($value instanceof DateTime || $value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s');
        }
        
        return $value;
    }

    /**
     * Convierte múltiples valores para base de datos
     * 
     * @param array<string, mixed> $data Array de datos
     * @return array<string, mixed>
     */
    protected function toDbValues(array $data): array
    {
        $result = [];
        
        foreach ($data as $key => $value) {
            $result[$key] = $this->toDbValue($value);
        }
        
        return $result;
    }

    /**
     * Construye el objeto ownershipFormat a partir de columnas JOIN de item_owned_formats.
     * Espera las columnas: ownership_format_id, ownership_format_value, ownership_format_label.
     * Devuelve null si no hay formato asignado.
     *
     * @param array<string, mixed> $row Fila de BD con columnas de JOIN
     * @return array{id: int, value: string, label: string}|null
     */
    protected function buildOwnershipFormat(array $row): ?array
    {
        $fmtId = isset($row['ownership_format_id']) && $row['ownership_format_id'] !== null
            ? (int) $row['ownership_format_id']
            : null;

        if ($fmtId === null) {
            return null;
        }

        return [
            'id'    => $fmtId,
            'value' => (string) ($row['ownership_format_value'] ?? ''),
            'label' => (string) ($row['ownership_format_label'] ?? ''),
        ];
    }
}
