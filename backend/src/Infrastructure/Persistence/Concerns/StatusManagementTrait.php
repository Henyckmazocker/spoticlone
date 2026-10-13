<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Concerns;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Trait: StatusManagementTrait
 * 
 * Proporciona métodos unificados para gestión de estados (statuses).
 * Elimina duplicación en MySqlBookRepository y MySqlMovieRepository.
 * 
 * Requisitos de la clase que use este trait:
 * - Debe tener una propiedad PDO $db
 * - Debe implementar los métodos abstractos para configuración
 * 
 * Uso:
 * ```php
 * class MySqlBookRepository implements BookRepositoryInterface {
 *     use StatusManagementTrait;
 *     
 *     protected function getStatusTableName(): string {
 *         return 'book_statuses';
 *     }
 *     
 *     protected function getEntityStatusTableName(): string {
 *         return 'book_has_statuses';
 *     }
 *     
 *     protected function getEntityIdColumnName(): string {
 *         return 'book_isbn';
 *     }
 * }
 * ```
 */
trait StatusManagementTrait
{
    /**
     * Obtiene la conexión PDO
     * La clase que use el trait debe tener esta propiedad
     */
    abstract protected function getDatabase(): PDO;

    /**
     * Nombre de la tabla de estados (ej: 'book_statuses', 'movie_statuses')
     */
    abstract protected function getStatusTableName(): string;

    /**
     * Nombre de la tabla de relación entidad-estado 
     * (ej: 'book_has_statuses', 'movie_has_statuses')
     */
    abstract protected function getEntityStatusTableName(): string;

    /**
     * Nombre de la columna de ID de entidad en la tabla de relación
     * (ej: 'book_isbn', 'movie_isbn')
     */
    abstract protected function getEntityIdColumnName(): string;

    /**
     * Obtiene el ID de un estado por su nombre
     * 
     * @param string $statusName Nombre del estado (ej: 'read', 'reading', 'to-read')
     * @return int|null ID del estado o null si no existe
     */
    protected function getStatusId(string $statusName): ?int
    {
        try {
            $table = $this->getStatusTableName();
            $db = $this->getDatabase();
            
            $stmt = $db->prepare("SELECT id FROM {$table} WHERE name = :name");
            $stmt->execute(['name' => $statusName]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? (int)$result['id'] : null;
            
        } catch (PDOException $e) {
            // Si el trait LoggableTrait está disponible, usarlo
            if (method_exists($this, 'logError')) {
                $this->logError("Error getting status ID for '{$statusName}'", $e, [
                    'status_name' => $statusName,
                    'table' => $this->getStatusTableName()
                ]);
            }
            
            throw new RuntimeException(
                "Failed to get status ID for '{$statusName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Obtiene todos los nombres de estados de una entidad
     * 
     * @param string|int $entityId ID de la entidad (ISBN, movie ID, etc.)
     * @return array<int, string> Array de nombres de estados
     */
    protected function fetchStatusNames(string|int $entityId): array
    {
        try {
            $statusTable = $this->getStatusTableName();
            $entityStatusTable = $this->getEntityStatusTableName();
            $entityIdColumn = $this->getEntityIdColumnName();
            $db = $this->getDatabase();
            
            $sql = "SELECT s.name 
                    FROM {$statusTable} s
                    JOIN {$entityStatusTable} es ON s.id = es.status_id
                    WHERE es.{$entityIdColumn} = :entityId
                    ORDER BY s.name";
            
            $stmt = $db->prepare($sql);
            $stmt->execute(['entityId' => $entityId]);
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            
        } catch (PDOException $e) {
            if (method_exists($this, 'logError')) {
                $this->logError("Error fetching status names", $e, [
                    'entity_id' => $entityId,
                    'status_table' => $this->getStatusTableName(),
                    'entity_status_table' => $this->getEntityStatusTableName()
                ]);
            }
            
            throw new RuntimeException(
                "Failed to fetch status names for entity '{$entityId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Obtiene todos los estados permitidos
     * 
     * @return array<int, string> Array de nombres de estados
     */
    protected function getAllowedStatusesFromDb(): array
    {
        try {
            $table = $this->getStatusTableName();
            $db = $this->getDatabase();
            
            $stmt = $db->query("SELECT name FROM {$table} ORDER BY name");
            $result = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            return is_array($result) ? $result : [];
            
        } catch (PDOException $e) {
            if (method_exists($this, 'logError')) {
                $this->logError("Error fetching allowed statuses", $e, [
                    'table' => $this->getStatusTableName()
                ]);
            }
            
            // Retornar array vacío en caso de error en lugar de lanzar excepción
            // (comportamiento más resiliente)
            return [];
        }
    }

    /**
     * Asigna un estado a una entidad
     * 
     * @param string|int $entityId ID de la entidad
     * @param string $statusName Nombre del estado
     * @throws RuntimeException si falla la operación
     */
    protected function assignStatus(string|int $entityId, string $statusName): void
    {
        try {
            $statusId = $this->getStatusId($statusName);
            
            if ($statusId === null) {
                throw new RuntimeException("Status '{$statusName}' does not exist");
            }
            
            $entityStatusTable = $this->getEntityStatusTableName();
            $entityIdColumn = $this->getEntityIdColumnName();
            $db = $this->getDatabase();
            
            $sql = "INSERT IGNORE INTO {$entityStatusTable} ({$entityIdColumn}, status_id) 
                    VALUES (:entityId, :statusId)";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'entityId' => $entityId,
                'statusId' => $statusId
            ]);
            
            if (method_exists($this, 'logDebug')) {
                $this->logDebug("Status assigned", [
                    'entity_id' => $entityId,
                    'status_name' => $statusName,
                    'status_id' => $statusId
                ]);
            }
            
        } catch (PDOException $e) {
            if (method_exists($this, 'logError')) {
                $this->logError("Error assigning status", $e, [
                    'entity_id' => $entityId,
                    'status_name' => $statusName
                ]);
            }
            
            throw new RuntimeException(
                "Failed to assign status '{$statusName}' to entity '{$entityId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Elimina todos los estados de una entidad
     * 
     * @param string|int $entityId ID de la entidad
     */
    protected function clearAllStatuses(string|int $entityId): void
    {
        try {
            $entityStatusTable = $this->getEntityStatusTableName();
            $entityIdColumn = $this->getEntityIdColumnName();
            $db = $this->getDatabase();
            
            $sql = "DELETE FROM {$entityStatusTable} WHERE {$entityIdColumn} = :entityId";
            
            $stmt = $db->prepare($sql);
            $stmt->execute(['entityId' => $entityId]);
            
            if (method_exists($this, 'logDebug')) {
                $this->logDebug("All statuses cleared", [
                    'entity_id' => $entityId,
                    'rows_affected' => $stmt->rowCount()
                ]);
            }
            
        } catch (PDOException $e) {
            if (method_exists($this, 'logError')) {
                $this->logError("Error clearing statuses", $e, [
                    'entity_id' => $entityId
                ]);
            }
            
            throw new RuntimeException(
                "Failed to clear statuses for entity '{$entityId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Reemplaza todos los estados de una entidad
     * 
     * @param string|int $entityId ID de la entidad
     * @param array<int, string> $statusNames Array de nombres de estados
     */
    protected function replaceStatuses(string|int $entityId, array $statusNames): void
    {
        $db = $this->getDatabase();
        
        try {
            $db->beginTransaction();
            
            // Limpiar estados actuales
            $this->clearAllStatuses($entityId);
            
            // Asignar nuevos estados
            foreach ($statusNames as $statusName) {
                $this->assignStatus($entityId, $statusName);
            }
            
            $db->commit();
            
            if (method_exists($this, 'logInfo')) {
                $this->logInfo("Statuses replaced", [
                    'entity_id' => $entityId,
                    'new_statuses' => $statusNames
                ]);
            }
            
        } catch (\Exception $e) {
            $db->rollBack();
            
            if (method_exists($this, 'logError')) {
                $this->logError("Error replacing statuses", $e, [
                    'entity_id' => $entityId,
                    'statuses' => $statusNames
                ]);
            }
            
            throw new RuntimeException(
                "Failed to replace statuses for entity '{$entityId}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
