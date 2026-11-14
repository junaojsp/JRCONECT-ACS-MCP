# Centralizar o monitoramento ACS no MCPv2

## Situacao verificada no ACS

- `jrconect-acs-monitor-teste` na porta local 3103 responde `/health` HTTP 200.
- Registro de ferramentas no container ACS: `status_servidor`, `acs_recursos_host`, `acs_status_integracoes`.
- `status_servidor` ja existe no MCPv2. Faltam apenas **duas ferramentas**.
- MCPv2 atual atende na porta 3104 por meio do Nginx `/mcp`; a instancia antiga 3100 continua disponivel.
- A permissao `acs-mcp-admin` ja foi atribuida no Keycloak ao usuario `mcp-teste`.

## O que esta PR faz

Adiciona um modulo **de leitura e testes**, sem deploy, ao codigo versionado em
`mcp/integrations/acs-monitor-central-tools.js`.

Ferramentas incluidas:

- `acs_recursos_host`: uptime, carga, RAM e swap, lendo apenas arquivos limitados e somente leitura do host: `/proc/uptime`, `/proc/loadavg`, `/proc/meminfo`.
- `acs_status_integracoes`: testes HTTP exclusivamente em URLs fixas de descoberta OAuth do MCP e Keycloak.

Exige: `ACS_MONITOR_MCP_ENABLED=true`, `ACS_MONITOR_ALLOWED_SUB` com sub autorizado, `azp=chatgpt-mcp` e role `acs-mcp-admin`. A identidade deve vir do **JWT ja validado** pelo fluxo de autenticacao da requisicao. Nunca usar valores do usuario como identidade nem um objeto global compartilhado.

## Ainda falta antes de publicar

1. Auditar o `/opt/jrconect-mcp/server.js` e o Dockerfile **reais** no ACS. O `mcp/server.js` do GitHub nao e uma copia completa da producao. Nao copiar esse arquivo para o ACS.
2. Conectar o novo modulo ao registro existente de ferramentas e contexto OAuth real no servidor, sem substituir outras integracoes.
3. Criar uma imagem candidata que mantenha **todas** as variaveis, mounts, redes, storage e ferramentas do MCPv2 e acrescente apenas os tres arquivos `/proc` em readonly.
4. Validar em porta isolada a lista autenticada de ferramentas, permissoes (inclusive nega a token do Opa), consultas ACS e regressao dos recursos Opa/IXC/Financeiro/Wiki/SHIELD/PABX.
5. Somente entao planejar a troca controlada do Nginx `/mcp`, com rollback. Nao remover o plugin separado do ACS ate teste completo.

## Garantias de escopo

Esta PR **nao** altera ACS em producao, Nginx, Keycloak, Docker Compose, portas, imagens ou plugins do ChatGPT. Nao permite shell, SSH, Docker socket, atualizacao, reinicio ou escrita.

A PR original #43 tinha um prototipo dessas duas consultas, mas nao foi incorporada. Esta proposta reutiliza o conceito e exige autorizacao explicita `acs-mcp-admin`, com testes unitarios.
