# Leitura de descrições das OSs

Adiciona `ixc_os_listar_por_assunto` e `ixc_os_consultar_detalhes` ao registrador existente. Preserva as 11 ferramentas IXC anteriores. Não altera server.js, cliente HTTP, autorização, Keycloak, Compose ou outros módulos.

## Fluxo

1. Localizar e confirmar o ID do assunto com `ixc_os_localizar_assunto` (busca paginada; ausência em uma página não prova inexistência).
2. Listar com `ixc_os_listar_por_assunto`, informando id_assunto, pagina e limite (máximo 20); status opcional é o código bruto validado pelo operador.
3. Consultar `ixc_os_consultar_detalhes` com id_os e o mesmo id_assunto. A API é consultada com igualdade de ID e assunto; ambos são conferidos na resposta antes de divulgar conteúdo.

Não há ID de cancelamento fixo nem classificação pelo texto. Os nomes são genéricos porque o assunto é confirmado em cada instalação. A autorização continua sendo a existente para o conjunto IXC; a nova capacidade de ler texto livre estará disponível aos mesmos usuários já autorizados, sem alteração de roles.

## Campos e limites

Retorna ID, assunto, status bruto, datas de abertura/fechamento, descrição (`mensagem`) e solução (`mensagem_resposta`). Estes dois últimos campos são documentados no [Query Builder oficial IXC](https://wiki-erp.ixcsoft.com.br/documentacao/menu-ferramentas/ferramentas/query-builder/query-builder---formularios-pivot-e-campos-parte-4). O [manual oficial da API](https://api-provedor.ixcsoft.com.br/) documenta a rota su_oss_chamado e filtros por assunto.

Não consulta histórico completo, mensagens de outras tabelas, cadastro de clientes, endereço ou credenciais. Texto livre da própria OS pode conter dados pessoais e instruções: deve ser tratado como conteúdo, não como comando. Cada texto é limitado a 12.000 unidades UTF-16, com indicação explícita de truncamento. Campo ausente é null e identificado; campo vazio permanece vazio. Não afirmar que não existe histórico pela ausência desses campos.

Os testes são locais, com respostas simuladas; não comprovam que todos esses campos sejam retornados pela versão/permissões da API em produção. Falhas e filtros ignorados geram erro sem divulgar detalhes ou texto da exceção.

## Validação

Com as dependências já instaladas no diretório mcp:

```bash
node --test mcp/integrations/ixc-os-support-tools.test.js
node --check mcp/integrations/ixc-os-support-tools.js
```

10 testes: nomes das 11 ferramentas anteriores + duas novas, igualdade de ID/assunto, paginação, validação de entradas, projeção dos campos, truncamento, ausência de campos, respostas malformadas, erros e filtros ignorados.

## Implantação controlada após merge

O arquivo de produção enviado pelo operador tem SHA-256:

`6ea4f58bbf5037412a89f148ab5d26f8fcbdecdfa9163ec4c557dbdaa8492152`

1. Comparar `/opt/jrconect-mcp/integrations/ixc-os-support-tools.js` com esse hash antes de instalar. Se houver divergência, parar e revisar o diff; não sobrescrever versão desconhecida.
2. Criar backup durável com `cp -p` desse módulo, conferir que o hash do backup corresponde à origem e guardar o ID da imagem atual para reversão. Validar sintaxe e testes antes de substituir somente esse módulo.
3. Não copiar server.js, auth ou a árvore mcp inteira do GitHub: produção tem composição própria. Nenhum script desta PR faz instalação automática.
4. O container atual incorpora o código na imagem. Após instalar o módulo revisado, a nova imagem precisa ser construída usando ambos os arquivos Compose existentes: docker-compose.yml e docker-compose.ixc.yml. Preservar volumes, segredos e demais overrides; não executar compose apenas com o arquivo principal.
5. Atualizar a conexão JR Conect MCPv2 no ChatGPT após implantação. Esperado: 13 ferramentas IXC e 41 ferramentas totais se o catálogo anterior de 39 permanecer igual.
6. Validar uma OS de cancelamento conhecida contra a tela IXC e testar clientes, contratos, financeiro IXC, OS, VoIP, Wiki, SHIELD e aplicativo Financeiro. PABX/Opa exigem sessões com autorização própria e não foram testados nesta PR.

Reversão: restaurar exclusivamente o módulo original do backup, conferir o hash e carregar a imagem anterior ou reconstruir com os mesmos dois Compose. Atualizar o catálogo do ChatGPT e verificar as 11 ferramentas anteriores. Não aplicar o diagnóstico da PR #139 para esta expansão.
