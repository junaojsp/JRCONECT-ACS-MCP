# ACS — separar Consultas e Relatórios

## Diagnóstico dos testes de 16/11/2026

O relatório de roteadores já lista 60 equipamentos com PPPoE, serial e
IP WAN, usando o inventário armazenado no ACS. A Consulta de sinal
é diferente: tenta ler a frota (752 equipamentos no print) e depende
da disponibilidade das APIs IXC e TR-069.

No teste, a consulta óptica informou que parte das requisições ao IXC
não respondeu. A interface apresentou contadores zero antes de
concluir. Zeros provisórios não comprovam que não há ONUs com sinal
fraco ou crítico.

## Nova organização

Ao clicar no menu lateral Relatórios, abre uma central com duas áreas:

- Consultas de diagnóstico: Consulta de sinal da frota é uma operação
  sob demanda, aberta explicitamente pelo usuário. Os filtros fraco,
  crítico, sem leitura e offline continuam iguais.
- Relatórios de dados coletados: Roteadores abre uma lista consolidada
  a partir do snapshot MySQL do inventário GenieACS. A central não
  varre a frota automaticamente.

A central não faz requisições IXC/GenieACS ao abrir e mostra um
aviso para não interpretar zeros de uma leitura incompleta como
diagnóstico definitivo.

O botão Voltar aos relatórios da lista de roteadores também passa
a apontar para a nova central. Os links diretos do dashboard para
filtros de sinal permanecem funcionando como antes.

## Arquivos

- reports-hub.php: nova central autenticada com as duas seções.
- views/layouts/header.php: no servidor, somente o href do item
  Relatórios passa de /view-logs.php para /reports-hub.php.
- router-report.php: somente o href do botão de retorno à central.
- scripts/deploy-acs-reports-hub.py plan|apply: instala a página e
  ajusta os dois links reconhecidos, com backup e rollback.
- tests/test_deploy_acs_reports_hub.py: valida navegação, segurança,
  idempotência e preservação dos filtros ópticos.

O header.php do GitHub difere do personalizado em produção.
NÃO copiar o header padrão do GitHub para /var/www/gacs.
O instalador encontra a navegação existente e altera somente o
href do único link Relatórios. Se não localizar esse link, falha
fechado e preserva os arquivos originais.

## Passos após testes e merge

Simular, sem alterar arquivos:

    cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && python3 scripts/deploy-acs-reports-hub.py plan

Esperado: PLAN_OK com reports-hub.php, views/layouts/header.php
e router-report.php. Se houver erro, não forçar a instalação.

Aplicar somente depois de PLAN_OK:

    cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-acs-reports-hub.py apply

Esperado: APLICADO_OK com caminho do backup em
/var/backups/jrc-acs-reports-hub.

Validar Ctrl+F5: menu Relatórios abre a central; o item Roteadores
abre a lista existente; Voltar retorna à central; Consulta de
sinal abre a tela óptica quando solicitada. Confirmar que
fraco/crítico/sem leitura ainda possuem resultados e filtros
idênticos aos anteriores.

## Limitação conhecida

Esta mudança organiza a navegação e esclarece a origem dos dados.
NÃO corrige a indisponibilidade parcial da API IXC, nem modifica os
estados internos Carregando/Parcial/Erro da consulta óptica.
A próxima correção dessas condições exige analisar o código
personalizado de produção da consulta de sinal: o arquivo
view-logs.php do repositório continua sendo o viewer de logs
legado e não corresponde ao painel óptico exibido no print.
Não inventar endpoints nem modificar consultas IXC sem esse código.

## Preservação

Não modifica view-logs.php, consultas IXC, TR-069, GenieACS,
firmware, reboot, WAN, Wi-Fi, comandos ONU, MCP, coletor, cron,
flags nem dashboard.php. Somente uma nova página e dois links
de navegação, com backup dos arquivos alterados.
