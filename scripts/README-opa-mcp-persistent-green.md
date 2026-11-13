# Opa MCP - concluir persistencia do ambiente green (3104)

## Evidencia do ACS, 13/11/2026
- MCP principal (porta 3100): HTTP 200, sem alteracoes.
- MCP green (porta 3104): HTTP 200, chave publica Keycloak e fila AG validadas.
- O unico arquivo observado em /data do principal era /data/connections.json.
- Backup criado no ACS em /root/jrc-opa-bluegreen/connections-backup-20261113T202934Z/connections.json, JSON validado.
- O usuario copiou o arquivo para a candidata via docker exec como UID 1000; hash SHA256 confirmou integridade. A tentativa anterior de docker cp para rootfs read-only falhou, mas **nao alterou a producao**.
- A copia no green ainda esta em /data tmpfs, que seria perdida na recriacao do container.

## Ajuste seguro da PR
Novo script `scripts/opa-mcp-migrate-green-data.py`:
- `plan`: valida a imagem/container green, imagem original, codigo 3104, Nginx /mcp em 3100, estado bluegreen e copia JSON. Nenhuma alteracao.
- `apply`: com confirmacao explicita, cria snapshot JSON 0600 e /root/jrc-opa-bluegreen/persistent-data privado UID1000, preservando integridade.
- PARA **somente** `jrconect-mcp-opa-ready` (3104), renomeia o container de teste original para recuperacao, e recria outro **com a mesma imagem, variaveis, redes e montagens readonly** e novo /data bind persistente.
- Confere 3104 health, ambiente, redes, tokens readonly, hash do arquivo e 3100 intacto.
- Em erro na nova candidata, tenta restaurar o container de teste anterior; nenhum rollback do MCP principal e necessario, pois ele nunca foi tocado.
- Atualiza o ID da candidata no arquivo `/root/jrc-opa-bluegreen/state.json` para que o roteiro bluegreen continue a reconhecer a instancia correta.
- A funcao `activate` do bluegreen passa a bloquear cutover se /data persistente ainda nao estiver validado.

## Operacao (apos merge e revisao)
Baixar o script com URL de commit fixo conferida apos PR aprovada, e rodar:

    sudo python3 /tmp/opa-mcp-migrate-green-data.py plan

Se passar e houver concordancia com a recriacao **apenas da homologacao**, executar:

    sudo env JRC_APPROVE_GREEN_MIGRATION=YES python3 /tmp/opa-mcp-migrate-green-data.py apply

Os arquivos `/tmp/opa-mcp-bluegreen.py` do ACS devem ser atualizados para a revisao corrigida **antes de eventual activate**. O script antigo nao possui a nova exigencia de /data persistente.

**NAO EXECUTAR activate ate validar tools/list OAuth real e preservar as ferramentas atuais.**
Nao alterar Nginx, Compose, servidor MCP de producao ou Keycloak. /mcp-finance, /mcp-admin, /mcp-acs e cliente opa-suite tambem continuam inalterados.
A troca futura de /mcp pode exigir reconexao de sessoes.

## Importante
O arquivo connections.json guarda estado de identificacao/conexoes. Uma copia inicial preserva o historico do momento, mas nao sincroniza automaticamente os contadores e ultimos acessos do MCP antigo apos a migracao. Nao utilizar o arquivo da candidata para sobrescrever o da producao durante rollback. Se houver divergencias importantes antes do cutover, tratar migracao adicional de estado em manutencao planejada e nunca sobrescrever o arquivo ativo diretamente.
