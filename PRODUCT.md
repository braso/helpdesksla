# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Agentes e supervisores de suporte (staff BRASO / MSP de TI)**: passam o expediente na fila de chamados, atendendo várias empresas clientes ao mesmo tempo. Precisam triar por urgência de SLA, responder, registrar notas internas e horas trabalhadas. Uso intenso, diário, em desktop.
- **Administradores**: configuram empresas, contratos de SLA, alertas, contas de e-mail, SMTP e usuários. Uso esporádico e cuidadoso.
- **Gerentes de empresas clientes (manager)**: acompanham relatórios e o SLA da própria empresa.
- **Clientes (usuários das empresas atendidas)**: abrem poucos chamados por ano, acompanham respostas e avaliam o atendimento. Não conhecem jargão de service desk.

## Product Purpose

Helpdesk B2B para um provedor de suporte de TI que atende múltiplas empresas. Recebe chamados pela web e por e-mail (IMAP/POP3), controla prazos de primeira resposta (FRT) e resolução (RT) por contrato de cada empresa, alerta e escalona violações, registra tempo trabalhado e gera relatórios exportáveis (CSV/PDF/TXT). Sucesso: nenhum chamado estoura o SLA sem que alguém saiba antes; o cliente sabe sempre em que pé está o seu pedido.

## Positioning

- **SLA por empresa no centro**: cada cliente tem seu contrato (minutos por prioridade, horário comercial), com alertas de aviso/violação/escalonamento. O prazo é o eixo da operação, não um relatório escondido.
- **Feito para o mercado brasileiro**: pt-BR, dados fiscais (CNPJ, IE, IM, razão social, endereço completo), horário comercial local.
- **Tempo e prestação de contas**: cronômetro por chamado e por agente, relatórios exportáveis para mostrar ao cliente o que foi feito.

## Operating Context

- Fluxo do chamado: aberto → pendente → em andamento → resolvido → fechado; prioridades baixa/média/alta/crítica; origens web, e-mail, API, WhatsApp, telefone.
- Respostas públicas versus notas internas (só staff).
- Cliente avalia o atendimento (1–5) ao fechar.
- Job de SLA roda por cron a cada 5 min; notificações internas por polling.
- Escopo de visibilidade: staff vê tudo; manager e client veem apenas a própria empresa.

## Capabilities and Constraints

- Frontend é um único arquivo `public/app.html` (HTML + CSS + JS vanilla, sem build) consumindo a REST API `/api/v1/*` com JWT em `localStorage`. Manter sem framework e sem etapa de build.
- Backend PHP 8.2 com framework próprio; o redesign não deve exigir mudanças de API além das já necessárias (ex.: expor nomes no lugar de IDs).
- Um único app para todos os papéis, adaptado por papel (decisão confirmada): staff vê a fila operacional completa; cliente vê navegação enxuta e linguagem simples.
- Sem teams, categorias, tags, KB ou webhooks na API ainda — não desenhar telas que dependam deles como se existissem.

## Brand Commitments

- Produto da **BRASO** (BRASO Tecnologia, transformação digital, foco setor público, desde 2013). Fonte da identidade: `portalbraso/index.html`.
  - Wordmark "BRASO" em peso 800, tracking −0.04em; marca de 4 quadrados (grade 2×2).
  - Paleta institucional: navy `#0B1929`, ink `#06121F`, sky `#0070D9`, blue `#1A5FA8`, green `#009B62` / `#007A4D`, mist `#F4F8FC`, ice `#EBF4FD`, textos `#0F2035` / `#3D5670` / `#7A96AE`.
  - Tipografia de sistema (Segoe UI / system-ui).
- Existe um segundo conjunto de tokens em `hub/braso-hub-completo/.../theme/tokens.css` (primário `#2547db`, secundário teal `#0e9384`). **Decisão em aberto**: qual das duas é a referência oficial; até lá, o portal institucional prevalece.
- Tom visual desejado pelo usuário para este produto: claro e sóbrio (modo escuro opcional).

## Evidence on Hand

- Sem manual de marca formal no repositório; sem logo em arquivo além do SVG inline do portal.
- Sem métricas de uso, depoimentos ou dados reais de clientes. Não inventar números, clientes ou certificações.

## Product Principles

1. **O prazo é visível onde a decisão acontece**: SLA aparece na fila e no chamado, não só em relatórios.
2. **Nunca perder o trabalho do usuário**: buscar, filtrar ou mudar status não apaga foco, rascunho ou contexto.
3. **Pessoas, não IDs**: quem pediu, quem responde e qual empresa sempre por nome.
4. **Cada papel vê o essencial**: o cliente não enxerga ferramentas de staff; o agente não perde tempo com cerimônia.
5. **Acessível por padrão**: operável por teclado, contraste AA, estados anunciados.

## Accessibility & Inclusion

WCAG 2.1 AA como mínimo (contraste, foco visível, teclado completo, labels associados, `prefers-reduced-motion`). Interface em pt-BR.
