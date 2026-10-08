<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Repositories\EmailAccountRepository;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\OrganizationRepository;
use App\Services\Ticket\TicketService;
use App\Http\AuthContext;
use App\Models\User;
use App\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class EmailFetcherService
{
    public function __construct(
        private readonly EmailAccountRepository  $emailAccountRepo,
        private readonly UserRepositoryInterface $userRepository,
        private readonly OrganizationRepository  $orgRepository,
        private readonly TicketService           $ticketService,
        private readonly Connection              $connection,
        private readonly string                  $encryptionKey,
    ) {}

    /**
     * Busca emails de uma conta específica e abre tickets.
     * Retorna array com resultado: tickets_created, emails_processed, errors[].
     */
    public function fetchAccount(EmailAccount $account): array
    {
        $result = ['tickets_created' => 0, 'emails_processed' => 0, 'errors' => []];
        if ($missing = $this->imapMissing()) {
            $this->emailAccountRepo->touchFetched($account->id, $missing);
            return array_merge($result, ['errors' => [$missing]]);
        }

        $mailbox = $this->buildMailboxString($account);
        $pass    = $this->decrypt($account->password);

        $mbox = @imap_open($mailbox, $account->username, $pass, 0, 1);

        if ($mbox === false) {
            $err = imap_last_error() ?: 'Falha ao conectar ao servidor de e-mail.';
            imap_errors();
            imap_alerts();
            $this->emailAccountRepo->touchFetched($account->id, $err);
            return array_merge($result, ['errors' => [$err]]);
        }

        $isPop3 = $account->protocol === 'pop3';
        try {

            if ($isPop3) {
                // POP3 não suporta UNSEEN — processa todas as mensagens presentes
                $total = imap_num_msg($mbox);
                $uids  = $total > 0 ? range(1, $total) : [];
                $useUid = false;
            } else {
                $found = imap_search($mbox, 'UNSEEN', SE_UID);
                $uids   = $found !== false ? $found : [];
                $useUid = true;
            }

            if (empty($uids)) {
                // Sem mensagens novas. A conexão é fechada uma única vez no finally.
                $this->emailAccountRepo->touchFetched($account->id);
                return $result;
            }

            foreach ($uids as $uid) {
                try {
                    $this->processMessage($mbox, $uid, $account, $useUid);
                    $result['tickets_created']++;
                } catch (Throwable $e) {
                    $result['errors'][] = "UID {$uid}: " . $e->getMessage();
                }
                $result['emails_processed']++;
            }

            $this->emailAccountRepo->touchFetched($account->id, empty($result['errors']) ? null : implode('; ', $result['errors']));
        } finally {
            // POP3: CL_EXPUNGE remove de fato as mensagens já transformadas em chamado
            // (sem isso, cada busca recriaria os mesmos chamados).
            imap_close($mbox, $isPop3 ? CL_EXPUNGE : 0);
            imap_errors();
            imap_alerts();
        }

        return $result;
    }

    /**
     * Busca todos os accounts ativos e processa.
     * Retorna mapa account_id => result.
     */
    public function fetchAll(): array
    {
        $accounts = $this->emailAccountRepo->findActive();
        $results  = [];
        foreach ($accounts as $account) {
            // Uma conta com problema não impede as demais.
            try {
                $results[$account->id] = $this->fetchAccount($account);
            } catch (Throwable $e) {
                $this->emailAccountRepo->touchFetched($account->id, $e->getMessage());
                $results[$account->id] = ['tickets_created' => 0, 'emails_processed' => 0, 'errors' => [$e->getMessage()]];
            }
        }
        return $results;
    }

    /** Testa a conexão autenticando de verdade no servidor. */
    public function testConnection(EmailAccount $account): array
    {
        if ($missing = $this->imapMissing()) {
            return ['ok' => false, 'message' => $missing];
        }
        $mailbox = $this->buildMailboxString($account);
        $pass    = $this->decrypt($account->password);

        // Faz autenticação completa (sem OP_HALFOPEN) para capturar erros reais
        $mbox = @imap_open($mailbox, $account->username, $pass, 0, 1);

        if ($mbox === false) {
            $errors = imap_errors() ?: [];
            $err    = implode(' | ', $errors) ?: (imap_last_error() ?: 'Falha na conexão. Verifique host, porta e credenciais.');
            imap_alerts();
            return ['ok' => false, 'message' => $err];
        }

        $check = imap_check($mbox);
        $nmsgs = $check ? $check->Nmsgs : 0;
        imap_close($mbox);
        imap_errors();
        imap_alerts();

        return [
            'ok'      => true,
            'message' => "Conexão bem-sucedida. {$nmsgs} mensagem(ns) na pasta.",
            'nmsgs'   => $nmsgs,
        ];
    }

    /** Mensagem clara quando o PHP foi montado sem a extensão IMAP (em vez de erro 500). */
    private function imapMissing(): ?string
    {
        return function_exists('imap_open')
            ? null
            : 'A extensão IMAP do PHP não está instalada neste servidor. Reconstrua a imagem: docker compose build php && docker compose up -d php';
    }

    // ── private ──────────────────────────────────────────────────────────────

    private function processMessage($mbox, int $uid, EmailAccount $account, bool $useUid = true): void
    {
        $flags   = $useUid ? FT_UID : 0;
        $header  = imap_fetchheader($mbox, $uid, $flags);
        $struct  = imap_fetchstructure($mbox, $uid, $flags);

        $subject = $this->parseSubject($mbox, $uid, $useUid);
        $from    = $this->parseFrom($mbox, $uid, $useUid);
        $body    = $this->fetchBody($mbox, $uid, $struct, $useUid);

        if (empty($subject)) {
            $subject = '(Sem assunto)';
        }
        if (empty($body)) {
            $body = '(Sem conteúdo)';
        }

        // Encontra ou cria usuário pelo e-mail do remetente
        $requesterId = $this->resolveRequester($from['email'], $from['name'], $account);

        // Impersona o usuário para criar o ticket corretamente
        $user = $this->userRepository->findById($requesterId);
        $org  = $this->orgRepository->findById($account->organizationId);

        AuthContext::set($user);
        AuthContext::setRbac([], []);
        AuthContext::setOrganization($account->organizationId);

        $this->ticketService->openTicket([
            'subject'         => mb_substr($subject, 0, 500),
            'description'     => $body,
            'requester_id'    => $requesterId,
            'organization_id' => $account->organizationId,
            'priority'        => $account->defaultPriority,
            'source'          => 'email',
            'type'            => 'incident',
        ]);

        // Marca como lido (IMAP) ou deleta (POP3)
        if ($useUid) {
            imap_setflag_full($mbox, (string) $uid, '\\Seen', ST_UID);
        } else {
            imap_delete($mbox, $uid); // POP3: remove da caixa depois de processar
        }
    }

    private function parseSubject($mbox, int $uid, bool $useUid = true): string
    {
        $msgno  = $useUid ? imap_msgno($mbox, $uid) : $uid;
        $header = imap_headerinfo($mbox, $msgno);
        if (!$header) return '';

        $raw     = $header->subject ?? '';
        $decoded = imap_mime_header_decode($raw);
        $subject = '';
        foreach ($decoded as $part) {
            $charset  = ($part->charset !== 'default') ? $part->charset : 'UTF-8';
            $subject .= mb_convert_encoding($part->text, 'UTF-8', $charset);
        }
        return trim($subject);
    }

    private function parseFrom($mbox, int $uid, bool $useUid = true): array
    {
        $msgno  = $useUid ? imap_msgno($mbox, $uid) : $uid;
        $header = imap_headerinfo($mbox, $msgno);
        if (!$header || empty($header->from)) {
            return ['email' => 'unknown@unknown.com', 'name' => 'Desconhecido'];
        }

        $from  = $header->from[0];
        $email = ($from->mailbox ?? 'unknown') . '@' . ($from->host ?? 'unknown.com');
        $name  = '';
        if (!empty($from->personal)) {
            $decoded = imap_mime_header_decode($from->personal);
            foreach ($decoded as $part) {
                $charset = ($part->charset !== 'default') ? $part->charset : 'UTF-8';
                $name   .= mb_convert_encoding($part->text, 'UTF-8', $charset);
            }
        }
        if (empty($name)) {
            $name = ucfirst(explode('@', $email)[0]);
        }

        return ['email' => strtolower(trim($email)), 'name' => trim($name)];
    }

    private function fetchBody($mbox, int $uid, $struct, bool $useUid = true): string
    {
        // Começa com seção '' (raiz) — fetchPart calcula as subseções corretamente
        $text = $this->fetchPart($mbox, $uid, $struct, 'PLAIN', '', $useUid) ?? '';

        if (empty(trim($text))) {
            $html = $this->fetchPart($mbox, $uid, $struct, 'HTML', '', $useUid) ?? '';
            if ($html !== '') {
                $text = strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        // Remove marcadores de assinatura inline ([signature_123]) que alguns clientes de e-mail inserem.
        $text = preg_replace('/\[signature_\d+\]/', '', $text) ?? $text;

        return preg_replace('/\n{3,}/', "\n\n", trim($text)) ?? trim($text);
    }

    /**
     * Percorre a estrutura MIME e retorna o conteúdo da primeira parte TEXT/$subtype.
     *
     * $sectionPrefix = '' para o nível raiz da mensagem.
     * Filhos de um parte em seção X ficam em X.1, X.2, X.3 …
     * Para a raiz, o primeiro filho fica em '1', o segundo em '2', etc.
     */
    private function fetchPart($mbox, int $uid, $struct, string $subtype, string $sectionPrefix, bool $useUid = true): ?string
    {
        $flags = ($useUid ? FT_UID : 0) | FT_PEEK;

        if (isset($struct->type) && $struct->type === 0) {
            // Parte de texto simples (não multipart)
            if (strtoupper($struct->subtype ?? '') === $subtype) {
                // Parte simples na raiz usa seção '1'; subpartes usam o prefixo calculado pelo pai
                $section = $sectionPrefix !== '' ? $sectionPrefix : '1';
                $raw     = imap_fetchbody($mbox, $uid, $section, $flags);
                $decoded = $this->decodePart((string) $raw, $struct->encoding ?? 0);
                return $decoded !== '' ? $decoded : null;
            }
            return null;
        }

        // Multipart: percorre filhos calculando a seção correta
        if (!empty($struct->parts)) {
            foreach ($struct->parts as $i => $part) {
                // Seção do filho: raiz → '1','2',… ; subparte X → 'X.1','X.2',…
                $sec = $sectionPrefix !== '' ? $sectionPrefix . '.' . ($i + 1) : (string) ($i + 1);

                if (isset($part->type) && $part->type === 0 && strtoupper($part->subtype ?? '') === $subtype) {
                    $raw     = imap_fetchbody($mbox, $uid, $sec, $flags);
                    $decoded = $this->decodePart((string) $raw, $part->encoding ?? 0);
                    return $decoded !== '' ? $decoded : null;
                }

                // Recursão em multipart aninhado
                if (isset($part->type) && $part->type === 1 && !empty($part->parts)) {
                    $found = $this->fetchPart($mbox, $uid, $part, $subtype, $sec, $useUid);
                    if ($found !== null) return $found;
                }
            }
        }
        return null;
    }

    private function decodePart(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($raw),
            4 => quoted_printable_decode($raw),
            default => $raw,
        };
    }

    private function resolveRequester(string $email, string $name, EmailAccount $account): int
    {
        $existing = $this->userRepository->findByEmail($email);
        if ($existing !== null) {
            return $existing->id;
        }

        if (!$account->autoCreateUser) {
            // Usa o primeiro usuário da organização como fallback
            $stmt = $this->connection->pdo()->prepare(
                'SELECT user_id FROM user_organizations WHERE organization_id = :oid LIMIT 1'
            );
            $stmt->execute([':oid' => $account->organizationId]);
            $row = $stmt->fetch();
            if ($row) return (int) $row['user_id'];
            return 1; // fallback para admin
        }

        // Auto-cria usuário
        $now  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $uuid = $this->generateUuid();
        $parts = explode(' ', $name, 2);
        $firstName = $parts[0] ?? 'Usuário';
        $lastName  = $parts[1] ?? '';

        $this->connection->pdo()->prepare(
            'INSERT INTO users (uuid, name, first_name, last_name, email, password, is_active, created_at, updated_at)
             VALUES (:uuid, :name, :first, :last, :email, :pass, 1, :created_at, :updated_at)'
        )->execute([
            ':uuid'       => $uuid,
            ':name'       => trim($name) ?: $firstName,
            ':first'      => $firstName,
            ':last'       => $lastName,
            ':email'      => $email,
            ':pass'       => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $userId = (int) $this->connection->pdo()->lastInsertId();

        // Role client
        $this->connection->pdo()->prepare(
            'INSERT IGNORE INTO user_roles (user_id, role_id) SELECT :uid, id FROM roles WHERE slug = :slug'
        )->execute([':uid' => $userId, ':slug' => 'client']);

        // Vincula à empresa
        $this->orgRepository->linkUser($userId, $account->organizationId, isPrimary: true);

        return $userId;
    }

    private function buildMailboxString(EmailAccount $account): string
    {
        $ssl  = match ($account->encryption) {
            'ssl'  => '/ssl',
            'tls'  => '/tls',
            default => '/notls',
        };
        $proto = $account->protocol === 'pop3' ? 'pop3' : 'imap';
        return "{{$account->host}:{$account->port}/{$proto}{$ssl}/novalidate-cert}{$account->mailFolder}";
    }

    public function encrypt(string $plain): string
    {
        $iv  = random_bytes(16);
        $key = substr(hash('sha256', $this->encryptionKey, true), 0, 32);
        $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $enc);
    }

    public function decrypt(string $cipher): string
    {
        $raw = base64_decode($cipher);
        $iv  = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $key = substr(hash('sha256', $this->encryptionKey, true), 0, 32);
        return openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv) ?: '';
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
