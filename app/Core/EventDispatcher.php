<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Despachador de eventos síncrono inspirado no PSR-14.
 *
 * Permite desacoplar produtores (Services) de consumidores (Listeners)
 * sem dependência direta: o Service despacha um evento imutável e
 * não sabe — nem precisa saber — quais Listeners vão reagir.
 *
 * Uso:
 *   $dispatcher->listen(TicketCreatedEvent::class, fn($e) => $listener->onTicketCreated($e));
 *   $dispatcher->dispatch(new TicketCreatedEvent($ticket, $actorId));
 *
 * Em produção, Listeners pesados (email, webhook) devem ser enfileirados
 * em vez de executados sincronamente — substituir o $listener->method() por
 * um $queue->push(new Job(...)) dentro da closure.
 */
final class EventDispatcher
{
    /** @var array<class-string, callable[]> */
    private array $listeners = [];

    /**
     * @param class-string $eventClass Classe do evento a escutar
     * @param callable     $listener   Função chamada com o evento como argumento
     */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * Despacha um evento para todos os listeners registrados para sua classe.
     * Listeners são executados na ordem de registro.
     */
    public function dispatch(object $event): void
    {
        $class = $event::class;

        foreach ($this->listeners[$class] ?? [] as $listener) {
            $listener($event);
        }
    }
}
