# JRC-Collector — fase 1: servico isolado somente de leitura

Objetivo: separar coleta e diagnosticos da interface e dos comandos da ONU.
Este projeto e o primeiro passo para o coletor dentro do proprio host ACS,
isolado em Docker. NAO e uma ativacao em producao.

## O que a PR inclui

- GenieACS NBI: consultas GET apenas na colecao devices com projecao de campos,
  paginacao e verificacao contra duplicidade.
- IXC opcional: listagens somente leitura da fibra (sinal_rx, sinal_tx,
  temperatura, voltagem, data_sinal), cliente e, quando habilitado,
  login PPPoE. O IXC exige POST com cabecalho ixcsoft: listar, mas nao
  ha criacao, atualizacao nem exclusao de cadastro.
- Normalizacao por serial/MAC exato; cada medida mantem origem e idade.
- Status RX: normal acima de -25; fraco entre -28 e -25, inclusive;
  critico abaixo de -28; sem_dados quando invalido, offline ou antigo.
  Valores e faixas devem ser validados com a frota real.
- Temperatura: valor coletado se disponivel. Alerta de temperatura desligado
  por padrao ate definir limites de cada fabricante.
- Nao persiste senhas de Wi-Fi, credenciais de ONU nem ping simulado.
- Publicacao SHADOW atomica nas duas tabelas jrc_collector_shadow_*.
  Nunca modifica jrc_device_inventory_*, configuracoes do painel ou cron.
- Container sem porta HTTP, sem socket Docker, sem privilege, user 10001,
  filesystem somente leitura, 512 MiB RAM, 0.75 CPU e sem reinicio automatico.

### Limitacoes honestas

A coleta le os valores que GenieACS e IXC JA armazenam. Nao inicia
consulta ativa TR-069 na ONU para atualizar temperatura ou RX. Sem parametro
coletado previamente, o diagnostico aparecera como sem_dados. A data_sinal
do IXC pode nao representar a hora real da temperatura; validar isso.

O NBI exige GET /devices; para menor privilegio, antes de usar credenciais
de producao o operador deve disponibilizar um proxy NBI que permita
somente GET /devices. Nao reaproveitar usuario administrativo de ACS/IXC.
A documentacao oficial GenieACS descreve o parametro projection na API NBI:
https://genieacs.readthedocs.io/en/stable/api-reference.html

## Passos de homologacao apos merge

1. Fazer backup e revisar os codigos/CI. Nao copiar este projeto
   sobre os arquivos do painel em /var/www/gacs.
2. Preparar /etc/jrc-collector/config.env a partir de
   collector/example-config.txt. As configuracoes nao devem conter tokens.
3. Criar /etc/jrc-collector/secrets como root:10001 (modo 0750);
   arquivos individuais root:10001 (modo 0640); config.env root:root
   (modo 0600). Conferir que JRC_ACS_PASSWORD_FILE esteja vazio
   caso NBI nao use BasicAuth; caso use, arquivo de senha separado.
4. No checkout verificado, compilar:
   docker compose -f collector/compose.yaml --profile shadow build jrc-collector
5. Executar SOMENTE o diagnostico sem gravacao:
   docker compose -f collector/compose.yaml --profile shadow run --rm jrc-collector --check
   A saida esperada e COLLECTOR_CHECK_OK com contagens agregadas.
   Compare com aproximadamente 750 dispositivos atuais, e confira amostras
   do RX, temperatura, PPPoE, IP, firmware e dados indisponiveis.
6. So depois, DBA cria as tabelas usando collector/schema.sql
   no banco de homologacao; criar usuario dedicado com permissoes
   SELECT, INSERT, UPDATE e DELETE SOMENTE nessas duas tabelas.
   Nao conceder permissoes nas tabelas atuais do ACS.
7. Colocar JRC_COLLECTOR_SHADOW_WRITE=1 no config.env e executar uma unica vez:
   docker compose -f collector/compose.yaml --profile shadow run --rm jrc-collector --shadow
   Esperado COLLECTOR_SHADOW_OK.
   Consultar via DBA (apenas dados agregados):
   SELECT total,ixc_matched,metrics_json,note,completed_at
   FROM jrc_collector_shadow_state WHERE id=1;
8. Comparar resultados do coletor com o painel atual antes de
   agendar execucoes ou mudar o frontend. Nenhum agendamento, chave
   de cache ou comando de ONU e alterado por esta PR.

## Atencao a rede e credenciais

O container utiliza network_mode: host para chegar a servicos locais
NBI/MySQL. Nao abre portas nem monta docker.sock, mas compartilha a
pilha de rede do host: aplicar firewall e endpoint GET-only para o NBI.
Para IXC remoto HTTPS e obrigatorio. Senhas e tokens sao lidos de arquivos
em /run/jrc-collector-secrets, nao do repositorio ou da linha de comando.
Tokens IXC legados versionados devem ser auditados/rotacionados
separadamente sem interromper o sistema existente.

## Rollback e proximas PRs

Esta PR adiciona arquivos novos e NAO altera APIs do painel.
Nao fazer deploy equivale a deixar o ACS como esta. Para interromper
um piloto, nao agendar novamente o container e manter a flag shadow em 0.
Nao apagar dados/tabelas sem backup e autorizacao do DBA.

Fase 2: validar amostras e criar indicadores pre-calculados para
a Visao geral e Dispositivos, somente leitura, com aviso de dados obsoletos.
Fase 3: agendamento seguro e troca de fonte com flag reversivel,
mantendo as APIs de reboot, firmware, WAN, senha e Wi-Fi integralmente
no ACS. O cron atual collect-device-inventory.php continua ativo.
