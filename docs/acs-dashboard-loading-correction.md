# Correção do carregamento do dashboard ACS

## Diagnóstico confirmado
Os contadores ópticos variaram na mesma página, sem navegação. O carregador inline consulta a frota na abertura e a cada 30 segundos, sem proteção explícita contra sobreposição. Há múltiplas rotinas escrevendo indicadores. O relatório jrc-sinal.js usa crítico <= -27 dBm, enquanto dashboard-chart-fix.js usa <= -28 dBm.

O cache existente já deduplica chamadas e controla geração; deve ser preservado. A presença de MutationObserver não comprova que ele seja o gargalo. O tempo de coleta SNMP de aproximadamente 24–36 segundos não representa o tempo de carregamento da tela nem demonstra que TR-069 seja a causa da lentidão.

## Correção proposta
- Uma única rotina responsável pelos contadores, com conjunto completo de registros e mesma versão de snapshot.
- Impedir chamadas sobrepostas e respostas antigas sobrescrevendo resultados novos; liberar bloqueios em falhas.
- Unificar classificação entre cards e relatório. Proposta: crítico <= -27 dBm; fraco > -27 e <= -25 dBm.
- Separar inventário, cobertura de leitura, estado online e alertas ativos. Coleta recente não comprova online. Alertas ativos não equivalem à soma das bandas ópticas.
- Carregar dados armazenados imediatamente e atualizar em segundo plano, informando idade e cobertura incompleta.
- Manter zeros/sentinelas como indisponíveis, sem inventar leituras ou associar seriais por aproximação.
- Revisar os arquivos personalizados realmente implantados antes de alterá-los; eles diferem do repositório inspecionado.

## Preservação
Preservar ações TR-069 de senha, SSID, reboot e configuração WAN, detalhe do dispositivo e gráficos/endpoints de tráfego ao vivo do IXC. Tráfego e potência óptica são métricas distintas.

## Validação necessária
Comparar duração da API, leitura de snapshot e renderização; medir abertura inicial e navegação com cache. Verificar estabilidade dos totais, limites iguais no relatório, ausência de coleta síncrona da frota na abertura e preservação das funções do dispositivo. Aplicação futura deve ter backup e reversão.

## Estado
PR de documentação: registra diagnóstico e plano de correção. Não contém implementação executável, não ativa SNMP e não altera produção. Ganho de desempenho ainda deve ser medido.
