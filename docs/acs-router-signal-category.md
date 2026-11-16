# ACS - indicador Roteador separado de Sem leitura

## Objetivo

Roteadores Ethernet como "D-LINK Router" aparecem na tabela da Visao
geral com "Sem dado" no campo Sinal RX. Se nao possuem interface optica
propria, o RX realmente nao se aplica a esse equipamento.

Este recurso identifica ROTEADORES EXPLICITOS sem RX e mostra "Roteador"
na coluna de sinal. Uma ONU/ONT continua com seu indicador usual. A
falta de RX isoladamente jamais e usada para classificar roteador.

## Escopo

- Helper independente: lib/JrcEquipmentClassification.php
- API agregada autenticada e somente leitura do inventario ja coletado:
  api/jrc-router-signal-summary.php
- Script JS isolado: assets/js/jrc-router-signal.js
- Instalador seguro de overlay: scripts/deploy-acs-router-signal.py
- Testes unitarios, validacao Python e verificacao JS no CI.

A API nova nao consulta IXC nem GenieACS novamente; le somente o snapshot
MySQL existente, sem serial, MAC, IP, senha ou cliente na resposta.

Na tabela, uma ONU com RX valido (como -28.23 dBm) continua mostrando
seu valor. Um roteador comum identificado por "Router/Roteador" com
"Sem dado" passa a exibir "Roteador". Modelos GPON/ONT e modelos ONU
conhecidos nunca sao confundidos com roteadores comuns.

No quadro Sinal da frota, acrescenta-se uma linha "Roteador (ACS)",
identificada como contagem do inventario GenieACS. NAO se altera o
numero atual de "Sem leitura", pois o quadro personalizado pode usar
outra fonte e outro denominador (IXC). Subtrair dois conjuntos de
origens distintas geraria uma contagem incorreta. A separacao completa
das contagens em uma unica base devera ocorrer apos homologar o
JRC-Collector integrado IXC + TR-069.

## Instalacao depois de CI/merge

Primeiro simular em producao, sem escrita:

    cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && python3 scripts/deploy-acs-router-signal.py plan

Se imprimir PLAN_OK, implantar:

    cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-acs-router-signal.py apply

O instalador nao substitui o dashboard.php personalizado. Insere
somente um tag script local antes do fim da pagina, guarda backup
privado do arquivo e cria os novos arquivos. Falha fechado em
arquivos personalizados/conflitantes ou estruturas nao reconhecidas.

Atualize o navegador com Ctrl+F5 e confira os exemplos conhecidos:
D-LINK Router -> Roteador na coluna Sinal RX; FiberHome HG6245D
sem RX -> continua Sem dado; Huawei EG8145V5 com RX medido ->
continua a mostrar seu valor. Verificar tambem a nova linha do
indicador e que as demais contagens nao mudaram.

## Rollback

Se necessario, restaurar APENAS o dashboard.php do caminho de backup
informado em APLICADO_OK, validando o destino antes de copiar.
Os novos arquivos isolados ficam inativos sem o tag script e podem
permanecer. Nao modificar a flag jrc_dashboard_cache_enabled,
que continua desligada ate a homologacao do enriquecimento IXC.

## Preservacao

Nao altera APIS atuais, IXC, TR-069, calculos legados de sinal,
firmware, reboot, configuracoes da ONU, cron, MCP, nem o
coletor atual. O novo recurso apenas apresenta classes/contagem
em um escopo claramente identificado.


## ACS personalizado sem fechamento body no dashboard.php

A simulacao no servidor identificou o layout real: o dashboard.php
nao contem fechamento body, pois o arquivo views/layouts/footer.php
e incluido no final da pagina e fecha o HTML.

O instalador agora reconhece dois formatos:
1. Dashboard independente, com um unico fechamento body: injeta antes.
2. Dashboard com include/require isolado de views/layouts/footer.php:
   injeta antes do bloco PHP do footer, sem modificar o arquivo footer.

Se a estrutura nao for reconhecida, a instalacao e recusada.
Nao substitui dashboard.php pelo arquivo padrao do GitHub, nao altera
consulta IXC/TR-069. Testes novos garantem que a unica mudanca no
arquivo personalizado e o include JS, com backup do original.

## Ajuste confirmado no ACS: bloco PHP com scripts antes do footer

O servidor personalizado termina dashboard.php com um bloco PHP que
faz echo de scripts/estilos da integracao JRC_INVENTORY_SAFE_V3 e depois
chama include __DIR__ . '/views/layouts/footer.php'. Fora do bloco
existem ainda estilos personalizados. Nao ha </body> nesse arquivo.

O instalador anterior exigia um bloco PHP contendo somente o include
e por isso falhou no modo plan sem alterar o ACS. O novo instalador
procura a linha isolada e unica do include de footer, confirma que
ela esta dentro do PHP e insere APENAS um echo de script imediatamente
antes, preservando todos os echoes e qualquer HTML posterior.

Validacoes CI adicionais reproduzem exatamente essa estrutura: plano
sem mutacao, instalacao idempotente, copia original no backup e
modificacao byte a byte limitada ao unico echo inserido.

Passos apos merge:

    cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && python3 scripts/deploy-acs-router-signal.py plan

Somente apos PLAN_OK aplicar:

    cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-acs-router-signal.py apply
