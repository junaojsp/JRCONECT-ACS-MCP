# PABX de leitura no MCP principal (ACS) — implantação segura

## Por que este PR não substitui `mcp/server.js`?

O arquivo versionado no GitHub é anterior à versão já instalada em `/opt/jrconect-mcp/server.js` no ACS. Substituí-lo destruiria as integrações locais com Wiki, Shield, Opa Suite, Financeiro e IXC. Este PR fornece **patch com validação do código existente**, configuração Compose complementar, testes e rollback. **Não faz deploy sozinho.**

## Pré-requisitos

- ACS: `/opt/jrconect-mcp` com `server.js`, `pabx.js`, `pabx-admin.js`, `integrations/pabx-queue-report.js` existentes.
- O PHP do PABX já deve responder ao endpoint `queue_report` via HTTPS (implantado anteriormente).
- Credencial de leitura de **64 caracteres hexadecimais**, existente e autorizada no PABX; armazenada **somente no ACS**, fora do repositório, com permissão restrita.
- UUID do sujeito OAuth Keycloak autorizado a consultar o PABX. **Não use um cliente genérico nem habilite acesso anônimo.**

## Aplicação no ACS (após merge e revisão)

**1.** Copiar os dois arquivos deste PR do checkout GitHub para `/opt/jrconect-mcp`, preservando os demais arquivos em produção. NÃO copiar o `mcp/server.js` antigo do repositório.

**2.** Conferir o patch sem alterar nada:

```sh
cd /opt/jrconect-mcp
python3 ops/enable-pabx-readonly.py
```

**3.** Preparar o token somente de leitura no ACS, usando canal SSH seguro, com proprietário root e modo `0600`. Não enviar token pelo chat e não colocar no GitHub. A aplicação e o Docker devem conseguir ler o arquivo. A configuração original do Issabel é `/etc/jrc-pabx-mcp/api.ini`: verificar a **chave e política** existente, sem imprimir o valor no terminal público. Se não houver token de leitura válido, interromper e provisionar uma credencial própria antes de seguir.

**4.** Definir no shell do ACS, sem commit de valores:

```sh
export PABX_READ_TOKEN_PATH=/CAMINHO/SEGURO/DO/TOKEN
export PABX_READ_ALLOWED_SUB=UUID-KEYCLOAK-AUTORIZADO
```

**5.** Validar Compose **antes** de atualizar:

```sh
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml config --quiet
```

Não combinar com `docker-compose.pabx-central.yml`, que habilita funções administrativas. Verifique o conteúdo do Compose efetivo e a existência do segredo antes de continuar.

**6.** Aplicar patch apenas se pré-requisitos passaram:

```sh
python3 ops/enable-pabx-readonly.py --apply
node --check server.js
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml build jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx-readonly.yml up -d --no-deps --no-build jrconect-mcp
```

**7.** Confirmar `tools/list` no endpoint OAuth `/mcp` para o usuário autorizado, contendo `pabx_status`, `pabx_filas`, `pabx_ramais`, `pabx_troncos` e `pabx_relatorio_atendentes`. Confirmar também que ferramentas do `pabx-admin.js` **não** aparecem. Conferir as ferramentas anteriores de Wiki, Shield, Opa, Financeiro e IXC. Atualizar catálogo de ferramentas do plugin JR Conect MCPv2 no ChatGPT.

## Rollback

O patch imprime o caminho exato de `server.js.bak-pabx-readonly-...`. Se houver erro, restaurar a cópia com `cp -a` e iniciar o contêiner com a configuração anterior. O arquivo Compose complementar pode simplesmente deixar de ser informado no comando. **Não apagar tokens nem alterar a API PABX.**

## Segurança

O novo ramo OAuth exige `azp=chatgpt-mcp` **e** o `sub` explicitamente aprovado em `PABX_READ_ALLOWED_SUB`. Se o UUID não estiver definido ou for inválido, a autorização falha. A seleção de catálogos existente continua intacta, inclusive o ramo administrativo. Acesso do PABX permanece somente leitura via segredo montado como arquivo.
