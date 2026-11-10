# Plano de consolidacao da Wiki JR Conect — auditoria 09/11/2026

> **Status:** proposta de PR; **sem alteracao de producao**. A Wiki.js guarda os artigos fora deste repositorio. O merge **nao edita nem publica** paginas da Wiki. Aplicar por etapas com leitura + hash_original + aprovacao do responsavel.
>
> **Escopo auditado:** 62 artigos retornados pelo catalogo MCP da Wiki (IDs 1–65, com lacunas na numeracao). Conteudo inspecionado por ferramenta de leitura. Status publicado, ACLs, links externos e funcionamento no navegador **nao foram validados pelo MCP**.

## P0 — nao perder procedimentos e permissoes

1. Registrar backup/versionamento do HTML atual dos artigos modificados **fora do repositorio publico**, em local com acesso restrito. Nao versionar HTML de artigos de credenciais, senha, dados pessoais ou backups.
2. Nao reescrever todo o conteudo dos 62 artigos. Para edicao de Wiki.js, ler artigo, conferir hash, alterar somente blocos identificados e reler.
3. Conferir as ACLs e o estado de publicacao do artigo **15** (`atendimento-suporte`) no painel Wiki.js. Tela mostrou "Nao publicado", mas a API de leitura nao retorna o estado: **nao presumir**. Publicar apenas apos confirmar que o acesso e restrito aos colaboradores pertinentes. A API MCP atual **nao dispoe de publicar artigo existente**.
4. Manter as 14 paginas de credenciais sob `tecnico/credenciais/` separadas dos guias publicos/operacionais de equipamentos. Nunca copiar credenciais para pagina comum.

## P1 — eliminar fonte duplicada de procedimentos

| Item | Evidencia | Correcao proposta | Confirmacao |
|---|---|---|---|
| Artigo 23 — `procedimentos-internos` | Conteudo extenso (cerca de 42 mil caracteres), reproduz passos de Atendimento, Cadastro, Contratos, Financeiro, Wi-Fi e NOC | Converter em **indice** por departamento com links canonicos; arquivar o texto antigo em backup interno | Conferir que cada procedimento possui pagina propria e que nenhum passo exclusivo se perdeu |
| Artigos 20, 21 e 22 | Tres paineis antigos de rascunho/previa alem do `home` | Remover de navegacao; **nao excluir** sem confirmar historico/referencias | Revisar status e acessos internos |
| Artigos 24 e 15 | Ramais estava duplicado no artigo 24; ja foi removido, ficando no artigo 15 -> 63 | **Manter** um unico cartao no indice do departamento | Testar menu principal -> indice -> ramais -> voltar |
| Artigos 35 e 64 | Qualifica Livros citado no Comercial e documentado em pagina especifica | Comercial fica com resumo comercial + link para guia 64 | Verificar elegibilidade e passos reais no App |
| Artigo 35 | Secao "Indique e Ganhe" identificada | Pedir confirmacao ao Comercial; se desativada, arquivar/remover so a secao obsoleta | Aprovacao Comercial antes da edicao |

## P2 — navegacao e informacao encontravel

- Pagina inicial `home` (1) e o **menu raiz**. As paginas de departamento (13, 14, 15, 16, 17, 18) sao **indices**; artigos individuais sao a unica fonte do procedimento. Nao forcar que o usuario atravesse mais de um indice.
- Artigo 15: conferir que os 10 artigos sob `atendimento-suporte/` estao representados uma vez por cartao (24, 30, 31, 34, 43, 44, 54, 63, 64, 65). O indice foi atualizado anteriormente; **nao recriar** links ja existentes.
- Artigo 24 (Atendimento): somente regras gerais e eventuais links contextuais; nao duplicar passos detalhados de Ramais, Qualifica ou Clube.
- Artigos 24, 63, 64 e 65 usam retorno a `/home`; verificar coerencia das demais paginas e reparar botoes `Voltar ao departamento` **apenas apos** confirmar a publicacao do indice correspondente.
- Artigo 55 (Central de Consultas): adicionar indexacao e pesquisa para 63, 64 e 65 **se ausentes**, preservando as consultas e os filtros atuais; validar anchors contra artigos reais.
- Conferir links `/pt-br/...` nas paginas antigas de pre-visualizacao (20), sem migrar rotas validas de producao apenas por diferenca de prefixo.

## P3 — governanca e qualidade

- Padrao de cada procedimento: setor, responsavel tecnico, ultima revisao, status (validado / pendente / obsoleto), pre-requisitos, etapas, checklist, referencias e link canonico.
- Diversos artigos continuam exibindo "Revisao tecnica pendente" ou "Revisao final pendente". Esses avisos **nao sao prova de erro**; obter revisao do responsavel antes de remove-los.
- Validar codigos de ramais do PABX em producao (`*8`, `##`, `*2`, `**`), especificando dependencias de grupos e telefones.
- Validar o fluxo exato dos beneficios Qualifica Livros e Clube no aplicativo atual; paginas 64 e 65 indicam passos ainda pendentes de homologacao.
- Revisar regras de descontos, taxas e cancelamentos com Comercial/Financeiro, sem atualizar valores apenas por suposicao.
- Revisar permissoes de artigos tecnicos, publicacao e pesquisa (evitar que resultados exponham titulos ou conteudos confidenciais).

## Execucao controlada

1. **Preflight (somente leitura):** capturar metadados, status e ACLs por interface administrativa; executar o verificador offline sobre um inventario **sanitizado** (ver `scripts/wiki-audit-check.mjs`).
2. **Lote A:** gerar indice do artigo 23 preservando a fonte original em backup de acesso restrito; conferir que os alvos existem.
3. **Lote B:** padronizar navegação (15 e demais indices) e pesquisar 63–65 no artigo 55; testar URL e anchors.
4. **Lote C:** validar os conteúdos comerciais e beneficios com donos; limpar secao obsoleta somente apos aprovacao.
5. **Lote D:** tratar pre-visualizacoes 20–22 como historico/arquivo, sem exclusoes ou redirecionamentos destrutivos.
6. **QA:** testes com perfil de colaborador e perfil sem privilegios; links de ida/volta; mobile; buscas; controles de acesso; comparar antes/depois. Rollback por revisao/restauracao do HTML original, com hashes.
7. **Somente depois:** promover as alteracoes na Wiki por operacoes pontuais; **nao acoplar ao deploy do ACS/MCP**.

## Critérios de aceite

- Nenhuma exclusao ou perda de conteudo sem validacao.
- Cada tema tem uma pagina canonica; indices apresentam links/resumos, nao copias.
- Os 10 artigos do departamento Atendimento e Suporte acessiveis pelo indice.
- Nenhum link interno quebrado ou ancora inexistente nos artigos auditados.
- Publicacao de pagina departamental realizada com ACL correta.
- Artigos de credenciais nao publicados/replicados para publico nao autorizado.
- QA funcional e revisao por area registrados, sem alteracoes em `mcp/server.js` ou outros servicos.

**Limitacao:** Esta PR prepara o plano e o validador. A API MCP Wiki atual so expoe ler/listar/criar/editar conteudo, sem consulta de ACL/publicacao e sem acesso a historico de publicacao. Portanto, "corrigir todas as paginas automaticamente" seria inseguro; as mudancas de conteudo demandam homologacao separada.
