# Opa Suite - imagem candidata do MCP sem alterar producao

Este procedimento foi preparado apos as PRs #141 a #144 e as verificacoes reais no ACS.

## O que ja sabemos
- O container `jrconect-mcp` estava ativo e `/health` retornou HTTP 200.
- Opa API no host `https://opa.jrconect.com` respondeu 200, filtro `AG` e campo `setor` presentes.
- A rotina `opa_fila_resumo` foi homologada em container temporario, retornando 1 atendimento aguardando naquele instante e paginacao completa.
- As ferramentas novas nao constavam na imagem MCP ativa; o Docker usa codigo na imagem, nao apenas nos arquivos do host.
- O container informava Compose ativos `docker-compose.yml` e `docker-compose.ixc.yml`; o `docker-compose.opa.yml` existia no host mas nao estava aplicado.
- O token de API Opa ja existia no host e nao deve ser copiado ao Git nem substituido pelo token de cliente Opa.

## Preparacao segura
O script `scripts/stage-opa-mcp-candidate-safe.sh` faz SOMENTE:
1. Confirma health, imagem e Compose do MCP ativo.
2. Extrai o arquivo `opa-api-tools.js` **da imagem realmente em execucao**, nunca o `mcp/server.js` historico do Git.
3. Adiciona 3 ferramentas Opa em uma **copia temporaria** desse arquivo (preservando todas as ferramentas antigas).
4. Baixa 2 modulos ja aprovados da revisao exata `86bdf2c07c5054b2f8e58df44814b79576cf8b65`.
5. Constroi uma **imagem candidata em cima da imagem atual**, com apenas os tres arquivos de Opa novos/diferentes. Nada e compilado ou sobrescrito no host.
6. Roda `node --check`, testa o registro sem token/rede e prova que nenhuma ferramenta Opa existente desapareceu.
7. Valida a composicao proposta base + IXC + Opa sem aplica-la: alerta se alguma montagem atual desapareceria e se flag, URL, allowlist, token read-only ou escrita admin estao incorretos.
8. Confirma que a imagem ID do MCP ativo nao mudou e que `/health` continua respondendo.

**Nao chama** docker compose up/down, restart, stop, rm ou altera Keycloak, Nginx, tokens, arquivos host, imagem ativa ou dados do Opa. Apenas escreve arquivos de auditoria em diretorio root 0700, tags Docker adicionais e uma nova imagem candidata.

## Execucao
Apos merge, obter o script do commit de merge verificado:

    curl -fsSLo /tmp/stage-opa-mcp-candidate-safe.sh https://raw.githubusercontent.com/junaojsp/JRCONECT-ACS-MCP/main/scripts/stage-opa-mcp-candidate-safe.sh
    bash -n /tmp/stage-opa-mcp-candidate-safe.sh
    sudo bash /tmp/stage-opa-mcp-candidate-safe.sh

Nao usar curl | bash. Nao executar o script modificado por terceiro.

## Criticos apos a etapa candidata
- Confirmar `OPA_API_MCP_ENABLED=true`, `OPA_API_ALLOWED_SUB` valido e role Keycloak `opa-mcp-admin`; nao imprimir UUID, JWT ou tokens em chats.
- Confirmar montagem read-only em `/run/secrets/opa-api-token`.
- Testar fila AG com o container candidato isolado, com segredo read-only, e garantir que cliente Opa bearer **nao tem acesso** a essas ferramentas.
- Realizar smoke tests de Wiki, IXC, Financeiro, PABX, SHIELD e Opa na candidata com conexoes autorizadas.
- **Ativacao e etapa separada com autorizacao**, pois trocar container MCP pode derrubar sessoes temporariamente. Se zero indisponibilidade for requisito absoluto, planejar blue-green com proxy e testes antes de mudar o endpoint.
- Preservar imagem original por tag e manter rollback; nao excluir o plugin separado do Opa no ChatGPT ate as consultas centrais funcionarem no endpoint MCP principal.
