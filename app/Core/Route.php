<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Representa uma rota registrada com seu pattern, handler e pipeline de middleware.
 *
 * Imutável após a construção — o método middleware() retorna $this apenas para
 * encadeamento fluente na definição de rotas, mas o estado já foi inicializado
 * com o middleware herdado do grupo pai.
 */
final class Route
{
    /** @var \Closure[] Pipeline completo: [grupo_pai..., rota_específico...] */
    private array $middlewares;

    /**
     * @param string     $method              Método HTTP em maiúsculas
     * @param string     $pattern             URI com placeholders: /tickets/{id}
     * @param \Closure   $handler             Callable que processa a requisição
     * @param \Closure[] $inheritedMiddleware Middleware herdado do(s) grupo(s) pai(s)
     */
    public function __construct(
        private readonly string   $method,
        private readonly string   $pattern,
        private readonly \Closure $handler,
        array $inheritedMiddleware = [],
    ) {
        $this->middlewares = $inheritedMiddleware;
    }

    /**
     * Adiciona middleware específico desta rota, após o middleware de grupo.
     * Retorna $this para encadeamento fluente:
     *
     *   $router->post('/tickets', fn() => ...)->middleware(fn() => ...);
     */
    public function middleware(\Closure ...$middleware): static
    {
        array_push($this->middlewares, ...$middleware);

        return $this;
    }

    /**
     * Tenta fazer match do URI contra o pattern desta rota.
     *
     * Converte placeholders {param} em grupos nomeados de regex:
     *   /tickets/{id}  →  #^/tickets/(?P<id>[^/]+)$#
     *
     * @return array<string, string>|false Parâmetros extraídos ou false se não houver match
     */
    public function match(string $uri): array|false
    {
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $this->pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) {
            return false;
        }

        // Filtra apenas capturas nomeadas — descarta os índices numéricos do preg_match
        return array_filter($matches, static fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);
    }

    public function getMethod(): string { return $this->method; }

    public function getPattern(): string { return $this->pattern; }

    /** @return \Closure[] */
    public function getMiddlewares(): array { return $this->middlewares; }

    public function getHandler(): \Closure { return $this->handler; }
}
