# Auditoria de cobertura optica IXC para o JRC-Collector

Situacao verificada em 16/11/2026: o inventario GenieACS tem cerca de
750 ONUs, mas somente duas com RX valido e duas com temperatura valida no
cache TR-069 existente. O piloto Collector v1, sem IXC, retornou zero
sinais RX/temperaturas. Ainda nao e seguro trocar a fonte do painel.

## Objetivo

Antes de alterar o coletor, medir a cobertura real da API optica IXC
que o painel personalizado ja utiliza. Este passo so consulta dados.

O script `scripts/jrc-collector-ixc-audit.php`:

- identifica URL e token literais usados pelo endpoint existente
  `/var/www/gacs/api/get-onu-optical.php` com tokenizacao estatica
  (sem incluir/executar PHP nem imprimir as credenciais);
- chama apenas a operacao IXC de listagem `ixcsoft: listar`
  em `radpop_radio_cliente_fibra`;
- consulta somente o snapshot atual de `jrc_device_inventory_*`;
- compara identificadores normalizados por serial e MAC em separado,
  apontando correspondencias ambiguas em vez de associar por nome;
- conta RX, temperatura, TX, tensao e leituras com data_sinal recente;
- imprime somente contagens agregadas com `IXC_OPTICAL_AUDIT_OK`.
  Nao imprime token, login, nome, MAC, serial, IP ou lista de clientes.

## Procedimento

No ACS, apos merge do codigo em `/tmp/jrc-acs-firmware-ui`:

```bash
cd /tmp/jrc-acs-firmware-ui &&
  php -l scripts/jrc-collector-ixc-audit.php &&
  php tests/jrc-collector-optical-audit.test.php &&
  php scripts/jrc-collector-ixc-audit.php
```

Este diagnostico faz chamadas de leitura ao IXC e ao MySQL, sem criar
agendamentos, alterar o painel, copiar credenciais para Docker ou
ativar flags de cache. Executar uma unica vez e observar eventual limite
de requisicoes do IXC.

O fuso usado para interpretar `data_sinal` e America/Sao_Paulo por
padrao, podendo ser ajustado com `JRC_IXC_TIMEZONE` conforme configuracao
real do IXC. A data do sinal nao prova a atualizacao da temperatura.

## Interpretacao

- `correspondencias_serial` / `correspondencias_mac`: indicam a
  estrategia correta de vinculo entre IXC e GenieACS.
- `correspondencias_ambiguas`: investigar antes de ativar.
- `rx_preenchido` vs `rx_recente_15min`: identifica se o IXC realmente
  fornece amostras recentes; dados antigos nao podem aparecer como atuais.
- `temperatura_preenchida`: somente disponibilidade, nao valida limites
  termicos nem prova frescor independente do campo `data_sinal`.

O objetivo da proxima PR sera integrar esses dados *a coleta*
do JRC-Collector, nao durante abertura de telas. Nenhuma consulta ou
comando atual do ACS e substituido nesta auditoria.

## Seguranca

O codigo legado contem um token IXC literal em arquivo PHP. Esta auditoria
nao o replica nem o imprime. Antes de operacao continua no Docker, criar
credenciais dedicadas de leitura armazenadas como secrets fora do repo.
Planejar rotacao do token legado sem interromper as APIs de producao.
