<?php
declare(strict_types=1);

namespace App\Domain\Repository\User;

use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;

/**
 * Interface: UserRepositoryInterface (refactorizado)
 * 
 * Repositorio especializado solo en gestión de usuarios.
 * Responsabilidades de relaciones User-Book y User-Movie movidas
 * a UserBookRepositoryInterface y UserMovieRepositoryInterface.
 */
interface UserRepositoryInterface
{
    /**
     * Busca un usuario por su Google ID
     */
    public function findByGoogleId(GoogleId $googleId): ?User;

    /**
     * Busca un usuario por su ID numérico
     */
    public function findById(int $id): ?User;

    /**
     * Busca un usuario por su email
     */
    public function findByEmail(Email $email): ?User;

    /**
     * Número total de usuarios registrados (para el tope MAX_USERS)
     */
    public function count(): int;

    /**
     * Ejecuta $fn bajo un lock global de registro (serializa count()+save() del tope MAX_USERS).
     * Si no se obtiene el lock lanza RuntimeException en vez de registrar sin él.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withRegistrationLock(callable $fn): mixed;

    /**
     * Guarda un nuevo usuario
     */
    public function save(User $user): User;

    /**
     * Actualiza un usuario existente
     */
    public function update(User $user): User;

    /**
     * Elimina un usuario (soft delete)
     */
    public function delete(int $id): void;

    /**
     * Incrementa token_version: invalida todos los JWT y sesiones emitidos antes
     */
    public function incrementTokenVersion(int $id): void;

    /**
     * Busca un usuario por su username único
     */
    public function findByUsername(string $username): ?User;

    /**
     * Busca usuarios cuyo username contiene el término dado (LIKE)
     * Excluye al usuario actual de los resultados
     *
     * @return User[]
     */
    public function searchByUsername(string $term, int $excludeUserId, int $limit = 10): array;
}
