<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database\Connection;
use App\Exceptions\ValidationException;
use App\Repositories\UserRepository;
use App\Services\Notification\NotificationService;
use App\Services\Notification\EmailTemplateService;
use Throwable;

/**
 * Recuperação de senha por link com token de uso único.
 *
 * Segurança:
 *  - token de 256 bits (random_bytes); só o SHA-256 é gravado;
 *  - validade de 30 minutos, uso único; pedir de novo invalida os anteriores;
 *  - resposta idêntica exista ou não a conta, e o e-mail vai para a fila de saída
 *    (enviado após a resposta HTTP, com novas tentativas pelo cron) — sem diferença
 *    de tempo que permita descobrir contas;
 *  - limite de pedidos por e-mail (3 / 15 min) e por IP (10 / hora);
 *  - o link leva o token no fragmento (#), que nunca chega a logs de servidor;
 *  - ao redefinir, todas as sessões do usuário são encerradas e ele é avisado.
 */
final class PasswordResetService
{
    private const TTL_MINUTES       = 30;
    private const MAX_PER_EMAIL_15M = 3;
    private const MAX_PER_IP_HOUR   = 10;

    public function __construct(
        private readonly Connection          $connection,
        private readonly UserRepository      $userRepository,
        private readonly NotificationService  $notificationService,
        private readonly EmailTemplateService $templates,
    ) {}

    /** Registra o pedido e agenda o e-mail. Nunca revela se a conta existe. */
    public function request(string $email, string $ip, string $userAgent): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => ['Informe um e-mail válido.']]);
        }
        $pdo = $this->connection->pdo();

        $byEmail = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE email = :e AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)');
        $byEmail->execute([':e' => $email]);
        $byIp = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE ip_address = :ip AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
        $byIp->execute([':ip' => $ip]);
        if ((int) $byEmail->fetchColumn() >= self::MAX_PER_EMAIL_15M || (int) $byIp->fetchColumn() >= self::MAX_PER_IP_HOUR) {
            return; // silencioso: mesma resposta para o cliente
        }

        $user = $this->userRepository->findByEmail($email);
        $usable = $user !== null && $user->isActive;
        $token = $usable ? bin2hex(random_bytes(32)) : null;

        if ($usable) {
            // Um link novo invalida os anteriores ainda não usados.
            $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = :u AND used_at IS NULL')
                ->execute([':u' => $user->id]);
        }
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, email, token_hash, ip_address, user_agent, expires_at)
             VALUES (:u, :e, :h, :ip, :ua, IF(:h2 IS NULL, NULL, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::TTL_MINUTES . ' MINUTE)))'
        )->execute([
            ':u'  => $usable ? $user->id : null,
            ':e'  => $email,
            ':h'  => $token ? hash('sha256', $token) : null,
            ':h2' => $token ? 'x' : null,
            ':ip' => $ip,
            ':ua' => mb_substr($userAgent, 0, 255),
        ]);

        if (!$usable) {
            return;
        }

        $name = trim("{$user->firstName} {$user->lastName}") ?: $user->name;
        $mail = $this->templates->render('auth.password_reset', [
            'usuario.nome'   => $name,
            'usuario.email'  => $user->email,
            'senha.link'     => $this->templates->baseUrl() . '/#/reset-password/' . $token,
            'senha.validade' => self::TTL_MINUTES . ' minutos',
            'senha.ip'       => $ip,
        ]);
        $this->notificationService->queueEmail($user->id, $user->email, $name, 'auth.password_reset', $mail['subject'], $mail['html']);
    }

    /** O token ainda serve? (para a tela mostrar o formulário ou "link expirado") */
    public function check(string $token): bool
    {
        return $this->findValid($token) !== null;
    }

    /** @throws ValidationException */
    public function reset(string $token, string $password, string $confirmation, string $ip): void
    {
        $row = $this->findValid($token);
        if ($row === null) {
            throw new ValidationException(['token' => ['Este link é inválido, expirou ou já foi usado. Peça um novo.']]);
        }
        $user = $this->userRepository->findById((int) $row['user_id']);
        if ($user === null || !$user->isActive) {
            throw new ValidationException(['token' => ['Esta conta não está disponível.']]);
        }

        $errors = [];
        if (strlen($password) < 8) {
            $errors['password'][] = 'A senha deve ter pelo menos 8 caracteres.';
        } elseif (strlen($password) > 255) {
            $errors['password'][] = 'A senha é longa demais.';
        } elseif (preg_match('/^(.)\1+$/', $password) || in_array(strtolower($password), ['12345678', '123456789', 'password', 'senha123', 'senha1234', 'qwerty123', 'abcd1234'], true)) {
            $errors['password'][] = 'Essa senha é fácil demais de adivinhar. Escolha outra.';
        } elseif (stripos($password, explode('@', $user->email)[0]) !== false && strlen(explode('@', $user->email)[0]) >= 4) {
            $errors['password'][] = 'A senha não pode conter o seu e-mail.';
        } elseif (password_verify($password, $user->password)) {
            $errors['password'][] = 'A nova senha deve ser diferente da atual.';
        }
        if ($password !== $confirmation) {
            $errors['password_confirmation'][] = 'As senhas não coincidem.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            // Marca o token como usado só se ainda estiver livre (evita corrida com dois envios).
            $mark = $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL');
            $mark->execute([':id' => $row['id']]);
            if ($mark->rowCount() !== 1) {
                throw new ValidationException(['token' => ['Este link já foi usado. Peça um novo.']]);
            }
            $this->userRepository->update($user->id, ['password' => password_hash($password, PASSWORD_BCRYPT)]);
            $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = :u AND used_at IS NULL')->execute([':u' => $user->id]);
            $pdo->prepare('DELETE FROM api_tokens WHERE user_id = :u')->execute([':u' => $user->id]); // encerra todas as sessões
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $name = trim("{$user->firstName} {$user->lastName}") ?: $user->name;
        $mail = $this->templates->render('auth.password_changed', [
            'usuario.nome'  => $name,
            'usuario.email' => $user->email,
            'senha.data'    => (new \DateTimeImmutable('now', new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo')))->format('d/m/Y \à\s H:i'),
            'senha.ip'      => $ip,
        ]);
        $this->notificationService->queueEmail($user->id, $user->email, $name, 'auth.password_changed', $mail['subject'], $mail['html']);
    }

    private function findValid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $s = $this->connection->pdo()->prepare(
            'SELECT id, user_id FROM password_resets
              WHERE token_hash = :h AND used_at IS NULL AND user_id IS NOT NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $s->execute([':h' => hash('sha256', $token)]);
        $row = $s->fetch();
        return $row ?: null;
    }
}
