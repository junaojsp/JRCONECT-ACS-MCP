# ACS: pilotos de caminhos ópticos já armazenados no GenieACS

## Motivação: auditoria de 16/11/2026

A auditoria de ONUs sem RX revelou que vários modelos fazem Inform
recentemente, mas não exibem RX no leitor rápido do ACS. Exemplos
dos resultados mostrados pelo operador:

| ONU | Total | Sem RX | Inform recente sem RX |
|---|---:|---:|---:|
| FiberHome HG6143D | 115 | 115 | 99 |
| FiberHome HG6245D | 76 | 76 | 61 |
| Huawei EG8145V5 | 75 | 73 | 68 |
| Huawei HG8546M | 60 | 60 | 56 |
| FiberHome HG6143D3 | 42 | 42 | 35 |

A auditoria anterior encontrou caminhos não cobertos pelo leitor
rápido, como:

- InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower
- InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower

Mas a maioria das ONUs não tinha caminhos RX reconhecidos no NBI.
Inform recente **não garante** leitura óptica atualizada.

## O que a inspeção piloto faz

- Somente CLI, lê /var/www/gacs/config/config.php, usa a credencial
  local existente e consulta GenieACS NBI em 127.0.0.1 por GET.
- Examina no máximo 1.800 registros em lotes de 60, até 30s por
  página. Não busca IXC nem altera banco, CPE, ACS ou presets.
- Seleciona **até duas ONUs por modelo**: uma com caminhos ópticos
  cadastrados e outra sem, sempre com Inform de até 15 min e RX
  ausente no leitor rápido.
- Modelos: HG6143D, HG6245D, EG8145V5, HG8546M.
- Exibe serial, firmware e os **nomes de caminhos** de RX/temperatura;
  para cada caminho mostra valor existente (NUMERICO/NAO_NUMERICO/VAZIO),
  idade do registro e se o leitor atual consegue interpretá-lo.
- Não imprime valor de sinal, senha, IP, PPPoE, SSID ou dados de cliente.
  Caminhos de autenticação/Wi-Fi são ignorados na varredura.

Essa inspeção permite decidir se falta atualizar o leitor rápido
(parâmetro encontrado, mas NAO_MAPEADO) ou se precisamos investigar
descoberta de parâmetros no GenieACS (SEM_CAMINHOS). Não afirma
incompatibilidade de firmware sem teste específico.

## Rodar após CI e merge

Esse script NÃO é um instalador; execute apenas no checkout:

    cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && php tests/jrc-onu-pilot-paths.test.php && php scripts/audit-acs-onu-optical-pilots.php

Resultado esperado: JRC_ONU_PILOT_PATHS_TESTS_OK, seguido por
JRC_PILOTOS_RX_AUDITORIA_OK, os quatro modelos, os caminhos
opticos e FIM_PILOTOS=OK.

Próxima etapa depois do resultado: escolher **uma ONU-piloto** por
fabricante, localizar parâmetros reais e timestamps; se os nomes
existirem, corrigir só o parser com PR, testes e opt-in. Se realmente
não estiverem no cadastro, estudar um preset de descoberta somente
nessa ONU-piloto, sem alterar a frota.

A PR #195 de classificação visual foi revertida e NÃO deve ser
reinstalada. Não alterar dashboard, IXC/TR-069, reboot, firmware,
cron ou flags em consequência desta auditoria.

## Etapa seguinte: confirmar escala real do RX e da temperatura

O teste piloto de 16/11/2026 confirmou:

- HG6245D: X_FH_GponInterfaceConfig.RXPower cadastrado, ultimo
  timestamp de aproximadamente 21.374 min, que e dado antigo.
- HG8546M: X_GponInterfaceConfig.RXPower e
  X_GponInterfaceConfig.TransceiverTemperature cadastrados, com
  timestamp aproximado de 84 min no instante da auditoria.
- HG6143D e EG8145V5: piloto selecionado sem parametros opticos
  cadastrados no NBI, exigindo avaliacao de descoberta por modelo.

Para validar a unidade original antes de corrigir o parser, o script
passa a mostrar BRUTO_OPTICO nos dois caminhos RX proprietarios e
no campo TransceiverTemperature, somente se o valor for numerico.
Nenhum outro parametro de assinante ou rede tem o valor exibido.
O texto BRUTO_OPTICO NAO SIGNIFICA dBm OU CELSIUS: a escala deve
ser conferida com outro dado conhecido e a idade da coleta.
Nao habilitar novas fontes de RX no dashboard enquanto nao houver
confirmacao de unidade, intervalo valido e frescor do parametro.

Reexecutar o mesmo comando de auditoria piloto apos merge desta PR:

    cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && php tests/jrc-onu-pilot-paths.test.php && php scripts/audit-acs-onu-optical-pilots.php

O procedimento segue 100% somente leitura e nao faz deploy.
