# Helpdesk API — Documentação do Projeto

## Visão geral

Sistema de helpdesk enterprise em PHP 8.2+ com framework MVC próprio (sem Laravel/Symfony).
Expõe uma REST API JSON em `/api/v1/`. Stack: PHP-FPM + Nginx + MySQL 8.0, orquestrado via Docker Compose.

```
http://localhost:8080   → API
```

## Subir o ambiente

```bash
docker compose up -d          # inicia todos os serviços
docker compose logs -f php    # acompanha logs do PHP
```

Banco de dados: após o primeiro `up`, aplicar migrações na ordem:

```bash
# dentro do container db ou via cliente externo (helpdesk:secret)
source database/schema.sql
source database/migrations/002_sla_export.sql
source database/migrations/003_system_settings.sql
source database/migrations/004_users_orgs_extended.sql
```

Seed de papéis/permissões:

```bash
docker compose exec php php database/seeders/RbacSeeder.php
```

## Estrutura de diretórios

```
app/
  Core/           # Router, Route, EventDispatcher, Database/Connection
  Http/           # Controllers, Middleware, Response, AuthContext
  Models/         # Value objects (sem ORM ativo)
  Repositories/   # Acesso a dados; Contracts/ contém as interfaces
  Services/       # Lógica de negócio (Auth, Sla, Automation, Email, …)
  Events/         # Objetos de evento (TicketCreated, etc.)
  Listeners/      # AuditTicketHistory, RunAutomations
  Jobs/           # CheckSlaBreachesJob
  Exceptions/     # Hierarquia de exceções
  Support/        # Validator
config/           # auth.php, database.php, sla.php
database/
  schema.sql                      # schema base completo
  migrations/00N_*.sql            # migrações incrementais
  seeders/RbacSeeder.php
routes/api.php                    # todas as rotas declaradas aqui
public/index.php                  # front controller
```

## Arquitetura

**Fluxo de requisição:**

```
nginx → public/index.php → Router::dispatch()
  → middleware (Auth → Role/Permission)
  → Controller
    → Service (lógica de negócio)
      → Repository (SQL via PDO)
    → EventDispatcher::dispatch()
      → Listener (AuditTicketHistory, RunAutomations)
  → Response::json()
```

**Padrões usados:**

- Repository pattern com interfaces em `Repositories/Contracts/`
- Service layer contém toda a lógica de negócio
- Events + Listeners (sem filas — execução síncrona)
- JWT para autenticação (`JwtService`)
- RBAC com roles e permissions (`RoleMiddleware` e `PermissionMiddleware`)
- `AuthContext` estático injeta o usuário autenticado durante a requisição

## Autenticação

- JWT Bearer token no header `Authorization: Bearer <token>`
- TTL configurável via `JWT_TTL` (padrão: 3600 s)
- `AuthContext::get()` retorna o usuário logado em qualquer camada
- Rotas públicas: `POST /auth/login`, `POST /auth/register`, `GET /organizations/public`

## Roles e permissões

| Role       | Acesso principal                              |
|------------|-----------------------------------------------|
| admin      | Tudo                                          |
| agent      | Tickets, relatórios                           |
| supervisor | Tickets, relatórios, logs de SLA              |
| manager    | Relatórios filtrados pela própria organização |
| client     | Abre e acompanha tickets da própria org       |

Permissões granulares: `tickets.view`, `tickets.create`, `tickets.edit`.

## Módulos principais

### Tickets

- CRUD completo em `/api/v1/tickets`
- Status: `open → pending → in_progress → resolved → closed`
- Prioridade: `low | medium | high | critical`
- Source: `web | email | api | whatsapp | phone`
- Tipo: `question | incident | problem | task`
- Avaliação (rating 1–5) via `POST /tickets/{id}/rate`
- Histórico de auditoria em `ticket_history` (evento, old_value, new_value)
- Custom fields e metadata armazenados como JSON

### SLA

Arquivo principal: `app/Services/Sla/SlaService.php`

- Políticas por organização (`sla_policies`)
- Dois deadlines: **FRT** (First Response Time) e **RT** (Resolution Time)
- Minutos por prioridade: `frt_low/medium/high/critical` e `rt_*`
- Suporte a horário comercial (`business_hours_only = 1`) via `BusinessHoursCalculator`
- Horário comercial configurável: `SLA_WORK_START`, `SLA_WORK_END`, `SLA_TIMEZONE` (env)
- Flags `sla_frt_breached` e `sla_rt_breached` no ticket

### Alertas de SLA

Arquivo principal: `app/Services/Sla/SlaAlertService.php`

Motor de alertas executado pelo `CheckSlaBreachesJob`:
1. **warning** — aviso pré-vencimento (N minutos antes)
2. **breached** — SLA violado
3. **critical** / **escalação** — X minutos após violação, notifica outro papel

Canais: notificação interna (banco) e e-mail.
Log de envios em `sla_notification_logs` (evita duplicatas).

### Automações

Arquivo principal: `app/Services/Automation/AutomationEngine.php`

Triggers (enum `trigger_event`):
`ticket_created | ticket_updated | status_changed | reply_added | time_elapsed | sla_frt_breached | sla_rt_breached`

Condições (campo + operador + valor) com `match_type = all | any`.

Ações disponíveis:
`set_status | set_priority | assign_agent | assign_team | add_tag | remove_tag | send_email | fire_webhook | add_private_note | close_ticket`

### Integração de E-mail

Arquivo principal: `app/Services/Email/EmailFetcherService.php`

- Suporte a IMAP e POP3 (SSL/TLS/sem TLS)
- Senha da conta criptografada com AES-256-CBC
- Parsing de MIME multipart (texto plano preferencial, HTML como fallback)
- Auto-criação de usuário ao receber e-mail de remetente desconhecido (`auto_create_user`)
- Endpoints: `POST /email-accounts/{id}/fetch`, `POST /email-accounts/fetch-all`

### Relatórios

Rotas: `GET /reports/overview|agents|organizations|sla`
Exportação PDF via dompdf: `GET /reports/{type}/export`

### Rastreamento de tempo

- `POST /tickets/{id}/time/start` — inicia timer
- `POST /tickets/{id}/time/stop` — para timer
- `GET  /tickets/{id}/time` — lista entradas

### Organizações

Campos brasileiros (migration 004): CNPJ, IE, IM, razão social, endereço completo.
SLA por organização: `GET/PUT /organizations/{id}/sla`

## Tabelas no banco

| Tabela                  | Propósito                                |
|-------------------------|------------------------------------------|
| users                   | Usuários do sistema                      |
| organizations           | Empresas clientes                        |
| user_organizations      | Vínculo usuário ↔ empresa (multi-org)    |
| roles / permissions     | RBAC                                     |
| role_permissions        | Vínculo role ↔ permission                |
| user_roles              | Vínculo usuário ↔ role                   |
| teams / team_members    | Equipes (schema pronto, sem API ainda)   |
| categories              | Categorias de ticket (sem API ainda)     |
| tags / ticket_tags      | Tags de ticket (sem API ainda)           |
| sla_policies            | Políticas de SLA                         |
| tickets                 | Chamados                                 |
| ticket_replies          | Respostas e notas internas               |
| ticket_history          | Auditoria de eventos do ticket           |
| attachments             | Anexos (local ou S3, sem API ainda)      |
| automations             | Regras de automação                      |
| automation_conditions   | Condições das automações                 |
| automation_actions      | Ações das automações                     |
| kb_categories / kb_articles | Base de conhecimento (sem API ainda) |
| webhooks                | Webhooks de saída (sem endpoint de CRUD) |
| api_tokens              | Tokens de API por usuário                |
| notifications           | Notificações internas                    |
| settings                | Configurações globais (key-value)        |
| sla_alert_profiles*     | Perfis de alerta de SLA                  |
| sla_notification_logs*  | Log de envios de alerta                  |

*Tabelas criadas em migration 002.

## Variáveis de ambiente

| Variável        | Padrão              | Descrição                    |
|-----------------|---------------------|------------------------------|
| APP_ENV         | development         | Ambiente                     |
| APP_DEBUG       | true                | Modo debug                   |
| DB_HOST         | db                  | Host MySQL                   |
| DB_DATABASE     | helpdesk            | Nome do banco                |
| DB_USERNAME     | helpdesk_user       | Usuário do banco             |
| DB_PASSWORD     | secret              | Senha do banco               |
| JWT_SECRET      | (obrigatório)       | Chave HMAC do JWT (256 bit)  |
| JWT_TTL         | 3600                | TTL do token em segundos     |
| SLA_WORK_START  | 9                   | Início do horário comercial  |
| SLA_WORK_END    | 18                  | Fim do horário comercial     |
| SLA_TIMEZONE    | UTC                 | Fuso do horário comercial    |

## O que ainda não tem API (tabelas prontas, sem rotas)

- **Teams / TeamMembers** — CRUD de equipes e membros
- **Categories** — categorias de ticket
- **Tags** — CRUD e vinculação de tags
- **Attachments** — upload de arquivos
- **Knowledge Base** — artigos e categorias de KB
- **Webhooks** — CRUD de webhooks de saída

## Bugs conhecidos / dívidas técnicas

1. `EmailFetcherService::resolveRequester()` insere colunas `first_name` / `last_name` que não existem no schema base → requer migration.
2. `Router::logError()` usa `error_log()` bruto — TODO substituir por logger PSR-3 (Monolog).
3. Automações com `action_type = fire_webhook` não disparam HTTP real ainda (ActionExecutor incompleto).
4. `CheckSlaBreachesJob` precisa ser chamado externamente (cron) — não há agendador interno.
5. Sem testes automatizados (PHPUnit configurado no composer mas sem suíte).
