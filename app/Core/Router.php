<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\AuthenticationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Response;
use Throwable;

/**
 * Roteador HTTP com suporte a grupos aninhados e pipeline de middleware.
 *
 * Registro de rotas:
 *   $router->get('/path', fn() => ...)
 *   $router->post('/path', fn() => ...)->middleware(fn() => ...)
 *
 * Grupos (aninhamento ilimitado, herança de prefixo e middleware):
 *   $router->group('/api/v1', function(Router $r) { ... }, fn() => $auth->handle())
 *
 * Parâmetros dinâmicos (entregues como args nomeados ao handler):
 *   $router->get('/tickets/{id}', fn(string $id) => $ctrl->show((int) $id))
 *
 * Dispatch centraliza o tratamento de exceções — controllers não precisam
 * de try-catch para os casos comuns (Validation, Auth, NotFound, etc.).
 */
final class Router
{
    /** @var Route[] */
    private array $routes = [];

    // ─── Estado do grupo ativo (empilhado/restaurado em cada group()) ─────────
    private string $currentPrefix          = '';
    /** @var \Closure[] */
    private array  $currentGroupMiddleware = [];

    // ─── Registro de rotas ────────────────────────────────────────────────────

    public function get(string $uri, \Closure $handler): Route
    {
        return $this->add('GET', $uri, $handler);
    }

    public function post(string $uri, \Closure $handler): Route
    {
        return $this->add('POST', $uri, $handler);
    }

    public function put(string $uri, \Closure $handler): Route
    {
        return $this->add('PUT', $uri, $handler);
    }

    public function patch(string $uri, \Closure $handler): Route
    {
        return $this->add('PATCH', $uri, $handler);
    }

    public function delete(string $uri, \Closure $handler): Route
    {
        return $this->add('DELETE', $uri, $handler);
    }

    /**
     * Agrupa rotas sob um prefixo e middleware compartilhados.
     *
     * Grupos são aninhados — cada nível herda o prefixo e o middleware do pai:
     *
     *   $router->group('/api/v1', function(Router $r) use ($auth) {
     *       $r->group('/admin', function(Router $r) { ... }, fn() => $roleGuard->handle());
     *   }, fn() => $auth->handle());
     *
     * O middleware do pai sempre precede o do filho no pipeline.
     *
     * @param \Closure ...$middleware Middleware aplicado a todas as rotas do grupo
     */
    public function group(string $prefix, \Closure $definition, \Closure ...$middleware): void
    {
        $savedPrefix     = $this->currentPrefix;
        $savedMiddleware = $this->currentGroupMiddleware;

        $this->currentPrefix          = $savedPrefix . $prefix;
        $this->currentGroupMiddleware = array_merge($savedMiddleware, $middleware);

        $definition($this);

        // Restaura o estado do grupo pai — permite aninhamento correto
        $this->currentPrefix          = $savedPrefix;
        $this->currentGroupMiddleware = $savedMiddleware;
    }

    // ─── Despacho ─────────────────────────────────────────────────────────────

    /**
     * Resolve a rota para método + URI e executa o pipeline completo.
     *
     * Ordem de execução:
     *   1. Middleware do(s) grupo(s) (ordem de herança: avô → pai → filho)
     *   2. Middleware específico da rota
     *   3. Handler
     *
     * Exceções comuns são capturadas aqui e mapeadas para os HTTP status corretos,
     * dispensando try-catch repetitivo nos controllers.
     *
     * Responde 404 se nenhuma rota bater o URI.
     * Responde 405 (com header Allow) se o URI bater mas o método não.
     */
    public function dispatch(string $method, string $uri): never
    {
        $method         = strtoupper($method);
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $route->match($uri);

            if ($params === false) {
                continue;
            }

            $allowedMethods[] = $route->getMethod();

            if ($route->getMethod() !== $method) {
                continue;
            }

            // ── Match completo — executa o pipeline ───────────────────────────
            try {
                foreach ($route->getMiddlewares() as $mw) {
                    $mw();
                }

                // Parâmetros de rota expandidos como argumentos nomeados:
                //   /tickets/{id} → fn(string $id) => ...
                ($route->getHandler())(...$params);

            } catch (ValidationException $e) {
                Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);

            } catch (AuthenticationException $e) {
                Response::error($e->getMessage(), statusCode: 401);

            } catch (AuthorizationException $e) {
                Response::error($e->getMessage(), statusCode: 403);

            } catch (NotFoundException $e) {
                Response::error($e->getMessage(), statusCode: 404);

            } catch (Throwable $e) {
                $this->logError($e);
                Response::error('Ocorreu um erro interno. Tente novamente mais tarde.', statusCode: 500);
            }

            exit;
        }

        if (!empty($allowedMethods)) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            Response::error('Método HTTP não permitido para esta rota.', statusCode: 405);
        }

        Response::error('Rota não encontrada.', statusCode: 404);
    }

    // ─── Interno ──────────────────────────────────────────────────────────────

    private function add(string $method, string $uri, \Closure $handler): Route
    {
        $route = new Route(
            method:              strtoupper($method),
            pattern:             $this->currentPrefix . $uri,
            handler:             $handler,
            inheritedMiddleware: $this->currentGroupMiddleware,
        );

        $this->routes[] = $route;

        return $route;
    }

    private function logError(Throwable $e): void
    {
        // TODO: substituir por Logger PSR-3 (Monolog) quando implementado
        error_log(sprintf(
            '[%s] %s in %s:%d | trace: %s',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        ));
    }
}
