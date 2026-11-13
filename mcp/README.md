# JR Conect MCP (componente do ACS)

Codigo-fonte isolado para o servidor MCP hospedado no mesmo host ACS. O dashboard GACS/PHP nao e modificado. **Repositorio publico:** nunca enviar credenciais, segredos, dumps, backups ou tokens.

## Estado e escopo

- Endpoint externo: `https://mcp.jrconect.com/mcp`, atras do Nginx com TLS.
- Endpoint interno de saude: `http://127.0.0.1:3100/health`.
- Opa Suite usa token estatico armazenado **somente no servidor** em `opa-token.secret`.
- ChatGPT usa JWT RS256 do Keycloak (issuer, audience e azp validados).
- Ferramenta implementada: `status_servidor`, apenas para diagnostico.
- **Ainda nao ha ferramentas para consultar ou alterar dados no Opa Suite nem no IXC.** A direcao Opa -> MCP ja foi testada em producao; ChatGPT -> MCP ainda depende de onboarding OAuth e metadados de descoberta.

## Implantacao e revisao

O host de producao usa `/opt/jrconect-mcp`. **Nao executar deploy automatico** a partir deste repositorio: conferir diferencas com os arquivos locais e fazer backup antes de sincronizar. Os arquivos daqui sao uma reconstrucao versionada a partir da configuracao discutida, nao uma copia auditada dos arquivos em execucao.

A estrutura exige `opa-token.secret` no diretorio do Compose, nao versionado; criar de forma segura no servidor, 64 caracteres hexadecimais, dono `root`, grupo do usuario do container `node`, permissoes `0640`. Confira o UID/GID real do container antes do ajuste. Nao copiar nem imprimir o segredo nos logs.

Instalacao em ambiente de homologacao com dependencia de rede Keycloak existente:

```sh
cd mcp
docker compose config --quiet
docker compose up -d --build
docker compose ps
curl -fsS http://127.0.0.1:3100/health
```

Testes basicos locais:

```sh
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:3100/mcp
curl -s -o /dev/null -w '%{http_code}\n' -H 'Authorization: Bearer token-invalido-teste' http://127.0.0.1:3100/mcp
```

Esperado para ambos: `401`. Testar o token valido de maneira que ele nao apareca no historico ou logs; guardar registros sem credenciais. Antes de expor em HTTPS, validar controles, taxas, logs e escopos.

## Proximos passos

1. Comparar esta implementacao com `/opt/jrconect-mcp` no ACS, incluindo versoes exatas das dependencias, antes de qualquer deploy.
2. Implementar resource metadata OAuth conforme o fluxo de conectores MCP do ChatGPT e testar login com Keycloak. Nao supor que a simples validacao de JWT conclui OAuth.
3. Implementar ferramentas Opa Suite de forma granular, com permissoes por identidade, validacao de entradas e trilha de auditoria.
4. Adicionar testes automatizados de autenticacao e execucao das ferramentas antes de habilitar operacoes que alterem dados.


## Integracao Opa Suite (consulta limitada)

A ferramenta opcional `opa_listar_atendimentos` utiliza `GET https://opa.jrconect.com/api/v1/atendimento` com JSON no corpo: `{"filter":{},"options":{"limit":1}}`. Essa chamada foi confirmada no ACS com HTTP 200, status `success`, e uma lista de tamanho 1.

A ferramenta **permanece DESABILITADA por padrao**: `OPA_READ_ENABLED=false`. O token usado e o **token de API do Opa**, distinto do token Bearer usado pelo Opa para conectar ao MCP. No host, o arquivo `/opt/jrconect-mcp/opa-api-token.secret` deve ser protegido e legivel apenas pelo usuario do container, montado em `/run/secrets/opa-api-token:ro`. Nunca enviar o conteudo para GitHub ou chat.

A resposta e limitada a 1-10 registros e expõe exclusivamente `protocolo`, `status`, `canal`, `setor`, `origem`, `data` e `fim`. Nao devolve descricao, observacoes, ID de cliente ou mensagens.

**Permissoes:** o codigo exige `OPA_READ_ENABLED=true`, cliente JWT `chatgpt-mcp` e `sub` do Keycloak listado em `OPA_ALLOWED_SUBS` (valores separados por virgula). Uma lista vazia bloqueia todos. A identidade Bearer `opa-suite` e negada nesta ferramenta. `AsyncLocalStorage` isola a identidade por requisicao. **Nao habilitar publicamente antes de validar isolamento entre usuarios, auditoria e privacidade.** O fato de a ferramenta aparecer na descoberta MCP nao significa que a consulta seja autorizada. Nao colocar IDs de usuarios privados ou tokens no repositorio publico; configure a lista somente no ACS.

O novo modulo `opa-api.js` usa `node:https` pois a API testada aceita GET com corpo JSON, o que nao e suportado pelo `fetch` padrao do Node. Nao segue redirecionamentos. Defina limite de resposta e timeout.

Depois da revisao, testar primeiro em ambiente isolado, sem expor portas publicas. Atualizar o container de producao apenas apos validar autenticacao e isolamento das identidades.

## Seguranca

Nao armazenar `opa-token.secret`, `.env`, codigos de autorizacao, access tokens, refresh tokens ou senhas no GitHub. Tokens estaticos devem ser rotacionados quando houver suspeita de vazamento. O caminho `/health` so deve ser acessivel localmente. Use permissao minima e TLS; `status_servidor` e uma ferramenta sem efeitos colaterais.
