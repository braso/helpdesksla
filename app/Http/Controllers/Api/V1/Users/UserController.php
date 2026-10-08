<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Users;

use App\Exceptions\ValidationException;
use App\Http\Response;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Auth\RegisterService;
use JsonException;
use Throwable;

final class UserController
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly RegisterService         $registerService,
    ) {}

    /** GET /api/v1/users */
    public function index(): never
    {
        $filters = [];
        if (!empty($_GET['organization_id'])) {
            $filters['organization_id'] = (int) $_GET['organization_id'];
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }

        $rows = $this->userRepository->findAllWithOrg($filters);

        Response::success(data: [
            'items' => array_map(static fn(array $r) => [
                'id'         => (int) $r['id'],
                'uuid'       => $r['uuid'],
                'first_name' => $r['first_name'],
                'last_name'  => $r['last_name'],
                'name'       => $r['name'],
                'email'      => $r['email'],
                'is_active'  => (bool) $r['is_active'],
                'org_id'     => $r['org_id']   ? (int) $r['org_id'] : null,
                'org_name'   => $r['org_name']  ?? null,
                'role_slug'  => $r['role_slug'] ?? null,
                'role_name'  => $r['role_name'] ?? null,
                'created_at' => $r['created_at'],
            ], $rows),
            'total' => count($rows),
        ]);
    }

    /** POST /api/v1/users — admin cria usuário vinculado a uma empresa */
    public function store(): never
    {
        $rawBody = (string) file_get_contents('php://input');

        if ($rawBody === '') {
            Response::error('O corpo da requisição não pode estar vazio.', statusCode: 400);
        }

        try {
            $data = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error('JSON inválido: ' . $e->getMessage(), statusCode: 400);
        }

        if (!is_array($data)) {
            Response::error('O corpo deve ser um objeto JSON.', statusCode: 400);
        }

        try {
            $user = $this->registerService->register($data);
            Response::success(
                data:       $user->toPublicArray(),
                message:    'Usuário cadastrado com sucesso.',
                statusCode: 201,
            );
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** GET /api/v1/users/{id} */
    public function show(int $id): never
    {
        $row = $this->userRepository->findWithDetails($id);

        if ($row === null) {
            Response::error('Usuário não encontrado.', statusCode: 404);
        }

        Response::success(data: [
            'id'           => (int) $row['id'],
            'uuid'         => $row['uuid'],
            'first_name'   => $row['first_name'],
            'last_name'    => $row['last_name'],
            'name'         => $row['name'],
            'email'        => $row['email'],
            'phone'        => $row['phone']        ?? null,
            'phone_mobile' => $row['phone_mobile'] ?? null,
            'position'     => $row['position']     ?? null,
            'department'   => $row['department']   ?? null,
            'is_active'    => (bool) $row['is_active'],
            'org_id'       => $row['org_id']   ? (int) $row['org_id'] : null,
            'org_name'     => $row['org_name']  ?? null,
            'role_id'      => $row['role_id']   ? (int) $row['role_id'] : null,
            'role_slug'    => $row['role_slug'] ?? null,
            'role_name'    => $row['role_name'] ?? null,
            'created_at'   => $row['created_at'],
            'updated_at'   => $row['updated_at'],
        ]);
    }

    /** PATCH /api/v1/users/{id} */
    public function update(int $id): never
    {
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            Response::error('Usuário não encontrado.', statusCode: 404);
        }

        $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);

        $firstName = trim((string) ($body['first_name'] ?? $user->firstName));
        $lastName  = trim((string) ($body['last_name']  ?? $user->lastName));
        $email     = trim((string) ($body['email']      ?? $user->email));

        $errors = [];
        if ($firstName === '') {
            $errors['first_name'] = ['O nome é obrigatório.'];
        }
        if ($lastName === '') {
            $errors['last_name'] = ['O sobrenome é obrigatório.'];
        }
        if ($email === '') {
            $errors['email'] = ['O e-mail é obrigatório.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['E-mail inválido.'];
        }

        if (!empty($errors)) {
            Response::error('Dados inválidos.', errors: $errors, statusCode: 422);
        }

        // E-mail deve ser único
        $existing = $this->userRepository->findByEmail($email);
        if ($existing !== null && $existing->id !== $id) {
            Response::error('Este e-mail já está em uso por outro usuário.', statusCode: 422);
        }

        // Validar nova senha se fornecida
        $newPassword = null;
        if (!empty($body['password'])) {
            if (strlen((string) $body['password']) < 8) {
                Response::error('A senha deve ter no mínimo 8 caracteres.', statusCode: 422);
            }
            $newPassword = password_hash((string) $body['password'], PASSWORD_BCRYPT);
        }

        // Validar e verificar proteção do último admin
        $newRole = isset($body['role']) ? trim((string) $body['role']) : null;
        if ($newRole !== null) {
            $validRoles = ['admin', 'agent', 'manager', 'client'];
            if (!in_array($newRole, $validRoles, true)) {
                Response::error('Papel inválido.', statusCode: 422);
            }

            if ($newRole !== 'admin' && $this->userRepository->getUserRole($id) === 'admin') {
                if ($this->userRepository->countActiveAdmins($id) === 0) {
                    Response::error('Não é possível remover o único administrador ativo do sistema.', statusCode: 422);
                }
            }
        }

        // Verificar desativação do último admin
        if (isset($body['is_active']) && !(bool) $body['is_active'] && $user->isActive) {
            if ($this->userRepository->getUserRole($id) === 'admin') {
                if ($this->userRepository->countActiveAdmins($id) === 0) {
                    Response::error('Não é possível desativar o único administrador ativo do sistema.', statusCode: 422);
                }
            }
        }

        try {
            $updateData = [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'name'       => $firstName . ' ' . $lastName,
                'email'      => $email,
            ];

            foreach (['phone', 'phone_mobile', 'position', 'department'] as $field) {
                if (array_key_exists($field, $body)) {
                    $updateData[$field] = trim((string) $body[$field]) ?: null;
                }
            }

            if (isset($body['is_active'])) {
                $updateData['is_active'] = (int) (bool) $body['is_active'];
            }

            if ($newPassword !== null) {
                $updateData['password'] = $newPassword;
            }

            $this->userRepository->update($id, $updateData);

            if ($newRole !== null) {
                $this->userRepository->updateRole($id, $newRole);
            }

            if (array_key_exists('organization_id', $body)) {
                $orgId = $body['organization_id'] !== null ? (int) $body['organization_id'] : null;
                $this->userRepository->updatePrimaryOrg($id, $orgId);
            }

            $updated = $this->userRepository->findWithDetails($id);
            Response::success(data: $updated, message: 'Usuário atualizado com sucesso.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** DELETE /api/v1/users/{id} */
    public function destroy(int $id): never
    {
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            Response::error('Usuário não encontrado.', statusCode: 404);
        }

        if ($this->userRepository->getUserRole($id) === 'admin') {
            if ($this->userRepository->countActiveAdmins($id) === 0) {
                Response::error('Não é possível excluir o único administrador ativo do sistema.', statusCode: 422);
            }
        }

        try {
            $this->userRepository->softDelete($id);
            Response::success(data: null, message: 'Usuário removido com sucesso.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }
}
