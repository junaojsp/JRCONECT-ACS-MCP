# JRC ONU Compatibility — comparação Huawei (somente leitura)

## Motivação

Na auditoria de 16/11/2026, uma Huawei HG8546M registrou
InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower no
levantamento geral, mas a consulta individual pelo serial da mesma
ONU não encontrou X_GponInterfaceConfig. Isso impede concluir
incompatibilidade de firmware. Precisamos comparar as DUAS origens
de leitura do GenieACS (GET paginado e GET filtrado) no MESMO momento.

Outra dificuldade é o leitor rápido do ACS: ele reconhece só RX em
VirtualParameters.RXPower, X_CT-COM_EponInterfaceConfig.RXPower e
Device.Optical.Interface.1.RxPower. O coletor também seleciona
paths fixos. Modelos ou firmwares podem publicar RX em outros ramos,
como X_GponInterfaceConfig ou X_FH_GponInterfaceConfig.

## Ferramenta nova

- scripts/compare-acs-huawei-tr069.php
- lib/JrcTr069ProfileCompare.php (motor generico para novos fabricantes)
- tests/jrc-tr069-profile-compare.test.php

Recebe o serial de uma ONU Huawei com problema via --serial=.
Busca somente GET no GenieACS NBI local, ate 1.800 equipamentos
paginação por ID, sem IXC. Compara:

1. ONU-alvo na resposta GET geral vs a mesma ONU no GET filtrado por serial.
   Exibe se o ID do equipamento e os caminhos/estados optical coincidem.
2. ONU-alvo contra outra Huawei cujo RX esteja efetivamente
   armazenado no GenieACS. A referencia e selecionada sem cadastrar
   previamente modelo/serial: prioriza RX NUMERICO COM TIMESTAMP <= 15min,
   depois mesmo modelo e firmware; caso nao haja RX recente, um
   historico e identificado como historico, nunca como "funcionando".
3. Caminhos RX/temperatura que existem em uma e nao na outra,
   estado NUMERICO/SEM_VALOR/NAO_NUMERICO, idade do parametro,
   modelo, firmware e indicador de reconhecimento do leitor atual.

A ferramenta não imprime valores brutos, PPPoE, Wi-Fi ou senhas; nao
instala codigo em producao, nao cria task TR-069, nao executa reboot,
nao modifica presets, banco, dashboard, coletor ou flags. Toda coleta
e feita por GET de registros existentes. O usuario so precisa
executar o script no checkout apos o merge.

## Comando apos os testes e o merge

    cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && php tests/jrc-tr069-profile-compare.test.php && php scripts/compare-acs-huawei-tr069.php --serial=485754436BCD129E

Resultados esperados:

- JRC_TR069_HUAWEI_COMPARE_TESTS_OK
- JRC_HUAWEI_TR069_COMPARACAO_OK
- ALVO_CONSULTA_DIRETA=CONCORDA ou DIVERGE
- REFERENCIA_TR069=RX_NUMERICO_RECENTE_CANDIDATO ou
  SOMENTE_RX_HISTORICO_NAO_CONSIDERAR_FUNCIONANDO
- MESMO_MODELO / MESMO_FIRMWARE / SOMENTE_REFERENCIA
- FIM_COMPARACAO=OK

Se nao houver Huawei com RX numerico armazenado no proprio
GenieACS, o script informa que nao ha comparador TR-069 confiavel.
Uma ONU que exibe sinal RX na dashboard via IXC nao e automaticamente
uma referencia de consulta TR-069.

## Projeto da biblioteca sem cadastro manual por modelo

1. Novo equipamento informa OUI, ProductClass, SoftwareVersion.
2. Motor percorre parametros cadastrados em ramos TR-069,
   considerando padroes genericos de nomes de RX e temperatura.
3. Forma um **perfil candidato por fingerprint OUI/modelo/firmware**:
   caminhos presentes, valor numerico, idade do parametro e padroes
   consistentes com outros CPEs da mesma familia.
4. **Nunca ativa automaticamente um caminho apenas porque o nome
   parece RX.** Homologacao exige confirmar unidade (dBm/escala
   proprietaria), intervalo, frescor e origem (TR-069 vs IXC/OLT).
5. Caminhos homologados podem ser aplicados automaticamente aos
   novos dispositivos semelhantes; se desconhecidos, o sistema
   registra "descoberta pendente" em vez de produzir dados falsos.
6. Presets de descoberta e refreshObject de novos caminhos devem
   ser pilotos separados, com limite de concorrencia e autorizacao:
   este script nao executa nenhuma descoberta ativa no CPE.

IMPORTANTE: nao reativar a PR #195; o codigo de classificacao foi
revertido em producao apos interromper o dashboard.
