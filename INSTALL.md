# BRASO Helpdesk — instalação com Docker

## Requisitos
Docker e Docker Compose (Docker Desktop no Mac/Windows).

## Subir
```bash
unzip helpdesk-docker.zip -d helpdesk && cd helpdesk
docker compose up -d --build
```
> Atualizando uma instalação antiga? Rode `docker compose build && docker compose up -d` para incluir a extensão IMAP e o agendador.
Aguarde ~30 s na primeira vez (o MySQL cria o banco). Acesse **http://localhost:8080**.

- Usuário inicial: `admin@helpdesk.com` · senha `password` — **troque em Usuários após entrar**.
- O banco é criado automaticamente a partir de `database/docker-init/` (schema completo + papéis, permissões, SLA padrão e admin). Isso só acontece com o volume `db_data` vazio.

## Configuração
- Variáveis ficam em `docker-compose.yml`. Para produção, **troque `JWT_SECRET`** (`openssl rand -hex 32`) e as senhas do MySQL.
- Opcional: `cp .env.example .env` — o `.env` tem precedência sobre o compose.
- E-mail de saída: tela **Envio de e-mail (SMTP)** ou variáveis `MAIL_*`.

## Tarefas agendadas (automáticas)
O serviço `scheduler` do docker-compose roda o cron dentro do Docker — não é preciso configurar nada no servidor:

| A cada | Tarefa | Arquivo |
|---|---|---|
| 1 min | Envia a fila de e-mails do sistema (chamados, respostas, recuperação de senha, alertas de SLA) e tenta de novo os que falharam | `app/Jobs/SendQueuedEmailsJob.php` |
| 5 min | Busca e-mails das contas ativas e abre chamados | `app/Jobs/FetchEmailsJob.php` |
| 5 min | Marca prazos de SLA vencidos e coloca os alertas na fila | `app/Jobs/CheckSlaBreachesJob.php` |

Todo e-mail passa pela tabela `email_outbox` (`pending → sending → sent | failed`). A requisição já tenta enviar logo após responder; o que falhar o cron reenvia em 1, 5, 15 e 60 min e desiste na 5ª tentativa (erro em `last_error`). Bancos existentes: aplicar `database/migrations/008_email_outbox.sql` e `009_email_templates.sql`.

O texto de cada e-mail é editável em **Administração → Modelos de e-mail** (texto rico, variáveis `{{chamado.numero}}` etc., blocos `{{detalhes}}`, `{{mensagem}}` e `{{botao}}`, pré-visualização e envio de teste). Só as personalizações ficam na tabela `email_templates`; "Restaurar padrão" volta ao texto original.

Para mudar os horários, edite `.docker/cron/crontab` e rode `docker compose restart scheduler`.
Acompanhe as execuções com `docker compose logs -f scheduler`. Para rodar na hora:
```bash
docker compose exec scheduler /var/www/.docker/cron/run.sh app/Jobs/FetchEmailsJob.php
```

## Comandos úteis
```bash
docker compose logs -f php        # logs da aplicação
docker compose logs -f scheduler  # execuções agendadas (e-mail e SLA)
docker compose down               # parar
docker compose down -v            # parar e APAGAR o banco (volta ao estado inicial)
```

## Observações
- A pasta `vendor/` já vem no pacote (o volume `.:/var/www` substitui a do container). Se removê-la, rode `docker compose exec php composer install`.
- Os arquivos `database/schema.sql` e `database/migrations/*` são histórico; a instalação usa `database/docker-init/`.
