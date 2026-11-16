# Auditoria de ONUs com dados ausentes no TR-069 — JR Conect ACS

## Escopo obrigatório

Esta auditoria analisa **somente ONUs/ONTs identificadas**. Roteadores
comuns como D-LINK Router nao entram nos grupos nem no detalhamento.
Modelos sem identidade confiavel de ONU tambem nao sao chamados de ONU
com problema; aparecem somente no total de equipamentos excluidos.

O objetivo e descobrir as ONUs que nao exibem sinal RX ou temperatura
no ACS e levantar uma causa tecnica provavel por modelo e firmware.

## O que a coleta verifica

O script consulta os registros ja salvos no GenieACS NBI local,
usando GET paginado. Nao faz refreshObject, nao envia tarefas TR-069
e nao realiza comandos no CPE. Tambem nao consulta o IXC.

Para cada ONU, verifica:

1. Fabricante, modelo e firmware (campos cadastrados no ACS).
2. Ultimo Inform TR-069: recente ate 15 min, intermediario ate 2h,
   ou ausente/antigo. Inform recente NAO garante leitura optica recente.
3. Sinal RX e temperatura ja reconhecidos pelo parser atual do ACS.
4. Caminhos RX e temperatura presentes na arvore de parametros TR-069,
   incluindo caminhos proprietarios nao usados pelo parser, apenas
   seus nomes, sem valores de credenciais e sem expor dados pessoais.
5. Se o parametro existe mas esta sem valor, se o caminho alternativo
   nao e usado pelo parser ou se nada foi descoberto no NBI.

A auditoria NAO afirma de antemao que um modelo seja incompativel.
Para comprovar incompatibilidade sera preciso testar o modelo/firmware,
verificar os presets de descoberta TR-069 e confrontar os valores
no IXC/OLT ou na interface administrativa do equipamento.

## Saida esperada

- TOTAL_CPE_EXAMINADOS, ONU_IDENTIFICADAS, EXCLUIDOS_NAO_ONU.
- ONUS_SEM_RX, ONUS_SEM_TEMPERATURA, ONUS_SEM_RX_E_TEMP,
  ONUS_SEM_RX_INFORM_RECENTE_15MIN.
- MODELOS_POR_PRIORIDADE: fabricante/modelo, firmware, quantidade
  afetada, estado do Inform, caminhos RX ja existentes no NBI.
- ONUS_INDIVIDUAIS_COM_COLETA_PENDENTE: ate 120 ONUs,
  priorizando as que comunicaram recentemente e ainda nao possuem RX.
  Mostra somente serial, fabricante/modelo, firmware e estados
  RX/TEMP/Inform. Nao apresenta nome de cliente, PPPoE, token,
  senha, WAN ou dados opticos brutos.

O numero de ONUS_ADICIONAIS_NAO_LISTADAS mostra se houver mais
de 120 casos. Para todos os casos, executar localmente redirecionando
a saida para TXT e/ou expandir limite em revisao controlada.

## Hipoteses retornadas

- CAMINHO_RX_NAO_DESCOBERTO: nao ha parametro RX no cadastro
  da ONU no NBI; revisar discovery/presets e suporte do firmware.
- CAMINHO_OU_FORMATO_RX_NAO_MAPEADO: ha parametro RX em caminho
  alternativo ou valor nao interpretado pelo parser atual; candidato
  a adaptar apenas a leitura, com testes por fabricante/modelo.
- PARAMETRO_RX_SEM_VALOR: parametro existe mas a leitura veio vazia;
  verificar se foi descoberto/atualizado pelo CPE.
- INFORM_NAO_RECENTE ou INFORM_ANTIGO_OU_AUSENTE: verificar
  comunicacao TR-069 antes de diagnosticar compatibilidade.
- RX_CADASTRADO_INFORM_ANTIGO: ha valor historico, nao confirma
  uma medicao recente.
- RX_PRESENTE_INFORM_RECENTE: sinal RX esta no ACS; se estiver
  na listagem de pendencias, o outro campo (temperatura) pode faltar.

## Uso seguro depois do merge

O script pode ser executado diretamente do checkout GitHub,
nao instala arquivos em /var/www/gacs e nao ativa flags:

    cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && php tests/jrc-onu-telemetry-audit.test.php && php scripts/audit-acs-onu-telemetry.php

O script consulta a configuracao de banco da instalacao atual e
aceita somente GenieACS com NBI em loopback 127.0.0.1. Para nao
sobrecarregar o servidor, consulta 60 CPEs por pagina, no maximo
1.800 registros, com timeout de 30s e limite total de paginas.
Se uma pagina falhar, nao imprime um falso diagnostico parcial.

## Importante

Nao instalar cron, nao modificar parametros ou atualizar firmware
por causa desta auditoria. A etapa seguinte sera homologar
parametros/presets nos MODELOS encontrados, com PR e teste de leitura
para uma ONU piloto antes de habilitar a coleta na frota inteira.

A PR #195 de classificacao visual continua revertida em producao
apos a regressao; NAO reaplicar aquele instalador.
