<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\AuthContext;
use App\Http\Response;

final class MeController
{
    /**
     * GET /api/v1/auth/me
     *
     * Retorna o perfil do usuário autenticado com suas roles e permissions.
     * Útil para o front-end construir menus dinâmicos e guards de rota client-side.
     *
     * Rota protegida — requer AuthMiddleware (que já populou AuthContext).
     */
    public function show(): never
    {
        $user = AuthContext::user();

        Response::success([
            'user'        => $user->toPublicArray(),
            'roles'       => AuthContext::roles(),
            'permissions' => AuthContext::permissions(),
        ]);
    }
}
