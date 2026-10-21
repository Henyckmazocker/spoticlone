<?php

declare(strict_types=1);

namespace App\Infrastructure\Middleware;

use App\Domain\Model\User;
use App\Domain\Services\AdminPolicy;

/**
 * Restringe una acción al administrador. Debe ir justo después de
 * AuthenticationMiddleware, que deja el usuario cargado en $request['user'].
 */
final class AdminMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): array
    {
        $user = $request['user'] ?? null;
        if (!$user instanceof User || !AdminPolicy::isAdmin($user)) {
            return ['status' => 'error', 'message' => 'Acción reservada al administrador.', 'http_code' => 403];
        }
        return $next($request);
    }
}
