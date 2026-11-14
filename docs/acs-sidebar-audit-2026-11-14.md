# Auditoria segura de navegacao ACS (2026-11-14)

## Escopo confirmado

Fonte: `views/layouts/header.php` da branch `main` examinada em 2026-11-14.

Rotas no menu versionado:

| Menu | Rota | Decisao |
| --- | --- | --- |
| Visao Geral | /dashboard.php | Manter |
| Equipamentos | /devices.php | Manter |
| Mapa da Rede | /map.php | Manter; avaliar sobreposicoes de indicadores |
| Enderecamento IP | /ip-addressing.php | Manter ate auditar dependencias |
| Discovery TR-069 | /discovery.php | Manter; corrigir estrutura HTML |
| Central VoIP | /voip-central.php | Manter; nao alterar automacao de DID |
| Configuracoes | /configuration.php | Manter |
| Usuarios | /users.php | Manter |
| Sair | /logout.php | Manter |

As telas chamadas "Diagnostico" e "Provisionamento" nao aparecem como entradas independentes no `header.php` versionado. Isso NAO prova que nao existam na instalacao ativa, em outras interfaces, ou como funcoes internas do GenieACS.

## Problemas comprovados e corrigidos nesta PR

- Link de Discovery TR-069 nao estava dentro do `<li>` padrao do menu.
- Um `<li>` sem fechamento precedia Usuarios.
- Ausencia do fechamento explicito `</ul>` antes do botao de recolher menu.

Mudanca limitada a markup do menu lateral, sem alteracao de rota, permissao, backend, banco, integracoes ou fluxo de provisionamento.

## Pendencias antes de unificar/ocultar funcionalidades

1. Comparar o header versionado com `/var/www/gacs/views/layouts/header.php` em producao; existem personalizacoes locais e o deploy historicamente injeta menu sem substituir o header inteiro.
2. Inventariar todas as rotas PHP efetivamente instaladas, inclusive outras interfaces de GenieACS.
3. Mapear chamadas/links internos para telas de diagnostico e provisionamento; preservar tarefas, presets, scripts, jobs e APIs TR-069.
4. Observar logs anonimizados por periodo definido para confirmar baixo uso. Nao inferir uso de uma rota so pela presenca do arquivo.
5. Propor, em PR separada, somente ocultacao reversivel de itens realmente redundantes. Exigir checklist de testes e rollback.
6. Se desejado, criar no MCPv2 uma consulta somente leitura de inventario; nao adicionar ferramentas ou alterar catalogo OAuth nesta PR.

## Homologacao proposta

- Validar sintaxe PHP do header e HTML da sidebar em ambiente de teste.
- Confirmar navegacao/estado ativo para as nove rotas do menu.
- Validar desktop e mobile, incluindo recolhimento do menu.
- Confirmar que todas as funcoes de ACS, IXC, Central VoIP e MCPv2 continuam inalteradas.
- Conferir se a versao de producao do header coincide com a alterada antes de qualquer deploy.

**Nao fazer deploy ou merge automaticamente.**
