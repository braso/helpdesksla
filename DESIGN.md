---
name: BRASO Helpdesk
description: Fila de chamados B2B em que o prazo de SLA é a forma — programa modular BRASO, claro e sóbrio.
colors:
  navy: "#0B1929"
  navy-2: "#132840"
  navy-3: "#1D3756"
  on-navy: "#EEF4FA"
  on-navy-2: "#A9BCD0"
  ground: "#F4F8FC"
  surface: "#FFFFFF"
  surface-2: "#EEF3F9"
  surface-3: "#E2EAF3"
  line: "#DCE4EE"
  line-2: "#C5D1DF"
  text: "#0F2035"
  text-2: "#3D5670"
  text-3: "#5B7189"
  accent: "#0070D9"
  accent-h: "#005CB3"
  accent-soft: "#E6F1FC"
  accent-ink: "#0059AD"
  ok: "#007A4D"
  ok-fill: "#009B62"
  ok-soft: "#E3F4EC"
  warn: "#A84B00"
  warn-fill: "#E08A00"
  warn-soft: "#FDF1DF"
  bad: "#C0342B"
  bad-fill: "#D93A2F"
  bad-soft: "#FBE9E7"
  idle-fill: "#9AAABB"
  note: "#FFF7E8"
  note-line: "#F0D29C"
typography:
  wordmark:
    fontFamily: "'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif"
    fontSize: "17px"
    fontWeight: 800
    lineHeight: 1
    letterSpacing: "-0.04em"
  headline:
    fontFamily: "'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif"
    fontSize: "20px"
    fontWeight: 700
    lineHeight: 1.3
    letterSpacing: "-0.01em"
    fontFeature: "tnum"
  title:
    fontFamily: "'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif"
    fontSize: "16px"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "-0.01em"
    fontFeature: "tnum"
  body:
    fontFamily: "'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
    fontFeature: "tnum"
  body-sm:
    fontFamily: "'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif"
    fontSize: "13px"
    fontWeight: 500
    lineHeight: 1.5
    fontFeature: "tnum"
  label:
    fontFamily: "'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif"
    fontSize: "12px"
    fontWeight: 600
    lineHeight: 1.5
    fontFeature: "tnum"
  mono:
    fontFamily: "ui-monospace, 'SF Mono', Menlo, Consolas, monospace"
    fontSize: "11px"
    fontWeight: 400
rounded:
  xs: "4px"
  sm: "6px"
  lg: "10px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.surface}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "0 14px"
    height: "36px"
  button-primary-hover:
    backgroundColor: "{colors.accent-h}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
    padding: "0 14px"
    height: "36px"
  button-secondary-hover:
    backgroundColor: "{colors.surface-2}"
  button-quiet:
    backgroundColor: "transparent"
    textColor: "{colors.text-2}"
    rounded: "{rounded.sm}"
    padding: "0 14px"
    height: "36px"
  button-danger:
    backgroundColor: "{colors.bad}"
    textColor: "{colors.surface}"
    rounded: "{rounded.sm}"
    padding: "0 14px"
    height: "36px"
  button-sm:
    typography: "{typography.body-sm}"
    padding: "0 10px"
    height: "30px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "8px 11px"
    height: "36px"
  tab:
    textColor: "{colors.text-2}"
    typography: "{typography.body}"
    padding: "0 12px"
    height: "40px"
  tab-count-selected:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.surface}"
    rounded: "{rounded.xs}"
    height: "20px"
  pill-status:
    backgroundColor: "{colors.surface-2}"
    textColor: "{colors.text}"
    typography: "{typography.label}"
    rounded: "{rounded.xs}"
    padding: "0 8px"
    height: "22px"
  panel:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
  sidepanel-section:
    backgroundColor: "{colors.surface}"
    padding: "16px"
  queue-header:
    backgroundColor: "{colors.surface-2}"
    textColor: "{colors.text-2}"
    typography: "{typography.label}"
    padding: "0 12px"
    height: "38px"
  queue-row:
    backgroundColor: "{colors.surface}"
    typography: "{typography.body-sm}"
    padding: "0 12px"
    height: "56px"
  queue-row-selected:
    backgroundColor: "{colors.accent-soft}"
  nav-side:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.on-navy}"
    width: "232px"
  nav-item:
    textColor: "{colors.on-navy-2}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "0 10px"
    height: "36px"
  nav-item-hover:
    backgroundColor: "{colors.navy-2}"
    textColor: "{colors.on-navy}"
  nav-item-active:
    backgroundColor: "{colors.navy-3}"
    textColor: "{colors.surface}"
  topbar:
    backgroundColor: "{colors.surface}"
    padding: "0 24px"
    height: "56px"
  timer-chip:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.surface}"
    typography: "{typography.body-sm}"
    rounded: "{rounded.sm}"
    height: "32px"
  message:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.lg}"
  message-note:
    backgroundColor: "{colors.note}"
  modal:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: "24px"
    width: "520px"
  toast:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.surface}"
    typography: "{typography.body-sm}"
    rounded: "{rounded.lg}"
    padding: "10px 8px 10px 14px"
  toast-error:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
---

# Design System: BRASO Helpdesk

## Overview

**Creative North Star: "O Prazo é a Forma"**

O sistema é um programa de identidade modular brasileiro aplicado a uma ferramenta de trabalho: campos de cor chapados, régua de 1px separando superfícies brancas sobre um fundo mist, e uma lateral navy que ancora a marca BRASO. A grade 2×2 do logo vira o módulo de SLA: cada chamado carrega quatro quadrados que se preenchem conforme o prazo é consumido, e a fila se ordena por vencimento. O prazo não é um relatório escondido; é a forma que o olho lê primeiro.

A densidade é de mesa de operação: linhas de 56px, tipografia de sistema em cinco tamanhos curtos, algarismos tabulares em toda a interface. A cor é escassa e semântica. O azul sky aparece apenas onde há ação, seleção ou foco; verde, âmbar e vermelho aparecem apenas para estado de prazo e para retorno de sucesso/erro. Status e prioridade usam tinta neutra e se distinguem pela forma do marcador, nunca por um arco-íris de badges.

O movimento é discreto e funcional: transições de 180ms, linhas que deslizam até a nova posição quando a fila se reordena (sem salto) e um realce quente que permanece até a mudança ser vista. Tudo respeita `prefers-reduced-motion`. O sistema é claro; o tema escuro não está implementado, mas os tokens estão organizados por papel (superfície, texto, linha, ação, estado) para que um tema escuro seja apenas a redefinição das variáveis em `:root`.

**Key Characteristics:**
- Lateral navy fixa de 232px, topo branco de 56px, conteúdo sobre fundo mist.
- Superfícies brancas planas com régua de 1px; sombra só em camadas sobrepostas.
- Módulo de SLA 2×2 como assinatura, repetido na fila, no detalhe, nos vazios e na legenda.
- Sky exclusivo para ação/seleção/foco; verde/âmbar/vermelho exclusivos para estado.
- Estado codificado por forma além de cor (preenchimento do módulo, marcador do status, barras da prioridade, traço de vencido).
- Escala tipográfica curta (12/13/14/16/20px) com algarismos tabulares.

## Colors

Paleta institucional BRASO em estratégia contida: neutros frios azulados fazem quase todo o trabalho, um único azul de ação e três cores de estado reservadas.

### Primary
- **Azul Sky BRASO** (`accent`): botão primário, aba selecionada (sublinhado de 2px), caixas e rádios marcados, borda de campo em foco, contorno de foco de 2px, etapa atual do cliente, marcador de não lida. Hover escurece para **Sky Profundo** (`accent-h`).
- **Sky Tinta** (`accent-ink`): links e botões-link em texto, onde o sky puro não atinge contraste AA sobre branco.
- **Sky Névoa** (`accent-soft`): fundo de linha selecionada, opção escolhida, área de soltar arquivo ativa, banner informativo.

### Secondary
- **Navy BRASO** (`navy`): lateral, painel da tela de entrada, cronômetro global, barra de ações em lote, contagem da aba selecionada, toast padrão, avatar de staff, etapas concluídas do cliente.
- **Navy Elevado** (`navy-2`) e **Navy Ativo** (`navy-3`): hover e item atual da navegação lateral, divisões dentro do navy.
- **Gelo sobre Navy** (`on-navy`) e **Névoa sobre Navy** (`on-navy-2`): texto principal e secundário sobre navy.

### Tertiary (somente estado)
Cada estado tem três papéis: texto (contraste AA), preenchimento (módulo, marcadores, barras) e fundo suave.
- **Verde Prazo** (`ok` / `ok-fill` / `ok-soft`): SLA dentro do prazo (até 75% consumido), concluído no prazo, toast de sucesso, botão de confirmação suave.
- **Âmbar Alerta** (`warn` / `warn-fill` / `warn-soft`): SLA com 75% ou mais consumido; selo de aviso nos perfis de alerta.
- **Vermelho Vencido** (`bad` / `bad-fill` / `bad-soft`): SLA vencido ou concluído fora do prazo, mensagens de erro, ação destrutiva, contador de notificações.

### Neutral
- **Mist** (`ground`): fundo de página atrás de tudo.
- **Branco** (`surface`): painéis, tabela, mensagens, campos, modais.
- **Mist Firme** (`surface-2`) e **Mist Fundo** (`surface-3`): cabeçalho da tabela, fundo de pílula, hover de botão secundário, contagem de aba não selecionada, campos desabilitados.
- **Régua** (`line`): divisórias de 1px entre linhas, seções e painéis. **Régua Firme** (`line-2`): borda de campos, botões secundários e contornos vazios do módulo de SLA.
- **Tinta** (`text`), **Tinta Média** (`text-2`), **Tinta Leve** (`text-3`): texto primário, secundário e metadados/placeholders.
- **Cinza Ocioso** (`idle-fill`): tom neutro de gráfico para a menor categoria nos relatórios.
- **Papel de Nota** (`note`) com **Borda de Nota** (`note-line`): material próprio das notas internas (mensagem e compositor), distinto de qualquer estado.

### Named Rules
**The Sky Means Act Rule.** O sky só aparece onde o usuário pode agir, onde algo está selecionado ou onde está o foco. A marca 2×2 do cabeçalho é a única exceção de identidade.

**The Reserved State Rule.** Verde, âmbar e vermelho pertencem ao prazo (módulo e texto do prazo) e ao retorno de sucesso/erro. Status do chamado e prioridade nunca recebem cor de estado.

**The Neutral Ink Rule.** Pílulas de status e marcadores de prioridade são tinta neutra (`text`, `text-2`, `text-3`) sobre `surface-2`; o nível é lido pela forma.

## Typography

**Display Font:** nenhuma; o sistema não tem nível display.
**Body Font:** pilha de sistema (`Segoe UI`, `system-ui`, `-apple-system`, `Helvetica Neue`, Arial) — compromisso de marca BRASO.
**Label/Mono Font:** `ui-monospace` / SF Mono / Menlo / Consolas, apenas em teclas de atalho (`kbd`).

**Character:** Uma única família de sistema em pesos 500/600/700 (e 800 só no wordmark), lida como ferramenta e não como peça editorial. Algarismos tabulares em toda a interface (`font-variant-numeric: tabular-nums` no `body`) para que prazos, números de chamado e cronômetros alinhem em coluna.

### Hierarchy
- **Wordmark** (800, 17px, line-height 1, tracking −0.04em): apenas "BRASO" junto à marca 2×2. Fora da escala de propósito; é ativo de marca.
- **Headline** (700, 20px, 1.3, tracking −0.01em): título de página, título do chamado, título da tela de entrada, valores de indicadores e relógio do cronômetro.
- **Title** (700, 16px, 1.25): título do topo, título de modal, título de estado vazio, prazo grande no painel lateral.
- **Body** (400, 14px, 1.5): texto corrido, campos, botões (600), abas (600), assunto na fila (600), corpo das mensagens (line-height 1.6, máx. 80ch).
- **Body-sm** (13px, pesos 500/600): células da tabela, texto do prazo (600), rótulos de campo (600), títulos das seções laterais (700), toasts.
- **Label** (600, 12px): cabeçalhos de coluna, pílulas, contagens, rótulos de grupo da navegação, metadados e prévia (400). Sempre em caixa normal, sem tracking.

### Named Rules
**The Five Sizes Rule.** A interface usa apenas 12, 13, 14, 16 e 20px (`--fs-1` a `--fs-5`). Hierarquia nova se resolve com peso e tinta, não com um sexto tamanho.

**The Tabular Rule.** Todo número é tabular. Não desligue `tabular-nums` em nenhum componente.

## Layout

Casca em grade de duas colunas: lateral navy de 232px (`--side-w`) fixa em altura total e coluna principal fluida. O topo branco tem 56px (`--top-h`), é fixo e separado por régua; o conteúdo tem padding de 24px. O ritmo de espaçamento é 4/8/12/16/24px, com 10px e 14px como ajustes ópticos internos de componentes.

A fila é uma tabela densa de layout fixo (mínimo 880px) com coluna de prazo primeiro (174px), linhas de 56px e cabeçalho de 38px. O detalhe do chamado é uma grade de conteúdo + coluna lateral de 320px fixa ao rolar, com 24px entre colunas.

Responsivo por retirada progressiva de colunas: abaixo de 1560px o número do chamado vai para baixo do assunto; abaixo de 1280px some a empresa; abaixo de 1060px some "atualizado" e o detalhe vira coluna única com o resumo antes do fio; abaixo de 900px a lateral vira gaveta (até 280px) com scrim navy a 42%, o padding cai para 12–16px e os relatórios ficam em coluna única; abaixo de 720px cada linha da fila vira um cartão em grade (assunto / prazo + status / responsável + prioridade / número), modais sobem da base e campos usam 16px para evitar zoom no iOS.

## Elevation & Depth

O sistema é plano. A profundidade vem de camada tonal (mist → branco → mist firme) e da régua de 1px, não de sombra. Uma única sombra existe e só em camadas que flutuam sobre o conteúdo.

### Shadow Vocabulary
- **Pop** (`box-shadow: 0 10px 28px rgba(11,25,41,.16), 0 2px 6px rgba(11,25,41,.08)`): modal, painel de notificações, menu suspenso, toast e gaveta lateral móvel.

`box-shadow` com `inset` também é usado como desenho de traço (contorno das células vazias do módulo, marcador vazio da pílula) e o anel de foco dos campos (`0 0 0 3px rgba(0,112,217,.18)`); esses usos são forma e foco, não elevação.

### Named Rules
**The Only Overlays Float Rule.** Painéis, cartões, linhas e mensagens nunca recebem sombra; se algo precisa se destacar em repouso, use borda `line-2` ou fundo tonal.

## Shapes

Cantos discretos e retangulares, em ecos da grade 2×2: 6px (`--r`) para controles (botões, campos, itens de navegação, avatares, cronômetro) e 10px (`--r-lg`) para contêineres (painéis, mensagens, modais, toasts, cartões). Elementos miúdos usam 4px (pílulas, contagens, `kbd`) e os quadrados do módulo usam 1.5–2.5px. Marcadores são quadrados, nunca círculos: módulo de SLA, marcador de status, ponto de não lida, ponto do toast, pulso do cronômetro, etapas do cliente. O único círculo do sistema é o spinner.

Gradientes lineares de parada dura são usados nativamente para desenhar forma (meio-preenchimento do marcador de status, barras de prioridade, faixa navy da casca, brilho do esqueleto); gradiente como modulação de luz ou cor não existe.

## Components

### SLA Module (assinatura)
Quatro quadrados em grade 2×2; cada célula preenchida representa ¼ do prazo consumido (mínimo 1 enquanto aberto; 4 quando vencido).
- **Tamanhos:** padrão 6px por célula com 2px de vão (fila); `.md` 9px/2px; `.lg` 12px/3px (painel lateral e vazios).
- **Estados:** `ok` (preenchimento verde), `warn` (âmbar, a partir de 75%), `bad` (vermelho, vencido, com traço diagonal de 1.5px — 2px no `.lg` — a −45°), `done` (contorno verde, concluído no prazo), `late` (contorno vermelho com traço, concluído fora do prazo), `none` (contorno régua a 70%, sem SLA).
- **Par textual:** sempre acompanhado do texto do prazo (13px/600 na cor de estado: "Vence em 2h 10min", "Vencido há 3d") e de um sub-rótulo de 12px ("1ª resposta" / "Resolução"). Concluído e sem SLA usam tinta neutra em peso 500. Tem `role="img"` com `aria-label` descritivo.

### Buttons
- **Shape:** cantos suaves (6px), altura 36px, padding 0 14px, 14px/600, ícone de 16px com 7px de vão.
- **Primary:** sky com texto branco; hover em sky profundo.
- **Hover / Focus:** transição de fundo/borda/cor em 180ms; pressão desloca 1px para baixo; foco visível por contorno sky de 2px com afastamento de 2px; desabilitado a 50% de opacidade.
- **Secondary:** branco com borda `line-2`; hover em `surface-2`. **Quiet:** transparente em `text-2`; hover em `surface-2`. **Danger:** vermelho cheio; **red outline** (branco, texto vermelho) para ação destrutiva secundária; **green soft** para confirmação. **Small:** 30px, 13px. **Icon:** quadrado de 36 ou 30px.

### Chips (status e prioridade)
- **Status (pílula):** 22px, 4px de canto, 12px/600, fundo `surface-2`, tinta neutra, marcador quadrado de 8px desenhado por traço: aberto = vazio, pendente = metade inferior cheia, em andamento = metade esquerda cheia, resolvido = cheio, fechado = cheio a 60% em pílula transparente com contorno `line`.
- **Prioridade:** sem caixa; 13px em `text-2` com três barras crescentes (40/70/100%) — baixa enche 1, média 2, alta 3 (texto `text` 600), crítica 3 + barra cheia extra (texto 700).

### Cards / Containers
- **Corner Style:** 10px.
- **Background:** branco sobre mist.
- **Shadow Strategy:** nenhuma (ver Elevation & Depth).
- **Border:** 1px `line`.
- **Internal Padding:** 16px nas seções do painel lateral (separadas por régua), 16–20px em cartões de relatório e indicadores.

### Inputs / Fields
- **Style:** branco, borda 1px `line-2`, cantos 6px, padding 8px 11px, 14px; rótulo acima em 13px/600 `text-2`; dica em 12px `text-3`. Selects com seta SVG própria.
- **Focus:** borda sky + anel de 3px sky a 18%; cursor de texto sky.
- **Error / Disabled:** erro em bloco `bad-soft` com texto `bad`; desabilitado em `surface-2` com `text-3`.

### Navigation
- **Lateral:** navy, cabeçalho de 56px com marca (quadrado sky de 26px com grade 2×2 branca + wordmark + "Helpdesk" em 12px). Itens de 36px, 14px/500 em `on-navy-2`, ícone de 16px; hover `navy-2`; atual `navy-3` com texto branco e `aria-current="page"`; contagem à direita em 12px/600. Rótulos de grupo em 12px/600, caixa normal. Rodapé com usuário e ações em 32px. Foco em sky claro (#66B2FF) para contraste sobre navy.
- **Abas:** 40px, 14px/600 em `text-2`; selecionada em `text` com sublinhado sky de 2px e contagem em quadro navy; não selecionada com contagem em `surface-3`.
- **Mobile:** lateral vira gaveta deslizante (240ms) com botão de menu no topo.

### Queue Table
- Cabeçalho `surface-2` de 38px, rótulos 12px/600, ordenação por botão com seta visível só na coluna ativa.
- Linhas de 56px separadas por régua; assunto 14px/600 com prévia de 12px em `text-3` que escurece para `text-2` no hover/foco da linha.
- Hover em mist claríssimo; foco de linha por contorno sky interno de 2px; selecionada em `accent-soft`; **linha alterada** em realce quente (#FFF9EC) até receber hover ou foco.
- Reordenação FLIP: linhas deslizam da posição antiga em 260ms com `cubic-bezier(.2,.7,.2,1)`, desligado sob movimento reduzido.
- Responsável ausente em itálico `text-3`.

### Thread & Composer
- Mensagem: branco, borda `line`, cantos 10px; cabeçalho com avatar de 30px (quadrado de 6px), nome 14px/600, papel 12px/600, hora à direita; corpo recuado a 56px, 14px/1.6, máx. 80ch.
- Nota interna: papel de nota com borda de nota e rótulo em `warn`. Evento do sistema: `surface-2` com borda tracejada.
- Compositor: contêiner de 10px com abas de 34px (resposta / nota), área sem borda, rodapé com dica de atalho em `kbd` e botão primário à direita; no modo nota o compositor inteiro assume o papel de nota.

### Client Stages
Quatro etapas (Recebido → Em atendimento → Resolvido → Encerrado) em painel branco: quadrado de 14px por etapa ligado por trilho de 2px; concluída em navy, atual em sky com halo `accent-soft` de 3px, futura em contorno `line-2`. Previsão em linguagem simples logo abaixo.

### Timer Chip
Chip navy de 32px no topo enquanto há cronômetro ativo: pulso quadrado de 8px em sky claro (1.6s), tempo em 13px/600 tabular, botão de parar embutido.

### Modal & Toast
- **Modal:** overlay navy a 42%, caixa branca de 520px, cantos 10px, padding 24px, sombra pop, entrada com subida de 8px em 220ms; rodapé com régua e ações à direita. Abaixo de 720px vira folha inferior.
- **Toast:** navy com texto branco, cantos 10px, marcador quadrado de 8px (verde sucesso, sky claro informação); erro inverte para branco com borda vermelha suave e marcador vermelho. Entrada em 200ms; no mobile ocupa a largura.

### Icons
SVG inline de 16px (14px na variante pequena), traço `currentColor` de 1.6, pontas e junções arredondadas, sem preenchimento; sempre `aria-hidden`.

## Do's and Don'ts

### Do:
- **Do** mostrar o módulo de SLA 2×2 junto do texto do prazo sempre que um chamado aparece em lista ou detalhe.
- **Do** codificar estado por forma além de cor: células preenchidas, marcador do status, barras da prioridade, traço diagonal de vencido.
- **Do** usar sky (`accent`) apenas em ação, seleção e foco; links em `accent-ink`.
- **Do** separar superfícies com régua de 1px (`line`) e camada tonal; cantos de 6px em controles e 10px em contêineres.
- **Do** manter a escala em 12/13/14/16/20px com algarismos tabulares e pesos 500/600/700.
- **Do** animar em 180ms com `cubic-bezier(.2,.7,.2,1)` e reordenar a fila por FLIP em 260ms, sempre respeitando `prefers-reduced-motion`.
- **Do** criar novas cores por redefinição dos tokens de papel em `:root`, para que o futuro tema escuro funcione sem tocar em componentes.

### Don't:
- **Don't** colorir status ou prioridade com verde/âmbar/vermelho ou com matizes próprios; isso é o inbox de badges coloridas que o sistema recusa.
- **Don't** usar verde, âmbar ou vermelho fora de estado de prazo e retorno de sucesso/erro.
- **Don't** introduzir índigo, roxo ou um segundo azul de ação.
- **Don't** aplicar sombra em painéis, linhas, cartões ou mensagens; a sombra pop é só para camadas sobrepostas.
- **Don't** usar gradiente como modulação de luz ou cor; gradiente de parada dura é permitido apenas para desenhar forma.
- **Don't** usar os apelidos legados (`--red`, `--green`, `--orange`, `--blue`, `--primary`, `--muted`, `--muted2`, `--surface2`, `--border`) em telas novas; eles existem só para as telas administrativas herdadas e não fazem parte do sistema.
- **Don't** trocar marcadores quadrados por círculos.
