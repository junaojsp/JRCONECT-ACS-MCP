# Administracao do PABX via JR Conect MCPv2 (permissao de EDICAO)

Este pacote habilita **de forma opt-in** o catalogo administrativo ja registrado pelo `pabx-admin.js` instalado no ACS. Ele nao inventa endpoints de edicao e nao converte ferramentas de consulta em escrita. **Sem merge/deploy/secret/role, nada e habilitado.**

## Regras de seguranca

1. Acesso de leitura segue ativo atraves de `docker-compose.pabx-readonly.yml`, `PABX_READ_ALLOWED_SUB` e credencial somente leitura.
2. O ramo administrativo do `server.js` existente exige `pabxAdminAuthorized(payload)`, que valida cliente OAuth, subject allowlist, role Keycloak e `PABX_ADMIN_MCP_ENABLED=true`. Nunca remover a checagem de role ou disponibilizar writes para um token somente de leitura.
3. A API administrativa do Issabel usa `admin_token` separado do `read_token`. O token administrativo deve ficar somente no ACS, montado como arquivo de leitura para o usuario Node (UID/GID 1000), e nao em GitHub, Compose ou mensagens do chat.
4. Cada mudanca exige aprovacao explicita do operador. A autorizacao OAuth libera o catalogo, nao uma alteracao automatica de configuracao. Confirmar validacao de campos, autorizacao por acao e auditoria do endpoint PHP antes de qualquer write em producao.
5. Nao habilitar edicao em todas as ferramentas cegamente. `pabx_status`, `pabx_filas`, `pabx_ramais`, `pabx_troncos` e `pabx_relatorio_atendentes` continuam consultas; edicao deve ser realizada apenas por ferramentas administrativas especificas efetivamente existentes e revisadas.

## Preflight no ACS (nao muda nada)

A versao em producao de `/opt/jrconect-mcp/server.js` nao corresponde ao `mcp/server.js` antigo no repositorio. Nao substituir o servidor existente.

Revisar os nomes de acoes reais do modulo administrativo antes do deploy, sem revelar os tokens:

```sh
cd /opt/jrconect-mcp
grep -nE 'registerTool\(|registerPabxAdminTools|action|method' pabx-admin.js | head -n 100
```

Preparar o token de administracao por canal SSH confiavel a partir da chave `admin_token` em `/etc/jrc-pabx-mcp/api.ini` no Issabel. **Nunca imprimir seu valor**, nunca reutilizar o token de leitura. No ACS, o arquivo deve ser regular e nao acessivel ao grupo publico; utilizar, por exemplo, `root:1000` com modo `0640`, como na conexao de leitura, apenas se confirmado que o contêiner utiliza GID 1000.

Definir no shell do ACS:

```sh
export PABX_ADMIN_TOKEN_PATH=/etc/jrconect-mcp/pabx-admin-token.secret
export PABX_ADMIN_ALLOWED_SUB=UUID-KEYCLOAK-DO-ADMINISTRADOR
python3 /tmp/checkout/mcp/ops/check-pabx-admin.py --root /opt/jrconect-mcp
```

Esperado: `PABX_ADMIN_PREFLIGHT=OK`. Esse resultado valida a configuracao local, mas **nao valida a role Keycloak nem os limites operacionais do backend**. Se falhar, nao continuar.

## Keycloak

Atribuir `jrconect-pabx-admin` **somente** ao usuario autorizado (`sub` de `PABX_ADMIN_ALLOWED_SUB`) no realm `jrconect`. O cliente esperado e `chatgpt-mcp`. Conferir se a role aparece em `realm_access.roles` do token usado pelo conector. Nao atribuir a role a todos usuarios nem ao service account do cliente de forma geral.

## Composicao e ativacao (depois de revisar e testar)

Copiar `docker-compose.pabx-admin-guarded.yml` para `/opt/jrconect-mcp`, ao lado dos Compose existentes. Manter os 3 arquivos juntos **em todos os comandos de atualizacao futuros**:

```sh
cd /opt/jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml -f docker-compose.pabx-admin-guarded.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml -f docker-compose.pabx-admin-guarded.yml build jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml -f docker-compose.pabx-admin-guarded.yml up -d --no-deps --no-build jrconect-mcp
```

A recriacao interrompe brevemente o MCP. Ela **nao** modifica a URA nem o Asterisk. Verificar dentro do contêiner apenas que o token pode ser lido, sem exibi-lo. Nao execute ferramentas de escrita no teste inicial: primeiro confirme `tools/list` com OAuth autorizado contendo as ferramentas administrativas *reais*, e com OAuth nao autorizado contendo apenas as ferramentas de leitura.

## Testes de aceite

- [ ] `python3 -m unittest discover -s mcp/ops -p 'test_*.py' -v` aprovado
- [ ] `PABX_ADMIN_PREFLIGHT=OK` no ACS
- [ ] `docker compose ... config --quiet` aprovado, segredo montado e legivel por UID 1000
- [ ] O mesmo plugin `JR Conect MCPv2` continua listando as sete ferramentas de leitura do PABX
- [ ] Perfil admin autorizado lista apenas as acoes administrativas implementadas no backend
- [ ] Perfil sem role **nao** recebe ferramentas admin
- [ ] Confirmacao humana e registro de auditoria presentes nos fluxos de alteracao
- [ ] Primeiro teste de edicao somente em objeto de teste aprovado e com rollback preparado

## Rollback imediato

Para desativar a administracao, voltar a executar o Compose **sem** o overlay `docker-compose.pabx-admin-guarded.yml`, preservando `docker-compose.pabx-readonly.yml`. Isso mantem as consultas. Nao remover o token antes de encerrar todas as sessoes administrativas, se existirem.

## Limite importante

O codigo completo de `pabx-admin.js` instalado no ACS nao esta versionado neste repositorio. Por isso, este PR adiciona **infraestrutura de permissao e verificacao**, nao novas funcionalidades de edicao de ramais/filas/troncos. Essas operacoes dependem das ferramentas realmente registradas nesse modulo e da API administrativa instalada no Issabel. Revisar/registrar cada ferramenta em PR especifico caso esteja ausente.
