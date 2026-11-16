# ACS: abrir Visão geral rapidamente usando o snapshot existente

## Diagnóstico no código (não é medição de latência de produção)

- `dashboard.php` chama `/api/dashboard-stats.php`, `/api/uplink-stats.php` e
  `/api/recent-devices.php` na abertura e repete as três a cada 30 segundos.
- **Cada endpoint** chama `GenieACS::getDevices()` sem limite, consultando
  a frota completa e processando os equipamentos. O painel também faz consultas
  adicionais de localização dos dez mais recentes.
- O coletor CLI da PR #182 já foi instalado, e um snapshot com **749 ONUs**
  foi gerado e conferido pelo operador em 16/11/2026. A coleta periódica e a
  habilitação de leitura **ainda não foram confirmadas em produção**.

## Mudança mínima e reversível

- Introduz o sinalizador independente `jrc_dashboard_cache_enabled` na tabela
  `configurations`, desativado por padrão.
- Com a flag ativa e snapshot disponível, os três endpoints geram suas respostas
  a partir das linhas MySQL já coletadas, sem NBI GenieACS e sem consulta IXC.
- Mantém as mesmas chaves de JSON, inclusive `stats`, `data` e `devices`.
- Calcula situação online/offline conforme status registrado na última coleta;
  sinal RX usa dados já presentes (IXC preferencial, TR-069 como fallback), com
  zero e sentinelas descartados.
- Respostas incluem `cached_at`, `cache_age_seconds` e `cache_stale`. Um
  snapshot antigo não é uma visão em tempo real. A interface atual não apresenta
  essas etiquetas; validar idade pelo endpoint ou integrar aviso em PR separada.
- Sem snapshot, com a flag desligada ou com erro de leitura, mantém o comportamento
  legado, com leitura direta. **Não é um diagnóstico de acesso ao IXC.**
- Nenhuma mudança em `dashboard.php`, menu, CSS, firmware, scripts de ONUs ou
  MCPv2. O objetivo aqui é navegação, não enriquecer dados do IXC.

## Implantação

### 1. Fazer atualização e simulação segura

```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && python3 scripts/deploy-acs-overview-fast.py plan
```

Continue somente com `PLAN_OK` ou `JA_INSTALADO`. Não substitua o layout
personalizado de `/var/www/gacs` pelo conteúdo do GitHub.

### 2. Aplicar somente a API

```bash
cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-acs-overview-fast.py apply
```

O instalador verifica o PHP, salva backups privados e faz rollback em erro.
Ele não liga flags nem serviços. O painel continua no modo antigo.

### 3. Verificar o snapshot e configurar atualização periódica

O coletor foi executado manualmente e deu `COLETA_OK`. Antes de habilitar o
cache na Visão geral, agende `php /var/www/gacs/cron/collect-device-inventory.php`
em um serviço ou cron gerenciado sob a conta do serviço, **sem sobrepor outros
agendamentos**. Comece com 60 segundos, se uma execução completar com folga.
Não execute duas coletas concorrentes (há lock de processo).

Confirme pelo `/api/device-inventory-status.php` autenticado que o horário da
coleta avança. Compare contagens e disponibilidade com o GenieACS. Se o coletor
não estiver agendado, a Visão geral exibirá dados da última coleta manual.

### 4. Ativar somente cache da Visão geral

Usar o processo autorizado de administração MySQL:

```sql
INSERT INTO configurations (config_key, config_value)
VALUES ('jrc_dashboard_cache_enabled', '1')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
```

Essa flag é **independente** de `jrc_inventory_cache_enabled` (lista Dispositivos).
Pode ser ativada sem alterar a tela Dispositivos.

Verificar no navegador a resposta autenticada de `dashboard-stats.php`, com
`source=jrc_background_inventory`, e abrir/alternar entre Visão geral e
Dispositivos. Medir tempo com DevTools Network antes/depois. Conferir os totais,
o gráfico de sinal e os dez mais recentes.

### Rollback imediato

```sql
UPDATE configurations SET config_value='0'
WHERE config_key='jrc_dashboard_cache_enabled';
```

Não remove tabelas nem mexe no coletor. Os três endpoints retornam à consulta
legada no GenieACS, sem precisar restaurar arquivos.

## Fora do escopo

- Configurar IXC e corrigir sinal óptico (tratamento posterior e independente).
- Acelerar a tabela Dispositivos, que tem flag própria e JS personalizado.
- Otimizar as 10 consultas de localização do bloco "Recentes"; se a navegação
  continuar lenta após as 3 APIs deixarem de consultar o GenieACS, investigar
  esse lote e o cache-busting de CSS/JS em PR separada.
- Qualquer tarefa de atualização, firmware ou alteração nas ONUs.
