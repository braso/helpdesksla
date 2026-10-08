<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Database\Connection;
use App\Support\Crypto;

/**
 * Configuração da integração de IA, persistida em system_settings.
 *
 * Dois provedores: DeepSeek e Google Gemini. Cada um guarda a própria chave, modelo e
 * endereço — trocar o provedor ativo não apaga a configuração do outro.
 * As chaves ficam criptografadas e nunca são devolvidas à interface.
 */
final class AiSettings
{
    public const PROVIDERS = ['deepseek', 'gemini'];
    public const MODELS = [
        'deepseek' => ['deepseek-chat', 'deepseek-reasoner'],
        // Os 2.5 não são mais liberados a contas novas (out/2026); a lista real vem de GET /models.
        'gemini'   => ['gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-pro-preview'],
    ];
    public const DEFAULT_URL = [
        'deepseek' => 'https://api.deepseek.com',
        'gemini'   => 'https://generativelanguage.googleapis.com',
    ];
    public const LABEL = ['deepseek' => 'DeepSeek', 'gemini' => 'Google Gemini'];
    public const FEATURES = ['triage', 'tips', 'similar', 'classify', 'summary', 'deflect', 'reports', 'satisfaction', 'assignee'];

    /** Chaves de system_settings por provedor (DeepSeek mantém os nomes originais). */
    private const PKEYS = [
        'deepseek' => ['api_key' => 'ai_api_key',        'model' => 'ai_model',        'base_url' => 'ai_base_url'],
        'gemini'   => ['api_key' => 'ai_gemini_api_key', 'model' => 'ai_gemini_model', 'base_url' => 'ai_gemini_base_url'],
    ];

    private ?array $cache = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Crypto     $crypto,
    ) {}

    /**
     * Configuração efetiva: provider ativo, api_key/model/base_url do provider ativo,
     * `providers` (config de cada um) e uma flag booleana por recurso (FEATURES).
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $keys = ['ai_enabled', 'ai_provider'];
        foreach (self::PKEYS as $map) {
            array_push($keys, ...array_values($map));
        }
        foreach (self::FEATURES as $f) {
            $keys[] = 'ai_feature_' . $f;
        }
        $in   = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->connection->pdo()->prepare("SELECT `key`, `value` FROM system_settings WHERE `key` IN ({$in})");
        $stmt->execute($keys);
        $r = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];

        $providers = [];
        foreach (self::PKEYS as $p => $map) {
            $model = (string) ($r[$map['model']] ?? '');
            $providers[$p] = [
                'api_key'  => ($r[$map['api_key']] ?? '') !== '' ? $this->crypto->decrypt($r[$map['api_key']]) : '',
                'model'    => self::validModel($p, $model) ? $model : self::MODELS[$p][0],
                'base_url' => rtrim((string) ($r[$map['base_url']] ?? ''), '/') ?: self::DEFAULT_URL[$p],
            ];
        }
        $provider = in_array($r['ai_provider'] ?? '', self::PROVIDERS, true) ? $r['ai_provider'] : 'deepseek';

        $c = [
            'enabled'   => ($r['ai_enabled'] ?? '0') === '1',
            'provider'  => $provider,
            'api_key'   => $providers[$provider]['api_key'],
            'model'     => $providers[$provider]['model'],
            'base_url'  => $providers[$provider]['base_url'],
            'providers' => $providers,
        ];
        foreach (self::FEATURES as $f) {
            $c[$f] = ($r['ai_feature_' . $f] ?? '1') === '1';
        }
        return $this->cache = $c;
    }

    /** @return array{api_key: string, model: string, base_url: string} */
    public function provider(string $p): array
    {
        return $this->all()['providers'][$p] ?? throw new AiException('Provedor de IA desconhecido.');
    }

    public function label(?string $p = null): string
    {
        return self::LABEL[$p ?? $this->all()['provider']] ?? 'IA';
    }

    /** Visão segura para a interface (sem as chaves). */
    public function publicView(): array
    {
        $c = $this->all();
        $providers = [];
        foreach ($c['providers'] as $p => $cfg) {
            $providers[$p] = [
                'label'        => self::LABEL[$p],
                'has_api_key'  => $cfg['api_key'] !== '',
                'api_key_hint' => $cfg['api_key'] !== '' ? '••••' . substr($cfg['api_key'], -4) : null,
                'model'        => $cfg['model'],
                'base_url'     => $cfg['base_url'],
                'default_url'  => self::DEFAULT_URL[$p],
                'models'       => self::MODELS[$p],
            ];
        }
        $key = $c['api_key'];
        unset($c['api_key'], $c['providers']);
        $c['providers']      = $providers;
        $c['provider_label'] = self::LABEL[$c['provider']];
        $c['has_api_key']    = $key !== '';
        $c['api_key_hint']   = $providers[$c['provider']]['api_key_hint'];
        $c['ready']          = $c['enabled'] && $key !== '';
        return $c;
    }

    /**
     * Aceita os campos gerais (enabled, provider, flags) e, por provedor, em `providers[<p>]`:
     * api_key, remove_api_key, model, base_url. Por compatibilidade, api_key/model/base_url/remove_api_key
     * na raiz valem para o provedor escolhido (ou o ativo).
     * @param array<string, mixed> $data
     */
    public function save(array $data): void
    {
        $map = [];
        $flags = ['enabled' => 'ai_enabled'];
        foreach (self::FEATURES as $f) {
            $flags[$f] = 'ai_feature_' . $f;
        }
        foreach ($flags as $in => $key) {
            if (array_key_exists($in, $data)) {
                $map[$key] = $data[$in] ? '1' : '0';
            }
        }
        if (isset($data['provider'])) {
            if (!in_array($data['provider'], self::PROVIDERS, true)) {
                throw new AiException('Provedor de IA inválido.');
            }
            $map['ai_provider'] = $data['provider'];
        }

        $per = is_array($data['providers'] ?? null) ? $data['providers'] : [];
        $target = $data['provider'] ?? $this->all()['provider'];
        $root = array_intersect_key($data, array_flip(['api_key', 'remove_api_key', 'model', 'base_url']));
        if ($root) {
            $per[$target] = $root + (is_array($per[$target] ?? null) ? $per[$target] : []);
        }

        foreach ($per as $p => $in) {
            if (!isset(self::PKEYS[$p]) || !is_array($in)) {
                throw new AiException('Provedor de IA inválido.');
            }
            $k = self::PKEYS[$p];
            if (isset($in['model'])) {
                $model = trim((string) $in['model']);
                if (!self::validModel($p, $model)) {
                    throw new AiException($p === 'gemini'
                        ? 'Modelo do Gemini inválido. Use um nome como gemini-3.5-flash.'
                        : 'Modelo inválido.');
                }
                $map[$k['model']] = $model;
            }
            if (array_key_exists('base_url', $in)) {
                $url = trim((string) $in['base_url']);
                if ($url !== '' && !preg_match('#^https://[^\s/]+#i', $url)) {
                    throw new AiException('O endereço da API deve começar com https://');
                }
                $map[$k['base_url']] = $url;
            }
            if (!empty($in['api_key'])) {
                $map[$k['api_key']] = $this->crypto->encrypt(trim((string) $in['api_key']));
            }
            if (!empty($in['remove_api_key'])) {
                $map[$k['api_key']] = '';
            }
        }

        $stmt = $this->connection->pdo()->prepare(
            "INSERT INTO system_settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP"
        );
        foreach ($map as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
        $this->cache = null;
    }

    /** DeepSeek: só os modelos conhecidos. Gemini: lista sugerida ou qualquer nome gemini-* (modelos novos). */
    private static function validModel(string $p, string $model): bool
    {
        if (in_array($model, self::MODELS[$p] ?? [], true)) {
            return true;
        }
        return $p === 'gemini' && (bool) preg_match('/^gemini-[a-z0-9][a-z0-9.\-]{1,60}$/', $model);
    }
}
