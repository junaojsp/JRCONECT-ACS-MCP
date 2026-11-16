# Migração de telemetria para SNMP — etapa de coleta completa

O piloto de três ONUs respondeu em 134 ms somados. Esse tempo NÃO é estimativa
validada para a frota inteira. Foram observados os seriais HWTC8feedba7,
GLGAbc08fad0 e DLNK007f0ad3 em seus índices SNMP.

## Coleta

`python3 -u collector/snmp_fleet.py`, no host ACS, reutiliza `snmp_pilot.py`.
O coletor lê SOMENTE as colunas serial, estado, posição, RX, TX, temperatura,
voltagem e corrente. São oito GETBULK walks, dois concorrentes, max-repetitions 10,
45 segundos máximos por coluna. Não há SET, consulta IXC, Zabbix, Grafana ou TL1.
Uma coluna com timeout, erro ou resposta inesperada rejeita a coleta inteira.
Até 4096 ONUs por OLT neste primeiro perfil. Não desabilita Inform/CWMP.

Serial é vinculado por índice SNMP; a saída converte o prefixo hexadecimal do
serial TR-069 em quatro letras, com sufixo hexadecimal. Serial inválido ou
repetido vai para quarentena local por índice; ambas as ocorrências de um serial
duplicado são excluídas da associação. Nenhuma ONU válida é descartada por uma
identidade inválida de outra ONU. `discovered_total` inclui quarentena, `total`
conta seriais associáveis. `success` confirma a publicação parcial ou completa,
enquanto `identity_complete` informa se todas as identidades foram resolvidas.
Se nenhum serial puder ser associado, a publicação é recusada. Não faz associação por nome, IP ou ordem de resposta.

Saída: `/var/lib/jrc-collector/snmp-fleet.json` e `snmp-fleet-status.json`.
O estado 1 significa online, 0 sem sinal, 2 sem energia, 3 offline, conforme
MIB AN5116/5516. Óptica de ONU não confirmada online fica indisponível, mesmo
que a OLT conserve valores em memória. Zeros/sentinelas são preservados no bruto.
Cada coluna tem hora da consulta; data da medição continua desconhecida.

Segredo: `/etc/jrc-collector/snmp_community`, 0600, não vai para argumentos/logs.
Falha total ou queda >25% do inventário anterior preserva a última amostra e
marca a última tentativa falhada. O leitor não trata essa amostra como atual.

## Leitor no ACS — opt-in, ainda precisa de integração na instalação personalizada

`lib/JrcSnmpTelemetry.php` lê somente arquivos locais. A seleção depende de
`/etc/jrc-collector/snmp-source.enabled`. Sem esse marcador, o comportamento
existente não é trocado. Com ele, as rotas do repositório passam a usar a fonte:
- parsers completo e rápido (no inventário gerenciado pelo ACS);
- cache de inventário, resumo do dashboard e enriquecimento final do cron;
- dados ópticos de detalhe via `api/get-onu-snmp.php`, mantendo o contrato
  JSON de `get-onu-optical.php` pelo overlay local. O endpoint legado não é
  republicado: contém configuração sensível que não deve ser copiada ao GitHub.

Sem correspondência SNMP, snapshot com mais de 15 min, falha na última tentativa
ou ONU não online, não recupera telemetria antiga do IXC/TR-069.
Mantém `management_status` separado; nunca usa último Inform como data óptica.
Os limiares de sinal seguem -25/-28 nos leitores existentes. A conversão segue
a MIB AN5516, mas a validação de unidades com UNM ainda está pendente e é
explicitada em `telemetry_unit_validation`. Não cria alerta de temperatura.

## Antes da ativação em produção

1. Coletar a frota completa e conferir duração, total de seriais e campos.
2. Rodar `python3 scripts/inspect-acs-telemetry.py /var/www/gacs`; ele só imprime
   caminhos locais, nomes de rotas e contagens de marcadores, sem código ou segredos.
3. Adaptar o instalador aos arquivos REAIS do painel. `view-logs.php` e outras
   rotas de produção podem divergir do GitHub; esta PR não afirma migrá-los.
4. Conferir a MESMA ONU no UNM para RX/TX, temperatura, corrente e voltagem.
5. Publicar arquivos com backup, testar permissões do snapshot para PHP, agendar
   coleta com usuário dedicado e ativar marcador somente após validar todos os leitores.

Não copiar os parsers ou endpoints inteiros do repositório sobre a produção
personalizada. Esta etapa não instala cron, não cria marcador e não modifica
handlers de Wi-Fi, senha ou reboot. A imagem Docker shadow não executa a coleta
Python no estado atual; coletar no host.

O inventário TR-069 gerenciado tem cobertura diferente do inventário total da OLT.
Não apresentar os dois totais com o mesmo rótulo. As telas gerenciadas pelo ACS
projetam o mesmo conjunto do seu inventário e associam por serial.

Referência dos campos e enumerações:
https://pdfcoffee.com/fiberhome-gepon-5116-5516-mib-open-interface-specifications-pdf-free.html

## Identificadores hexadecimais de 12 caracteres

A coleta real também apresentou identificadores como DD32e69b93a4 e 0000622f5c52.
São preservados como identidade opaca `HEX12:...`, sem presumir que são MACs.
A associação com ACS exige o MESMO identificador em seu campo serial. Não há
fallback para MAC Ethernet, endereço IP, login ou nome. Ausência de correspondência
continua sem telemetria até validar a identidade; eliminar quarentena na OLT não
comprova cobertura do inventário ACS. Identificadores totalmente zero/FF são rejeitados.
