# Auditar a estrutura real do MCPv2 antes de habilitar administracao ACS

O objetivo e garantir que o modulo administrativo versionado pela PR #151
seja incorporado SOMENTE apos conhecer o contexto de autenticacao do servidor real.

## Situacao atual
- A PR #150 adicionou `acs_recursos_host` e `acs_status_integracoes` em um modulo isolado.
- A PR #151 adicionou um protocolo administrativo seguro, incluindo `acs_admin_planejar` e `acs_admin_executar`, mas SEM helper local e sem deploy.
- A imagem MCPv2 principal na porta 3104 ainda nao possui esses modulos; o endpoint 3100 continua como reserva.
- O monitor ACS independente esta em 3103 e deve permanecer funcionando.
- `acs-mcp-admin` esta atribuido ao usuario `mcp-teste` no Keycloak, mas o contexto JWT real do servidor precisa ser confirmado. A ausencia da string literal `realm_access` em um codigo fonte nao prova o conteudo do JWT.

## Coleta read-only
O script `scripts/audit-acs-mcpv2-readonly.py` consulta apenas:
- `docker inspect` dos tres containers conhecidos (3100, 3103, 3104), suprimindo `Mounts.Source`, valores de `Env`, labels privadas e command line;
- `docker exec ... node -` na candidata 3104 para ler *internamente* alguns arquivos de codigo (sem exibir conteudo), listando somente existencia, linha de marcadores de autenticacao/registro e nomes estaticos de tools;
- `curl` local `GET /health` nas portas 3100, 3103 e 3104.

Nao chama `docker restart`, `docker stop`, `docker cp`, `docker compose`, comandos de escrita, nem recarrega Nginx.

## Operacao apos PR revisada e incorporada
Baixe a revisao IMUTAVEL aprovada, confirme `python3 -m py_compile` e execute com `sudo python3 /tmp/audit-acs-mcpv2-readonly.py`.

O relatorio resultante pode ser compartilhado no chat. Ele nao contem JWTs, tokens, senhas, codigo fonte nem dados de clientes. O diretorio `/root/jrc-opa-bluegreen/persistent-data` nao e lido.

## Proxima PR
Com relatorio validado:
1. desenhar a integracao *na imagem realmente em uso*, preservando arquivos e ferramentas existentes;
2. confirmar que OAuth real passa `realm_access.roles` ao modulo (se a role nao estiver acessivel, falhar sem privilegios);
3. homologar as duas tools de consulta sem disponibilizar comandos admin;
4. planejar agente host restrito e auditoria persistente para a unica operacao administrativa inicial `reiniciar_monitor_acs`;
5. testar OAuth positivo e negativo, Opa bearer negado, Opa/IXC/Financeiro/Wiki/SHIELD/PABX sem regressao e rollback.

**Nenhuma alteracao em producao e parte deste roteiro.**
