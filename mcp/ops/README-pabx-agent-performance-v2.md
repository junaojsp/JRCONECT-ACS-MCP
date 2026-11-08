# MCP: novo relatorio de desempenho de atendentes (v2)

## Entrega e compatibilidade
O novo tool **pabx_desempenho_atendentes_v2** recebe dias=7|15|30,
somente leitura, sem novos privilegios. Permanece disponivel o
pabx_relatorio_atendentes antigo, sem mudanca de contrato.

O modulo novo eh registrado a partir de registerPabxQueueReport(mcp),
no catalogo somente de leitura ja existente; **nao substituir server.js**
do ACS e nao mexer nas permissoes administrativas nem em Keycloak.

## Contrato necessario no Issabel
Depende de PR complementar do repositorio JRC-PABX:
- autenticacao READ preexistente na API /api/mcp/index.php
- GET action=queue_report_v2&days=7|15|30
- resposta JSON {ok:true,action:"queue_report_v2",result:...}
- resultado gerado pelo scripts/queue-report-v2.py, sem identificadores
  de clientes, CPFs, telefones ou UNIQUEIDs

**A API em producao nao esta versionada no JRC-PABX**. A rota so deve
ser integrada depois de inspeção local, backup, testes e controles de acesso
READ. Este PR nao implanta um endpoint no Issabel automaticamente.

## Indicadores
- chamadas unicas conectadas, tentativas de toque sem resposta,
  chamadas distintas envolvidas e destino da tentativa
- almoco de Maria 12h–13h e Emylly 13h–14h exclusivamente seg-sex
- tempo de toque ate CONNECT e tempo de espera na fila se os campos
  existirem; quantidade de amostras permite avaliar confiabilidade
- abandono/saida sem CONNECT da fila, **nao atribuivel individualmente**
- situacoes desconhecidas permanecem como desconhecidas, nao perdas
- metricas ainda indisponiveis (null): ocupacao real no toque,
  horas disponiveis, chamadas por hora disponivel, resolucao no IXC
  e reincidencia. Para implementar corretamente, precisamos cruzar
  CDR, registros de disponibilidade/pausas e IXC de modo autorizado,
  com privacidade. Nao simular essas metricas no endpoint.

## Implantar com seguranca (depois dos 2 PRs e CI)
1. No Issabel, instalar motor e helper **somente apos** o controller
   de leitura ser revisado, com uma action nova e sem mudar o antigo.
   Testar 7/15/30 e confirmar que nao vazam dados pessoais.
2. No ACS, fazer backup de /opt/jrconect-mcp/integrations/pabx-queue-report.js.
   Copiar apenas:
   - mcp/integrations/pabx-agent-performance-v2.js -> integrations/
   - mcp/integrations/pabx-queue-report.js -> integrations/
   Nao substituir /opt/jrconect-mcp/server.js! Conferir a diferenca do
   modulo existente antes de copiar. Se houver diferencas de producao,
   aplicar somente import e chamada registerAgentPerformanceV2.
3. Validar sintaxe e testes isolados antes de rebuild:
   \`\`\`sh
   node --check integrations/pabx-agent-performance-v2.js
   node --check integrations/pabx-queue-report.js
   node --test integrations/pabx-agent-performance-v2.test.js
   \`\`\`
4. Validar os overlays atuais (base+read-only+admin-guarded, se ja
   instalados). Nao remover overlays, segredos ou conexoes em uso.
5. Atualizar apenas container jrconect-mcp com os overlays existentes,
   conferir logs e tools/list, validar queue_report antigo, queue_report_v2
   novo, Wiki, Opa, Financeiro, Shield e IXC sem regressao.
6. Verificar permissao do read_token existente. Nunca imprimir o segredo.
7. Testar perguntas do usuario: relatorio Maria/Emylly ultimos 7/15/30,
   comparativo sem atribuir eventos de fila como falha da pessoa.

## Rollback
Repor o backup do modulo antigo, remover apenas modulo novo e reconstruir
o container com os mesmos overlays. No Issabel, retirar apenas a rota v2
sem alterar queue_report antigo. Nao apagar logs ou historico de filas.

## Justica e etica de uso
O relatorio descreve eventos tecnicos, nao comportamento humano comprovado.
Uma mesma ligacao pode tocar em dois ramais com ringall, e RINGNOANSWER
pode ser seguido por CONNECT. Nao inferir culpa, nota ou ranking.
Apresentar cobertura real dos logs, campos ausentes e limite das fontes.
