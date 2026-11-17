# Visao geral (Made4Graph reference): graficos sem consulta direta a frota

## Entrega
- Dois paineis circulares responsivos lado a lado, seguindo a composicao da referencia
  enviada pelo operador, com identidade JR Conect azul-escuro.
- **Fabricantes**: grafico real de distribuicao por fabricante, contado exclusivamente
  por geracao completa do inventario MySQL, com legenda, valores e percentuais.
- **Clientes com e sem TR-069**: painel reservado para um dado que exige
  denominador confiavel (clientes elegiveis/ativos no IXC e vinculo unico
  cliente <-> CPE no GenieACS). O sistema mostra a integracao pendente,
  SEM inventar proporcoes ou confundir equipamentos com contratos/clientes.
- Tabelas de modelos de CPE e fabricantes, como na parte inferior da referencia.
- Uma requisicao de agregados por acesso ao dashboard, com cache APCu de
  300 segundos por `snapshot_id` (se APCu estiver disponivel).
- Snapshot antigo continua identificado como HISTORICO.
- Nao consulta NBI/IXC/OLT e nao atualiza ONUs em request HTTP.

## Segunda fase: fontes para "com e sem TR-069"
Usar uma rotina em segundo plano no coletor para contar todos os clientes
**elegiveis** do IXC de forma distinta, confrontar IDs de clientes
com contratos/CPEs vinculados ao GenieACS e armazenar um snapshot agregado
atomico com timestamp, escopo e estado de integridade. Nunca utilizar
`ixc_matched` como quantidade de clientes ou subtrair total de dispositivos
do total de clientes. Somente quando os totais estiverem confirmados publicar
`with_tr069` e `without_tr069` neste endpoint.

## Implantacao
`python3 scripts/deploy-acs-dashboard-light-charts.py plan`
`python3 scripts/deploy-acs-dashboard-light-charts.py apply`
O instalador atualiza a pagina piloto `dashboard-light.php`, seu JS
e adiciona uma API autenticada. Backup e rollback para erros.
Nenhuma troca de menu, tela original ou APIs de configuracao.
