<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Model\User;

/**
 * Único sitio que decide quién es admin: ADMIN_EMAILS (lista separada por comas,
 * sin distinguir mayúsculas ni espacios). Vacía = nadie es admin.
 */
final class AdminPolicy
{
    public static function isAdmin(User $user): bool
    {
        $email = strtolower(trim($user->getEmail()->toString()));
        if ($email === '') {
            return false;
        }
        return in_array($email, self::adminEmails(), true);
    }

    /** @return string[] */
    private static function adminEmails(): array
    {
        $raw = $_ENV['ADMIN_EMAILS'] ?? getenv('ADMIN_EMAILS');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $list = array_map(static fn (string $e): string => strtolower(trim($e)), explode(',', $raw));
        return array_values(array_filter($list, static fn (string $e): bool => $e !== ''));
    }
}
