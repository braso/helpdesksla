<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database\Connection;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Validator;
use DateTimeImmutable;
use DateTimeZone;

final class RegisterService
{
    public function __construct(
        private readonly Connection               $connection,
        private readonly UserRepositoryInterface  $userRepository,
        private readonly OrganizationRepository   $organizationRepository,
    ) {}

    private const ALLOWED_ROLES = ['admin', 'agent', 'supervisor', 'client', 'manager'];

    /**
     * Registra um novo usuário obrigatoriamente vinculado a uma empresa.
     *
     * Campos obrigatórios:
     *   first_name, last_name, email, password, password_confirmation
     *   organization_id  (int)    — vincula empresa existente
     *   OU
     *   organization_name (string) — cria nova empresa
     *
     * Campos opcionais:
     *   role  (string) — papel do usuário; padrão: 'client'
     *
     * @throws ValidationException
     */
    public function register(array $data): User
    {
        $this->validate($data);

        $now  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $uuid = $this->generateUuid();

        $userId = $this->createUser($data, $uuid, $now);

        // Empresa é obrigatória — já validado em validate()
        if (!empty($data['organization_id'])) {
            $orgId = (int) $data['organization_id'];
            if ($this->organizationRepository->findById($orgId) === null) {
                throw new ValidationException(['organization_id' => ['Empresa não encontrada.']]);
            }
            $this->organizationRepository->linkUser($userId, $orgId);
        } else {
            $orgId = $this->organizationRepository->create([
                'uuid'       => $this->generateUuid(),
                'name'       => trim($data['organization_name']),
                'domain'     => isset($data['organization_domain']) ? trim($data['organization_domain']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->organizationRepository->linkUser($userId, $orgId, isPrimary: true);
        }

        return $this->userRepository->findById($userId);
    }

    /** @throws ValidationException */
    private function validate(array $data): void
    {
        $validator = (new Validator())
            ->required('first_name', $data['first_name'] ?? null)
            ->required('last_name',  $data['last_name']  ?? null)
            ->required('email',      $data['email']      ?? null)
            ->required('password',   $data['password']   ?? null)
            ->maxLength('first_name', (string) ($data['first_name'] ?? ''), 100)
            ->maxLength('last_name',  (string) ($data['last_name']  ?? ''), 100)
            ->maxLength('password',   (string) ($data['password']   ?? ''), 255);

        if (!$validator->passes()) {
            throw new ValidationException($validator->errors());
        }

        $errors = [];

        $email = trim((string) ($data['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'O campo email deve ser um endereço de e-mail válido.';
        } elseif ($this->userRepository->findByEmail($email) !== null) {
            $errors['email'][] = 'Este e-mail já está em uso.';
        }

        if (strlen((string) ($data['password'] ?? '')) < 8) {
            $errors['password'][] = 'A senha deve ter pelo menos 8 caracteres.';
        }

        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            $errors['password_confirmation'][] = 'As senhas não coincidem.';
        }

        // Empresa é obrigatória
        $hasOrgId   = !empty($data['organization_id']);
        $hasOrgName = !empty(trim((string) ($data['organization_name'] ?? '')));
        if (!$hasOrgId && !$hasOrgName) {
            $errors['organization'][] = 'Selecione uma empresa existente ou informe o nome de uma nova.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    private function createUser(array $data, string $uuid, string $now): int
    {
        $pdo = $this->connection->pdo();

        $firstName = trim((string) $data['first_name']);
        $lastName  = trim((string) $data['last_name']);
        $fullName  = $firstName . ($lastName !== '' ? ' ' . $lastName : '');

        $pdo->prepare(
            'INSERT INTO users (uuid, name, first_name, last_name, email, password, is_active, created_at, updated_at)
             VALUES (:uuid, :name, :first_name, :last_name, :email, :password, 1, :created_at, :updated_at)'
        )->execute([
            ':uuid'       => $uuid,
            ':name'       => $fullName,
            ':first_name' => $firstName,
            ':last_name'  => $lastName,
            ':email'      => trim((string) $data['email']),
            ':password'   => password_hash((string) $data['password'], PASSWORD_BCRYPT),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $userId = (int) $pdo->lastInsertId();

        $role = in_array($data['role'] ?? 'client', self::ALLOWED_ROLES, true)
            ? $data['role']
            : 'client';

        $pdo->prepare(
            'INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT :uid, id FROM roles WHERE slug = :slug'
        )->execute([':uid' => $userId, ':slug' => $role]);

        return $userId;
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
