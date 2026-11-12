# Patch de implementação: IXC OAuth independente do Shield

Esta alteração é um **patch executável para a árvore Node.js existente no ACS** (`/opt/jrconect-mcp`). O repositório principal atualmente contém o dashboard PHP; por isso não substituímos o `server.js` do dashboard por um arquivo diferente. O patch foi gerado a partir do pacote de código de produção enviado em 12/11/2026.

## Alterações
- Registra ferramentas IXC independentemente de `includeShield`.
- Seleciona um catálogo IXC isolado para OAuth autorizado sem exigir `SHIELD_MCP_ALLOWED_SUB`.
- Preserva PABX somente leitura quando esse acesso também estiver autorizado.
- Aceita `ixc-mcp-read` ou `ixc-mcp-admin`, mantendo flag, `azp` e allowlist.
- Não modifica credenciais, API IXC, dados retornados, nem o fluxo Opa bearer.

## Verificação antes de qualquer aplicação
No ACS, na árvore de código atual, executar **somente após revisão**:
```sh
cd /opt/jrconect-mcp
git apply --check /caminho/para/ixc-permissoes.patch
```
Como a árvore de produção não é um checkout Git, alternativamente `patch --dry-run -p1 < /caminho/para/ixc-permissoes.patch`. Não aplicar caso haja divergência.

## Testes
1. `node --check server.js` e `node --check integrations/ixc-webservice-auth.js`.
2. Verificar `tools/list` com usuário autorizado IXC sem Shield: ferramentas IXC visíveis, Shield ausente.
3. Verificar flag desligada, sub fora da allowlist, cliente diferente e JWT inválido: sem IXC.
4. Verificar combinação IXC + PABX read e comportamento das demais integrações.
5. Manter plugin IXC direto até confirmação ponta a ponta.

**Não executar deploy/restart/merge automático.** A PR propõe código para revisão e testes; não certifica testes de integração no ACS.
