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

## Seguranca

Nao armazenar `opa-token.secret`, `.env`, codigos de autorizacao, access tokens, refresh tokens ou senhas no GitHub. Tokens estaticos devem ser rotacionados quando houver suspeita de vazamento. O caminho `/health` so deve ser acessivel localmente. Use permissao minima e TLS; `status_servidor` e uma ferramenta sem efeitos colaterais.

## Monitoramento do proprio ACS (PR de homologacao)

Duas ferramentas adicionais sao disponibilizadas apenas com `ACS_MONITOR_ENABLED=true` e `ACS_MONITOR_ALLOWED_SUBS` contendo o ID `sub` de usuarios Keycloak explicitamente autorizados:

- `acs_recursos_host`: uptime, carga media do Linux, memoria e swap do host. O container monta **somente** os arquivos `/proc/uptime`, `/proc/loadavg` e `/proc/meminfo` do host como leitura; nao possui acesso a outras partes do /proc.
- `acs_status_integracoes`: verifica o HTTP dos endpoints fixos e confiaveis do MCP e Keycloak. Nao verifica o estado interno dos processos nem faz operacoes de escrita.

**Producao:** ambos ficam DESABILITADOS por padrao. O token estatico do Opa nunca autoriza essas ferramentas; somente JWT Keycloak emitido para `chatgpt-mcp` com `sub` listado em `ACS_MONITOR_ALLOWED_SUBS`. Nunca versionar UUIDs de usuarios ou tokens reais. Nao conceder Docker socket, SSH, execucao shell, acesso root, mount do filesystem ou `/proc` completo ao MCP.

**Testes exigidos antes do deploy:**
1. `node --check server.js && node --check acs-monitor.js`.
2. Build de homologacao, com volume apenas dos 3 arquivos /proc descritos acima.
3. Sem `ACS_MONITOR_ALLOWED_SUBS`, uma chamada autenticada deve resultar em erro de autorizacao.
4. Bearer do Opa deve ser negado mesmo com allowlist presente.
5. JWT com `sub` autorizado deve retornar metricas, sem identificadores ou informacoes privadas.
6. `status_servidor`, HTTP 401 para acesso nao autenticado e metadados OAuth devem continuar operacionais.
7. Revisar diffs com os arquivos locais no servidor ACS antes de qualquer substituicao em producao.

**Nao implementado:** status de contêiner Docker, Nginx, PHP, MongoDB e uso de disco, nem reinicios e atualizacoes. Esses recursos exigem um agente administrativo local com API estritamente limitada e politicas de autorizacao proprias; nao expor socket Docker.
