<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Email;

use App\Http\Response;
use App\Repositories\EmailAccountRepository;
use App\Repositories\OrganizationRepository;
use App\Services\Email\EmailFetcherService;
use JsonException;
use Throwable;

final class EmailAccountController
{
    public function __construct(
        private readonly EmailAccountRepository $emailAccountRepo,
        private readonly OrganizationRepository $orgRepo,
        private readonly EmailFetcherService    $fetcher,
    ) {}

    /** GET /api/v1/email-accounts */
    public function index(): never
    {
        $accounts = $this->emailAccountRepo->findAll();
        Response::success(data: [
            'items' => array_map(static fn($a) => $a->toArray(), $accounts),
            'total' => count($accounts),
        ]);
    }

    /** POST /api/v1/email-accounts */
    public function store(): never
    {
        $data = $this->parseBody();

        $errors = $this->validate($data);
        if ($errors) {
            Response::error('Dados inválidos.', errors: $errors, statusCode: 422);
        }

        try {
            $now  = date('Y-m-d H:i:s');
            $uuid = $this->uuid();

            $id = $this->emailAccountRepo->create([
                'uuid'            => $uuid,
                'organization_id' => (int) $data['organization_id'],
                'name'            => trim($data['name']),
                'host'            => trim($data['host']),
                'port'            => (int) ($data['port'] ?? 993),
                'protocol'        => $data['protocol'] ?? 'imap',
                'encryption'      => $data['encryption'] ?? 'ssl',
                'username'        => trim($data['username']),
                'password'        => $this->fetcher->encrypt((string) $data['password']),
                'mail_folder'     => $data['mail_folder'] ?? 'INBOX',
                'default_priority'=> $data['default_priority'] ?? 'medium',
                'auto_create_user'=> (bool) ($data['auto_create_user'] ?? true),
            ]);

            $account = $this->emailAccountRepo->findById($id);
            Response::success(data: $account->toArray(), message: 'Conta de e-mail cadastrada.', statusCode: 201);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro interno.', statusCode: 500);
        }
    }

    /** PATCH /api/v1/email-accounts/{id} */
    public function update(int $id): never
    {
        $account = $this->emailAccountRepo->findById($id);
        if (!$account) Response::error('Conta não encontrada.', statusCode: 404);

        $data    = $this->parseBody();
        $updates = [];

        foreach (['name','host','port','protocol','encryption','username','mail_folder','default_priority','auto_create_user','is_active'] as $f) {
            if (array_key_exists($f, $data)) $updates[$f] = $data[$f];
        }
        // Nova senha só se enviada e não vazia
        if (!empty($data['password'])) {
            $updates['password'] = $this->fetcher->encrypt((string) $data['password']);
        }

        $this->emailAccountRepo->update($id, $updates);
        $updated = $this->emailAccountRepo->findById($id);
        Response::success(data: $updated->toArray(), message: 'Conta atualizada.');
    }

    /** DELETE /api/v1/email-accounts/{id} */
    public function destroy(int $id): never
    {
        $account = $this->emailAccountRepo->findById($id);
        if (!$account) Response::error('Conta não encontrada.', statusCode: 404);

        $this->emailAccountRepo->delete($id);
        Response::success(data: [], message: 'Conta removida.');
    }

    /** POST /api/v1/email-accounts/{id}/test */
    public function test(int $id): never
    {
        $account = $this->emailAccountRepo->findById($id);
        if (!$account) Response::error('Conta não encontrada.', statusCode: 404);

        // Resultado do teste sempre com HTTP 200 e `ok` (falha de login no servidor é um resultado esperado).
        $result = $this->fetcher->testConnection($account);
        Response::success(data: $result, message: $result['message']);
    }

    /** POST /api/v1/email-accounts/{id}/fetch */
    public function fetch(int $id): never
    {
        $account = $this->emailAccountRepo->findById($id);
        if (!$account) Response::error('Conta não encontrada.', statusCode: 404);

        $result = $this->fetcher->fetchAccount($account);
        Response::success(data: $result, message: "Processados: {$result['emails_processed']} e-mail(s), {$result['tickets_created']} ticket(s) criado(s).");
    }

    /** POST /api/v1/email-accounts/fetch-all */
    public function fetchAll(): never
    {
        $results = $this->fetcher->fetchAll();
        $total   = array_sum(array_column($results, 'tickets_created'));
        Response::success(data: $results, message: "{$total} ticket(s) criado(s) no total.");
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function parseBody(): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') Response::error('Corpo vazio.', statusCode: 400);
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error('JSON inválido.', statusCode: 400);
        }
        if (!is_array($data)) Response::error('JSON deve ser objeto.', statusCode: 400);
        return $data;
    }

    private function validate(array $d): array
    {
        $errors = [];
        if (empty($d['organization_id'])) $errors['organization_id'][] = 'Obrigatório.';
        if (empty($d['name']))            $errors['name'][]  = 'Obrigatório.';
        if (empty($d['host']))            $errors['host'][]  = 'Obrigatório.';
        if (empty($d['username']))        $errors['username'][] = 'Obrigatório.';
        if (empty($d['password']))        $errors['password'][] = 'Obrigatório.';
        if (!empty($d['protocol']) && !in_array($d['protocol'], ['imap','pop3'], true))
            $errors['protocol'][] = 'Deve ser imap ou pop3.';
        if (!empty($d['encryption']) && !in_array($d['encryption'], ['ssl','tls','none'], true))
            $errors['encryption'][] = 'Deve ser ssl, tls ou none.';
        if (!empty($d['organization_id']) && !$this->orgRepo->findById((int)$d['organization_id']))
            $errors['organization_id'][] = 'Empresa não encontrada.';
        return $errors;
    }

    private function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
