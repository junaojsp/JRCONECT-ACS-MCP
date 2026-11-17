# ACS Visao geral: reduzir repeticao de leituras e estabilizar fontes

## Mudancas preparadas
- `JrcDashboardSnapshot::response` pode reutilizar por **8 segundos** os totais
  agregados `stats` e `uplink`, com APCu opcional. A chave de cache inclui
  geracao do inventario, situacao do overlay SNMP e metadados dos arquivos
  SNMP; a idade do snapshot e recalculada a cada requisicao.
- A lista `recent` **nao e armazenada em APCu**, para nao manter dados de
  dispositivos/assinantes num cache compartilhado de metricas.
- Novo endpoint somente leitura e autenticado `/api/jrc-dashboard-bundle.php`,
  com estatisticas, sinal e dez dispositivos recentes **da mesma geracao**
  quando `jrc_dashboard_cache_enabled=1`. Sem snapshot, retorna 503;
  nunca afirma que contagem parcial e correta.
- O dashboard personalizado **nao e alterado nesta etapa**. O endpoint bundle
  esta disponivel apenas apos instalacao explicita e nao e chamado pela tela
  ate haver uma integracao client-side revisada na pagina real.

## Limites
- A primeira chamada (miss no cache) ainda le todos os registros.
- Sem APCu habilitado no PHP-FPM, os endpoints anteriores permanecem com
  desempenho original; o bundle pode reduzir tres leituras a uma quando
  integrado.
- O carregamento inicial trocando numeros pode envolver outros JS presentes
  somente em producao; este patch NAO resolve sozinho a corrida visual.
- Nao corrige a falta de RX ou as bandas diferentes de classificacao.
  Devem ser unificadas com criterio validado pela operacao e por modelo.
- Inform de TR-069 nao e timestamp de medicao; RX antigo deve ser identificado.
- Nenhuma tarefa ou parametro ONU, rede IXC, dashboard.php ou flag e alterado.

## Proximos passos de homologacao
1. Verificar se a biblioteca `lib/JrcDashboardSnapshot.php` em producao
   coincide com a versao revisada; fazer backup e nao sobrescrever customizacoes.
2. Testar `php -l` nos dois arquivos e `php tests/jrc-dashboard-snapshot.test.php`.
3. Aplicar arquivos com uma implantacao guardada, sem ativar flags.
4. Em sessao autenticada, comparar timestamp e snapshot entre endpoint bundle
   e os tres endpoints existentes; testar estados de cache ausente/obsoleto.
5. Medir Network/Performance do navegador: tempo ate primeiro conteudo,
   tempo do bundle, quantidade de chamadas, tarefas longas JS e LCP;
   corrigir scripts duplicados no dashboard personalizado.
6. Integrar UMA unica rotina frontend ao endpoint bundle com atualizacao
   atomica dos contadores; preservar todo o detalhe do dispositivo e
   os comandos WAN/Wi-Fi/reboot/firmware.
