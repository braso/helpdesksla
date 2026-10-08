-- Migration 007 — SLA padrão alinhado à média de mercado (suporte de TI / MSP, plano 8x5)
--
-- Horário comercial: 1 dia útil = 9 h (09:00–18:00) = 540 min úteis.
--   Prioridade   1ª resposta        Resolução
--   Crítica      30 min             4 h úteis
--   Alta         1 h                1 dia útil (9 h)
--   Média        4 h                2 dias úteis (18 h)
--   Baixa        1 dia útil (9 h)   5 dias úteis (45 h)
--
SET NAMES utf8mb4;

-- Só cria a política padrão se ainda não existir (não sobrescreve ajustes feitos à mão).

INSERT INTO sla_policies (name, description, is_default,
                          frt_low, frt_medium, frt_high, frt_critical,
                          rt_low, rt_medium, rt_high, rt_critical, business_hours_only)
SELECT 'SLA padrão (mercado 8x5)', 'Média de mercado para suporte de TI em horário comercial. Aplicado a empresas sem contrato próprio.', 1,
       540, 240, 60, 30,
       2700, 1080, 540, 240, 1
WHERE NOT EXISTS (SELECT 1 FROM sla_policies WHERE is_default = 1);
