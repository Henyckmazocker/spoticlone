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
