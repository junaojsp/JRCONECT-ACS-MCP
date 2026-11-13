# Opa Suite - fila de atendimentos no MCP central

## Objetivo
Adicionar `opa_fila_resumo` ao catalogo Opa ja protegido por OAuth do MCP central da JRConect. Apenas leitura. Nao cria instancia MCP, nao mexe nos fluxos do Opa e nao altera tokens, Keycloak ou o painel ACS.

## Contrato
- Fonte: API Opa instalada em `OPA_API_BASE_URL`, rota `GET /api/v1/atendimento`, com corpo JSON `{"filter":{"status":"AG"},"options":{"limit":100,"skip":0}}`.
- Usa arquivo de credencial existente `/run/secrets/opa-api-token`.
- Interpreta `AG` como aguardando; **confirmar no painel do Opa da JRConect que este status corresponde exatamente aos atendimentos na fila**.
- Saida: total `aguardando`, `por_setor` (identificadores brutos; sem nomes pessoais), data da consulta, auditoria de paginas.
- Nao informa tempo medio de espera, fila por atendente ou IA vs humano, pois estes campos nao foram validados.
- Consulta paginada com ate 100 paginas x 100 itens. Recusa pagina incompleta, esquema inesperado ou retorno de outro status; nao apresenta numero parcial como completo.
- A contagem pode mudar durante a paginacao: e uma consulta operacional, nao uma fotografia transacional.
- Nao imprime nome, CPF, telefone, mensagens ou IDs de atendimentos.

## Como validar sem afetar producao
1. Na branch, rodar `node --check mcp/integrations/opa-queue-summary.js`, `node --check mcp/integrations/opa-api-tools.js` e `node --test tests/opa-queue-summary.test.mjs` com Node 24; ainda nao validado no ACS nesta PR.
2. Comparar o `server.js` e os modulos efetivamente ativos em `/opt/jrconect-mcp`: o `mcp/server.js` do GitHub e historico e **nao deve ser copiado sobre o servidor em producao**.
3. Confirmar que o handler OAuth ja registra `registerOpaApiTools` somente para o operador com role `opa-mcp-admin` e allowlist; o bearer do Opa cliente nao pode receber ferramentas de consulta de clientes.
4. Confirmar endpoint, filtro `AG`, formato `data`, identificador do setor, token existente e paginas com respostas reais, sem imprimir dados pessoais. Falha segura se a API ignorar filtros ou retornar um esquema diferente.
5. Preparar backup dos modulos de producao e promover somente os arquivos novos e o registro atualizado para a imagem real, de maneira controlada. Confirmar os Compose ativos, manter rollback pronto.
6. Testar `/health`, `status_servidor`, `tools/list` pelo OAuth autorizado, `opa_fila_resumo` e demais ferramentas existentes. Comparar total/setores com o painel Opa no mesmo intervalo de tempo.
7. **So depois do sucesso** avaliar remover eventual plugin Opa separado no ChatGPT. Nao remover o cliente Opa que se conecta ao MCP nem a credencial/arquivo da API Opa necessarios ao fluxo central.

## Sem deploy
Esta PR altera somente o GitHub. Sem reinstalacao, sem reinicio, sem token no repositorio e sem chamadas de escrita.
