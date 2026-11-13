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


## Correcao de paginacao da fila (posterior a PR #143)

O ACS validou em 13/11/2026 a rota GET /api/v1/atendimento:
- status AG, options.limit=1, skip=0: 1 registro, HTTP 200
- status AG, options.limit=1, skip=1: 1 registro DIFERENTE, HTTP 200
- status EA, options.limit=1, skip=0: 1 registro, HTTP 200
- status EA, options.limit=1, skip=1: nenhum registro, HTTP 200

Isto confirma deslocamento apenas nas pequenas amostras vistas. Nao comprova a
contagem total da fila, nem o comportamento sob 100 registros/pagina.

A rotina aggregateOpaQueue foi fortalecida para:
1. Exigir identificador estavel _id/id para cada atendimento AG; nao exibe IDs.
2. Rejeitar qualquer ID repetido na mesma pagina ou em pagina posterior.
3. Rejeitar retorno com mais registros do que options.limit.
4. Em pagina final nao vazia, fazer uma verificacao GET adicional com
   options.limit=1 e options.skip=<total lido>; se retornar dados,
   bloquear o resultado ao inves de inventar total.
5. Nao retornar total parcial ao exceder o numero maximo de paginas ou ao
   encontrar formato inesperado.

Essas verificacoes podem falhar com uma fila em movimento mesmo quando o
servico esta saudavel; nesse caso a ferramenta informa erro e a operacao
deve conferir a fila no painel do Opa. A consulta continua nao transacional.

Implantacao: o codigo desta PR NAO altera o container, o server.js,
segredos, Keycloak nem Docker Compose. Usar os arquivos do ACS em producao
como fonte principal; o mcp/server.js do GitHub esta desatualizado.
Antes de qualquer deploy: backup, imagem/Compose atuais documentados,
homologacao isolada Node 24, validacao da API AG, protecao OAuth e
regressao IXC/Wiki/Financeiro/PABX/SHIELD. Nao remover plugin Opa do GPT
ate a ferramenta central estar disponivel e os totais validados.
