# Expansao Opa Suite - catalogo e resumos no MCP central

## Escopo
Uma unica conexao ChatGPT -> MCP JRConect -> API Opa Suite.
Nao cria servidor MCP nem plugin paralelo, nao muda fluxo de atendimento, nao gera token e nao executa escritas.

**Estado do codigo:** proposta em PR; **nao implica deploy ou permissao disponivel no ACS**. A copia mcp/server.js no Git e historica, divergente da instalacao real. NUNCA sobrescrever /opt/jrconect-mcp/server.js, Docker Compose ou credenciais sem reconciliacao com producao.

## Documentacao
- https://api.opasuite.com.br/
- https://central-opasuite.ixcsoft.com.br/documentacao/api/api-opa!-suite
- https://central-opasuite.ixcsoft.com.br/documentacao/menu-configuracoes/2-permissoes/funcoes-de-cada-permissao.html

A documentacao publica agrupa 12 recursos:
atendimentos, mensagens, canais de comunicacao, templates, clientes, usuarios,
contatos, etiquetas, notificacoes, departamentos, motivos e periodos.

**Somente os 6 endpoints GET com caminho especifico confirmado no material consultado** constam da allowlist:
- atendimentos: /api/v1/atendimento (status AG, EA ou PS; F permanece no relatorio por periodo existente);
- mensagens: /api/v1/atendimento/mensagem (id_rota obrigatorio);
- canais: /api/v1/canal-comunicacao/ (status A por padrao);
- templates: /api/v1/template (sem filtro; testar aceitacao real);
- usuarios: /api/v1/usuario/ (status A por padrao);
- contatos: /api/v1/contato/ (sem filtro; testar aceitacao real).

O catalogo lista as outras 6 categorias como **nao habilitadas**, pois a rota GET, o formato, a permissao ou a propria existencia de operacao de leitura ainda precisam ser validados na API do Opa. Nao presumir path a partir de nome.

## Ferramentas
- opa_catalogo_recursos: apenas informacao estatica de capacidade; sem chamadas API.
- opa_resumo_recurso(recurso, status?, id_rota?): somente consulta GET paginada, agregacao local, sem retornar linhas individuais, nomes, CPF, telefone, mensagens ou tokens.
- Nao modifica opa_fila_resumo, opa_relatorio_atendimentos ou opa_canais existentes.

**Seguranca**
- O registro dessas tools deve ficar exclusivamente dentro de registerOpaApiTools no handler Opa OAuth validado, com role opa-mcp-admin + allowlist de sujeito + cliente chatgpt-mcp. O bearer usado pelo Opa como cliente do MCP nao pode ter acesso as ferramentas.
- Permissoes na API Opa sao por recurso (grupo API): conceder apenas Visualizar nos recursos necessarios. 401, 403, 400 e rotas desconhecidas devem resultar em erro claro, nunca em procura de endpoints alternativos ou elevacao de permissao.
- A ferramenta nao tem path arbitrario, parametros HTTP personalizados, SQL, proxy generico ou operacao de escrita.
- Filtros e status sao validados localmente.
- Resumos ate 40 paginas x 100 registros; se ultrapassar, retorna erro sem total parcial. Nao solicitar consultas pesadas repetidamente em producao.
- Listagem sem filtro de templates/contatos pode ser rejeitada com 400 em determinadas versoes; validar caso a caso antes de habilitar em producao.
- Filtros server-side devem ser confirmados por amostra segura. A verificacao do campo status e feita para atendimentos, canais e usuarios; falta definir comportamento real do id_rota se o campo nao vier no retorno.
- Paginacao nao e snapshot transacional. Consultas paginadas em uma fila dinamica podem ter pequenas variacoes e precisam ser cotejadas com dashboard.

## Validacao (sem mudancas no Opa em producao)
1. Executar com Node 24:
   - node --check mcp/integrations/opa-resource-summary.js
   - node --check mcp/integrations/opa-api-tools.js
   - node --test tests/opa-resource-summary.test.mjs
2. No ACS, verificar arquivos ja instalados, secrets existentes, OPA_API_MCP_ENABLED, OPA_API_ALLOWED_SUB, role do usuario e endpoint HTTPS real. Nao imprimir credenciais.
3. Testar GET com no maximo 3 registros por recurso para conferir estrutura, filtros e autorizacao, sem despejar linhas nem dados de clientes no terminal. Se receber erro 400/403, registrar so codigo/status e suspender aquele recurso.
4. Validar com o painel do Opa contagem AG e EA, canais/usuarios ativos, datas e setor, sem inventar equivalencia entre campos desconhecidos.
5. Promover apenas arquivos alterados no container MCP real, com backup/rollback e com os quatro Compose corretos. Nao substituir server.js antigo do GitHub pelo de producao.
6. Verificar status_servidor, tools/list com JWT autorizado e NEGATIVO com bearer Opa, IXC, Wiki, Financeiro, PABX, SHIELD. Confirmar ferramentas antigas sem regressao.
7. So apos todas as consultas centrais necessarias estarem validadas, confirmar se existe plugin Opa separado no ChatGPT; remover apenas esse plugin. **Nunca remover a conexao API Opa usada pelo MCP ou o cliente Opa que usa o MCP**.

## Pendencias da proxima etapa
Confirmar paths + metodos GET dos recursos cliente, etiqueta, notificacao,
departamento, motivo e periodo. Se os endpoints nao existirem como leitura,
registrar como indisponiveis em vez de tentar acesso a banco interno do Opa.

NENHUMA alteracao de producao e autorizada por este README.
