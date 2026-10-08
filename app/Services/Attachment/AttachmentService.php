<?php

declare(strict_types=1);

namespace App\Services\Attachment;

use App\Core\Database\Connection;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AttachmentRepository;

final class AttachmentService
{
    private const DEFAULT_MAX_MB    = 10;
    private const DEFAULT_TYPES     = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,zip,txt';

    // Accepted real MIME types per extension
    private const MIME_MAP = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'mp4'  => ['video/mp4'],
        'webp' => ['image/webp'],
    ];

    public function __construct(
        private readonly AttachmentRepository $repository,
        private readonly Connection $connection,
    ) {}

    public function getSettings(): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT `key`, `value` FROM system_settings
             WHERE `key` IN ('attachment_max_size_mb', 'attachment_allowed_types')"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];

        return [
            'max_size_mb'   => (int) ($rows['attachment_max_size_mb']   ?? self::DEFAULT_MAX_MB),
            'allowed_types' => $rows['attachment_allowed_types'] ?? self::DEFAULT_TYPES,
        ];
    }

    public function saveSettings(int $maxSizeMb, string $allowedTypes): void
    {
        $pdo  = $this->connection->pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO system_settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([':k' => 'attachment_max_size_mb',   ':v' => (string) $maxSizeMb]);
        $stmt->execute([':k' => 'attachment_allowed_types', ':v' => $allowedTypes]);
    }

    /**
     * Valida e persiste um único arquivo do $_FILES.
     * $fileInfo: ['name' => ..., 'tmp_name' => ..., 'size' => ..., 'error' => ...]
     */
    /**
     * Valida um arquivo enviado (erro de upload, tamanho, extensão e tipo real),
     * sem gravá-lo. Permite checar todos os anexos antes de criar a resposta.
     *
     * @return array{0: string, 1: string, 2: string} [nome original, extensão, MIME real]
     * @throws ValidationException
     */
    public function validateUpload(array $fileInfo): array
    {
        if ((int) $fileInfo['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException(
                ['file' => [$this->uploadErrorMessage((int) $fileInfo['error'])]],
                'Erro no upload do arquivo.'
            );
        }

        $settings     = $this->getSettings();
        $maxBytes     = $settings['max_size_mb'] * 1024 * 1024;
        $allowedTypes = array_map('trim', explode(',', strtolower($settings['allowed_types'])));

        if ((int) $fileInfo['size'] > $maxBytes) {
            throw new ValidationException(
                ['file' => ["O arquivo excede o tamanho máximo de {$settings['max_size_mb']} MB."]],
                'Arquivo muito grande.'
            );
        }

        $originalName = basename((string) $fileInfo['name']);
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedTypes, true)) {
            throw new ValidationException(
                ['file' => ["Tipo .{$ext} não permitido. Aceitos: {$settings['allowed_types']}."]],
                'Tipo de arquivo inválido.'
            );
        }

        // Verify actual MIME against expected MIMEs for the allowed extensions
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file((string) $fileInfo['tmp_name']);

        $allowedMimes = array_unique(array_merge(...array_map(
            static fn(string $t) => self::MIME_MAP[$t] ?? [],
            $allowedTypes
        )));

        if (!empty($allowedMimes) && !in_array($realMime, $allowedMimes, true)) {
            throw new ValidationException(
                ['file' => ["O conteúdo do arquivo ({$realMime}) não corresponde à extensão .{$ext}."]],
                'MIME type inválido.'
            );
        }

        return [$originalName, $ext, $realMime];
    }

    public function processUpload(
        array  $fileInfo,
        string $attachableType,
        int    $attachableId,
        int    $uploaderId,
    ): array {
        [$originalName, $ext, $realMime] = $this->validateUpload($fileInfo);

        $storedName  = $this->generateUuid() . '.' . $ext;
        $storagePath = $this->storeLocal((string) $fileInfo['tmp_name'], $storedName);

        $rowId = $this->repository->create([
            ':uuid'            => $this->generateUuid(),
            ':attachable_type' => $attachableType,
            ':attachable_id'   => $attachableId,
            ':uploader_id'     => $uploaderId,
            ':original_name'   => $originalName,
            ':stored_name'     => $storedName,
            ':mime_type'       => $realMime,
            ':size_bytes'      => (int) $fileInfo['size'],
            ':storage_driver'  => 'local',
            ':storage_path'    => $storagePath,
        ]);

        return $this->repository->findById($rowId) ?? [];
    }

    /**
     * Normaliza o formato de múltiplos arquivos do $_FILES (attachments[]).
     * Retorna array de file-info arrays individuais.
     */
    public static function normalizeFiles(array $filesEntry): array
    {
        if (!is_array($filesEntry['name'])) {
            return [$filesEntry];
        }

        $normalized = [];
        foreach (array_keys($filesEntry['name']) as $i) {
            $normalized[] = [
                'name'     => $filesEntry['name'][$i],
                'type'     => $filesEntry['type'][$i],
                'tmp_name' => $filesEntry['tmp_name'][$i],
                'error'    => $filesEntry['error'][$i],
                'size'     => $filesEntry['size'][$i],
            ];
        }
        return $normalized;
    }

    /**
     * Anexos do chamado e das respostas dele. Clientes não veem anexos de notas internas.
     * Cada item traz reply_id (null para anexos da abertura do chamado).
     */
    public function findByTicket(int $ticketId, bool $includePrivate = true): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT a.*, NULL AS reply_id FROM attachments a
              WHERE a.attachable_type = 'ticket' AND a.attachable_id = :t1
             UNION ALL
             SELECT a.*, r.id AS reply_id FROM attachments a
               JOIN ticket_replies r ON r.id = a.attachable_id AND a.attachable_type = 'reply'
              WHERE r.ticket_id = :t2 AND r.deleted_at IS NULL" . ($includePrivate ? '' : ' AND r.is_private = 0') . "
              ORDER BY created_at ASC, id ASC"
        );
        $stmt->execute([':t1' => $ticketId, ':t2' => $ticketId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Resposta à qual o anexo pertence (para checar acesso). */
    public function replyOwner(int $replyId): ?array
    {
        $s = $this->connection->pdo()->prepare('SELECT id, ticket_id, is_private FROM ticket_replies WHERE id = :id AND deleted_at IS NULL');
        $s->execute([':id' => $replyId]);
        $r = $s->fetch(\PDO::FETCH_ASSOC);
        return $r ? ['id' => (int) $r['id'], 'ticket_id' => (int) $r['ticket_id'], 'is_private' => (bool) $r['is_private']] : null;
    }

    /** Limites atuais para a interface validar antes de enviar. */
    public function limits(): array
    {
        $s = $this->getSettings();
        return ['max_size_mb' => $s['max_size_mb'], 'allowed_types' => array_values(array_filter(array_map('trim', explode(',', strtolower($s['allowed_types'])))))];
    }

    public function findById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    public function delete(int $id): void
    {
        $attachment = $this->repository->findById($id);
        if (!$attachment) {
            throw new NotFoundException('Anexo');
        }

        $fullPath = $this->storagePath() . '/' . $attachment['storage_path'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        $this->repository->delete($id);
    }

    public function serveDownload(int $id): never
    {
        $attachment = $this->repository->findById($id);
        if (!$attachment) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Anexo não encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fullPath = $this->storagePath() . '/' . $attachment['storage_path'];
        if (!is_file($fullPath)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Arquivo não encontrado no servidor.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Disposition: attachment; filename="' . rawurlencode($attachment['original_name']) . '"');
        header('Content-Length: ' . (string) filesize($fullPath));
        header('Cache-Control: private, no-cache');
        readfile($fullPath);
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function storagePath(): string
    {
        return dirname(__DIR__, 3) . '/storage/attachments';
    }

    private function storeLocal(string $tmpPath, string $storedName): string
    {
        $yearMonth = date('Y/m');
        $dir       = $this->storagePath() . '/' . $yearMonth;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $destination  = $dir . '/' . $storedName;
        $relativePath = $yearMonth . '/' . $storedName;

        move_uploaded_file($tmpPath, $destination);

        return $relativePath;
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o limite de tamanho.',
            UPLOAD_ERR_PARTIAL    => 'Upload incompleto, tente novamente.',
            UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo recebido.',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário indisponível no servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo no disco.',
            default               => "Código de erro {$error}.",
        };
    }
}
