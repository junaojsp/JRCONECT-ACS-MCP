# ACS - Sem dado nao identifica o tipo de equipamento

## Diagnostico

Na Visao geral, quando falta potencia optica RX, ha tres situacoes:

1. **Roteador**: dispositivo comum que nao possui interface optica
   propria. Nao significa falha de leitura da ONU.
2. **ONU sem leitura**: ONU/ONT identificada que deveria oferecer RX,
   mas cujo sinal optico nao esta disponivel no IXC/TR-069.
3. **Tipo a confirmar**: sem RX e sem informacoes suficientes para
   afirmar que seja ONU ou roteador. Nao somar como falha de ONU.

Valor RX valido em dBm SEMPRE prevalece: ele fica como esta. Nao
classificar um equipamento como roteador apenas porque faltam dados.

## Arquivos

- lib/JrcEquipmentClassification.php: status separados, incluindo
  tipo_nao_identificado; ONU com roteamento integrado continua ONU.
- api/jrc-router-signal-summary.php: acrescenta optical_no_reading,
  optical_with_reading e unclassified_no_reading ao contrato existente
  (routers, confirmed_optical, unknown_type). Fonte: snapshot ACS.
- assets/js/jrc-router-signal.js: troca somente etiqueta no campo
  Sinal RX de roteador, ONU reconhecida ou tipo desconhecido, e mantem
  valor RX real e temperatura intactos. Nao altera a navegacao, os
  eventos de filtros nem o painel Sinal da frota.
- scripts/deploy-acs-no-rx-types.py: instalador opt-in com plan/apply,
  comparacao exata de versoes, backup, reversao e recusa de alteracoes
  personalizadas inesperadas.

O resumo original Sem leitura da Visao geral pode usar uma fonte IXC
diferente do snapshot ACS. Portanto, NAO subtrair Routers dessa
contagem e NAO substitui-la pelas novas contagens ate que as duas
origens sejam conciliadas pelo JRC-Collector.

## Homologacao apos testes/merge

Primeiro, somente inspecao:

    cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && python3 scripts/deploy-acs-no-rx-types.py plan

Se retornar PLAN_OK, aplicar em passo separado:

    cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-acs-no-rx-types.py apply

Depois Ctrl+F5 e validar D-LINK Router -> Roteador;
FiberHome HG6245D sem RX -> ONU sem leitura;
modelo desconhecido sem RX -> Tipo a confirmar (quando seguro);
ONU com RX em dBm -> valor preservado, inclusive sem temperatura.

Sem mudancas nos endpoints IXC/TR-069, firmware, reboot, comandos,
dashboard.php, view-logs.php, reports-hub.php, cron, MCP ou flags.
Backup mostrado no APLICADO_OK pode ser usado para reversao.

A PR #194 (separacao de Consultas e Relatorios) e independente desta.
O PLAN_OK de #194 ainda nao instalou a nova pagina.
