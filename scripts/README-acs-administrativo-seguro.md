# ACS administrativo no MCP JR Conect (fase segura)

## Contexto validado em 13/11/2026
- `jrconect-mcp-opa-ready` na porta local 3104, servido publicamente por `/mcp`, com IXC, Financeiro, Opa, Wiki e SHIELD existentes.
- `jrconect-mcp` (3100) preservado como rollback.
- `jrconect-acs-monitor-teste` (3103) tem ferramentas `acs_recursos_host`, `acs_status_integracoes` e `status_servidor`.
- O modulo de leitura ACS da PR #150 esta em GitHub, mas AINDA NAO ESTA INSTALADO na imagem ativa (verificacao no container retornou false).
- Keycloak: `mcp-teste` recebeu `acs-mcp-admin`, mas nao ha teste de JWT REAL confirmando que `realm_access.roles` da sessao ChatGPT contenha a role.
- O arquivo `mcp/server.js` versionado e uma reconstrucao historica, NAO substitui o /app/server.js REAL. O diagnostico de regex de texto `PERMISSOES_JWT: 0` nao comprova ausencia da role no JWT; precisamos inspecionar como o payload validado e propagado ao handler.

## Decisao: acs-mcp-admin = admin CONTROLADO
Mantemos duas consultas de leitura da PR #150. Para escrita administrativa, a primeira operacao aprovada para desenvolvimento sera APENAS:

- `reiniciar_monitor_acs` -> container exato `jrconect-acs-monitor-teste` (porta 3103).

Nao pode reiniciar / parar / remover `jrconect-mcp`, `jrconect-mcp-opa-ready`, IXC, Financeiro, Keycloak, Nginx, Shield, Wiki, PABX, banco ou outros containers. Nao existe execucao livre de shell, sudo, SSH, docker.sock ou acesso root a partir do MCP.

## Protocolo (a implementar no handler real apos auditoria)
Arquivo isolado `mcp/integrations/acs-admin-guarded-tools.js`:
1. Flag DESABILITADA por padrao `ACS_ADMIN_MCP_ENABLED`; exigir `true` para registrar.
2. Autorizacao cumulativa: JWT Keycloak realmente validado, `azp=chatgpt-mcp`, `sub` na `ACS_ADMIN_ALLOWED_SUB`, `realm_access.roles` incluindo `acs-mcp-admin`.
3. Registro apenas de `acs_admin_planejar` e `acs_admin_executar`, com alvo/acao *fixos*.
4. Plano emite ticket aleatorio de uso unico valido por 2 minutos, vinculado ao `sub`.
5. `acs_admin_executar` requer ticket + frase exata de confirmacao; o operador deve mandar essa confirmacao **em nova mensagem** antes de chamar a tool. O protocolo nao consegue sozinho verificar a origem humana dessa mensagem: o handler deve reforcar esse requisito.
6. Auditoria persistente pre-acao OBRIGATORIA; se indisponivel, nao executar. Auditoria final tambem registrada. A resposta nao repassa stdout nem informacoes internas do helper.
7. Para executar, o MCP DEVE chamar um auxiliar local restrito (`helper` injetado). **Esse auxiliar ainda nao foi criado nem conectado** nesta PR. Ele devera impor sua propria allowlist, checar autorizacao do canal local, registrar no host, limitar taxa e aceitar apenas o container exato. Nao montar socket Docker no MCP.

**Importante:** a PR NAO implementa restart real nem disponibiliza tools no ChatGPT. Sem helper, registro fica indisponivel, por desenho seguro.

## Antes de implantar
1. Revisar no ACS o `/app/server.js` REAL (apenas fragmentos estruturais, nunca JWT/tokens), o `/app/integrations`, os Compose efetivos, UID/GID, storage e container 3103.
2. Confirmar que a sessao OAuth do ChatGPT apresenta role `acs-mcp-admin` em JWT verificado (sem imprimir o token, nem o `sub` nos logs).
3. Preparar PR de adaptacao ao handler real e agente host isolado: sem acesso irrestrito ao Docker pelo MCP, nenhum comando arbitrario, log persistente e autorizacao por acao.
4. Criar imagem candidata sobre a imagem EM EXECUCAO de 3104, preservando todas as ferramentas e mounts. Homologar em porta livre separada; nao substituir `server.js` pelo arquivo do GitHub.
5. Testar negacao sem permissao, negacao com bearer Opa, plano expirado, replay, falha de auditoria e confirmacao; testar ferramenta real somente em janela autorizada.
6. Revisar troca controlada e rollback Nginx preservando 3100, 3103 e 3104. Nunca remover o plugin Monitoramento ACS antes de equivalencia testada.
7. Acoes mais fortes (reiniciar MCP principal, Docker compose, Nginx, update de producao, backups/restores) exigem futura avaliacao de impacto e allowlist propria; nao sao implementadas nesta fase.

## O que esta PR altera
Somente modulo puro, testes unitarios, este documento e CI. **Sem deploy, sem Docker, sem stop/restart, sem Nginx reload, sem modificacao de Keycloak/ACS ativo.**
