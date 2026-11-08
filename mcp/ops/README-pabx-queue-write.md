# Controle administrativo de pausas de atendentes - PABX

Esta entrega acrescenta somente duas ferramentas **de escrita real** ao catalogo administrativo ja previsto no MCP principal:

- `pabx_pausar_atendente` -> POST `queue_pause`
- `pabx_retomar_atendente` -> POST `queue_unpause`

A API Issabel ja possui essas duas acoes com metodo POST, token admin, `X-JRC-Confirm: yes`, validacao de `interface` e `queue`, e auditoria. Este PR **nao** cria edicao de ramais SIP, troncos, filas ou arquivos IXCSoft.

## Seguranca

- As duas ferramentas somente sao registradas no `if (includePabx)` **administrativo** de `server.js`; NUNCA no `if (includePabxRead)`.
- A decisao OAuth `pabxAdminAuthorized(payload)` existente precisa ser mantida, incluindo subject individual, cliente `chatgpt-mcp`, role `jrconect-pabx-admin` e `PABX_ADMIN_MCP_ENABLED=true`.
- Token administrativo fica somente no segredo `/run/secrets/pabx-admin-token`, montado no ACS via `docker-compose.pabx-admin-guarded.yml` do PR #126. Token de leitura nao autoriza mudancas.
- `confirmar=SIM` na ferramenta **so deve ser enviado apos aprovacao humana explicita e contextualizada**. A API faz sua propria verificacao da confirmacao via cabecalho e audita a operacao.
- A interface esta restrita a SIP/PJSIP com identificador simples, a fila aceita somente ASCII alfanumerico, underscore e hifen (max 64). Nada de comandos arbitrarios.
- Resultado omitindo saida bruta do Asterisk e erros internos. Em falha/time-out, consultar fila e logs antes de repetir, pois pode ter ocorrido aplicacao parcial.

## Validacoes antes do deploy

1. Executar os testes isolados (CI). **Nenhum teste chama o Issabel de verdade.**
2. Validar o catalogo administrativo do PR #126 e o perfil/role no Keycloak. O preflight administrativo precisa passar. A conexao de leitura ja implantada permanece inalterada.
3. Verificar se o arquivo administrativo esta legivel por UID/GID 1000 dentro do contêiner. Nunca imprimir o token.
4. Conferir no Issabel se `queue_pause` e `queue_unpause` continuam aceitando os campos `interface` e `queue` na API atual.

## Instalar no ACS com seguranca

O `mcp/server.js` do GitHub e mais antigo que o `server.js` instalado no ACS. **Nao copiar nem substituir** esse arquivo a partir do GitHub. A instalacao deve ser feita exclusivamente pelo script de patch com backup.

Depois do merge, com checkout atualizado em `/tmp/jrc-pabx-fix-124`, copiar somente o modulo novo:

```sh
cd /opt/jrconect-mcp
sudo cp /tmp/jrc-pabx-fix-124/mcp/integrations/pabx-queue-write.js integrations/pabx-queue-write.js
sudo node --check integrations/pabx-queue-write.js
sudo python3 /tmp/jrc-pabx-fix-124/mcp/ops/enable-pabx-queue-write.py
```

O ultimo comando e **dry-run** e deve retornar `PABX_QUEUE_WRITE_PATCH_VALID` e `DRY_RUN_NO_CHANGE`. Se ocorrer qualquer erro, **parar**, sem deploy.

Somente apos aprovar a revisao do catalogo e as credenciais administrativas, aplicar:

```sh
sudo python3 /tmp/jrc-pabx-fix-124/mcp/ops/enable-pabx-queue-write.py --apply
sudo node --check /opt/jrconect-mcp/server.js
```

Para disponibilizar as ferramentas em runtime, usar os tres overlays em conjunto, com ambiente e tokens definidos conforme a documentacao do PR #126:

```sh
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml -f docker-compose.pabx-admin-guarded.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml -f docker-compose.pabx-admin-guarded.yml build jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml -f docker-compose.pabx-admin-guarded.yml up -d --no-deps --no-build jrconect-mcp
```

**O merge por si so nao ativa o admin.** Esta publicacao depende da role Keycloak, do segredo admin e da autorizacao ja existente em producao.

## Aceite

- Confirmar `tools/list` com conta administrativa autorizada: duas novas ferramentas aparecem.
- Confirmar `tools/list` com conta de leitura: as duas ferramentas **nao aparecem**.
- Validar `pabx_status` e `pabx_relatorio_atendentes` continuam funcionando.
- Primeiro uso com atendente e fila de teste, plano de retorno definido, e aprovacao humana explicita. Verificar logs de auditoria do Issabel.

## Rollback

Restaurar o backup do `server.js` registrado pelo patch e recriar somente o contêiner `jrconect-mcp` com os mesmos overlays e tokens, sem excluir a configuracao de leitura. Opcionalmente deixar `PABX_ADMIN_MCP_ENABLED` desativado para bloquear todas as escritas, mesmo antes do rollback do arquivo.
