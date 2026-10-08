<?php

declare(strict_types=1);

namespace App\Http;

use App\Models\User;
use RuntimeException;

/**
 * Contexto de autenticação e autorização da requisição atual.
 *
 * Ciclo de vida por request:
 *   1. AuthMiddleware chama set() + setRbac() — uma única vez, após autenticar.
 *   2. RoleMiddleware e PermissionMiddleware consultam hasRole() / hasPermission().
 *   3. Services podem chamar userId() para associar dados ao usuário logado.
 *
 * Thread-safety: PHP-FPM isola cada request em um processo dedicado.
 * Propriedades estáticas reiniciam a cada novo processo — sem vazamento entre requests.
 */
final class AuthContext
{
    private static ?User  $user           = null;
    private static ?int   $organizationId = null;
    /** @var string[] */
    private static array  $roles          = [];
    /** @var string[] */
    private static array  $permissions    = [];

    private function __construct() {}

    // ─── Setters (uso exclusivo do AuthMiddleware) ────────────────────────────

    public static function set(User $user): void
    {
        self::$user = $user;
    }

    public static function setOrganization(?int $organizationId): void
    {
        self::$organizationId = $organizationId;
    }

    public static function organizationId(): ?int
    {
        return self::$organizationId;
    }

    /**
     * @param string[] $roles       Slugs das roles do usuário  ex: ['admin', 'agent']
     * @param string[] $permissions Slugs das permissions        ex: ['tickets.view', 'tickets.edit']
     */
    public static function setRbac(array $roles, array $permissions): void
    {
        self::$roles       = $roles;
        self::$permissions = $permissions;
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public static function user(): User
    {
        return self::$user ?? throw new RuntimeException(
            'AuthContext::user() acessado antes da autenticação.'
        );
    }

    public static function userId(): int
    {
        return self::user()->id;
    }

    public static function isAuthenticated(): bool
    {
        return self::$user !== null;
    }

    /** @return string[] */
    public static function roles(): array
    {
        return self::$roles;
    }

    /** @return string[] */
    public static function permissions(): array
    {
        return self::$permissions;
    }

    // ─── Verificações de autorização ──────────────────────────────────────────

    public static function hasRole(string $slug): bool
    {
        return in_array($slug, self::$roles, strict: true);
    }

    /**
     * Retorna true se o usuário tiver pelo menos uma das roles informadas.
     * Útil para rotas acessíveis por múltiplos papéis: ['admin', 'agent'].
     *
     * @param string[] $slugs
     */
    public static function hasAnyRole(array $slugs): bool
    {
        return !empty(array_intersect($slugs, self::$roles));
    }

    public static function hasPermission(string $slug): bool
    {
        return in_array($slug, self::$permissions, strict: true);
    }

    /**
     * Retorna true se o usuário tiver pelo menos uma das permissions informadas.
     *
     * @param string[] $slugs
     */
    public static function hasAnyPermission(array $slugs): bool
    {
        return !empty(array_intersect($slugs, self::$permissions));
    }

    /**
     * Retorna true se o usuário tiver TODAS as permissions informadas.
     * Para gates que exigem múltiplas permissões simultâneas.
     *
     * @param string[] $slugs
     */
    public static function hasAllPermissions(array $slugs): bool
    {
        return empty(array_diff($slugs, self::$permissions));
    }
}
