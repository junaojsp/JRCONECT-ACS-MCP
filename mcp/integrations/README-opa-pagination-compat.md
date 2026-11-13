# Compatibilidade Opa Suite - paginacao e contatos

## Evidencia real do ACS (13/11/2026)
Em consultas somente leitura com options.limit=1:
- atendimentos AG: HTTP 200, um registro, status AG e campo setor presentes
- atendimentos EA: HTTP 200, um registro
- atendimentos PS: HTTP 200, zero registros
- canais ativos: HTTP 200, **quatro registros**
- usuarios ativos: HTTP 200, **oito registros**
- templates: HTTP 200, um registro
- contatos: indisponivel, codigo HTTP desconhecido (o teste anterior nao o mostrou)

Isso nao comprova se options.skip funciona nem se retornou todos os registros. A documentacao oficial descreve limit entre 1 e 1000 e skip como deslocamento:
https://central-opasuite.ixcsoft.com.br/documentacao/api/api-opa%21-suite.html

## Ajustes (somente leitura)
- Se a resposta contiver mais registros do que o limite solicitado: falhar explicitamente, nunca apresentar total parcial.
- Para pagina final nao vazia, confirmar o fim com uma unica consulta adicional usando skip igual ao total observado e limit=1. Se a API repetir registros, falhar.
- Em pagina cheia, exigir identificador estavel _id/id e rejeitar IDs repetidos entre paginas.
- Mensagens devem incluir id_rota igual ao filtro. Se o campo faltar, nao presumir que a resposta foi filtrada.
- Consulta agregada de contatos suspensa ate diagnostico HTTP, rota, formato e permissoes. A categoria continua no catalogo como documentada, com consulta_resumida=false.
- Nao alterar opa_fila_resumo, opa_relatorio_atendimentos, autenticacao, OAuth, IXC, Wiki, Financeiro, PABX ou SHIELD.
- No maximo uma pequena consulta adicional de validacao de skip por relatorio agregado.

## Diagnostico real sem dados pessoais
No ACS, apos obter o script do repositorio, executar:

    python3 scripts/opa-pagination-readonly-probe.py

O script realiza ate 8 GETs de listagem com limit=1, verificando skip para canais, usuarios, templates e contatos. Exibe somente codigo HTTP, quantidade e se o filtro de status e respeitado. Nao imprime tokens, nomes, CPF, IDs, telefones ou mensagens; nao segue redirects. Os resultados devem orientar a decisao de habilitar cada recurso.

## Homologacao e criterios de liberacao
1. Verificar Node 24: node --check mcp/integrations/opa-resource-summary.js e node --check mcp/integrations/opa-api-tools.js.
2. Executar node --test tests/opa-resource-summary.test.mjs no CI, confirmar todos os testes.
3. Rodar o probe com autorizacao somente leitura no ACS, sem exibir registros individuais.
4. Se a API nao comprovar skip, manter consultas agregadas bloqueadas, sem tentativa de contagem alternativa.
5. Reconciliar arquivos reais em /opt/jrconect-mcp com os do repo. Nunca copiar o mcp/server.js historico sobre producao; preservar os Compose ativos.
6. Backup e rollback antes de eventual implantacao, validando /health, OAuth, cliente Opa, IXC, Wiki, Financeiro, PABX e SHIELD.
7. Plugin separado do Opa no ChatGPT so pode ser removido apos as ferramentas centrais estarem em funcionamento.

**Sem deploy, sem reinicio, sem alteracao de token/Compose e sem operacoes de escrita nesta PR.**
