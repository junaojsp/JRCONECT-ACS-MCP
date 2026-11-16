# ACS JR Conect — Coleta em segundo plano (fase 1, opt-in)

## Objetivo
Separar o trabalho pesado de inventário GenieACS da abertura da tela Dispositivos,
sem modificar as rotinas de configuração, firmware ou detalhes individuais das ONUs.

**Não é uma migração do GenieACS.** O GenieACS continua sendo a origem dos
equipamentos; o MySQL do painel recebe uma cópia somente de leitura.

## Fluxo
- \`cron/collect-device-inventory.php\` (somente CLI) coleta a lista completa do GenieACS
  em páginas de 100 e valida IDs duplicados, páginas e queda brusca de volume.
- Opcionalmente, consulta \`radpop_radio_cliente_fibra\` + \`radusuarios\` no IXC para
  enriquecer login e sinal. Requer URL HTTPS e token fornecidos no ambiente seguro
  do processo CLI; **não** lê tokens embutidos em arquivos existentes.
- Publicação em duas tabelas MySQL: registros por geração e ponteiro para a
  geração mais recente. A publicação ocorre em transação; falhas não substituem
  um snapshot íntegro. Mantém-se a geração anterior para leitores concorrentes.
- \`api/get-devices.php\` consulta o snapshot com paginação existente **somente**
  quando \`configurations.jrc_inventory_cache_enabled = '1'\`. Sem flag ou sem
  snapshot válido, preserva a API original como fallback.
- \`api/device-inventory-status.php\` apresenta última coleta, total, quantidade
  de correspondências IXC e indicação de obsolescência para usuários autenticados.
- Nada muda em comandos TR-069, operações de ONU, telas de firmware ou MCPv2.
- Senhas de Wi-Fi e ping simulado não são gravados no cache.

## Validação e implantação segura
Requer PHP CLI, extensão cURL, extensão mysqli, tabelas InnoDB e acesso interno
à NBI GenieACS já configurada no painel. Requer permissão para CREATE TABLE
apenas no primeiro uso; preferir aplicar migração por DBA quando apropriado.

1. Fazer backup do banco e do checkout, revisar as alterações e executar
   \`php -l\` nos PHPs novos e em \`api/get-devices.php\`.
2. Executar \`php tests/jrc-inventory-reader.test.php\`.
3. **Sem alterar a flag**, executar uma vez pelo CLI:
   \`cd /var/www/gacs && php cron/collect-device-inventory.php\`.
   Confirmar saída \`COLETA_OK\`, total esperado, e consultar
   \`/api/device-inventory-status.php\` autenticado.
4. Validar diferenças com inventário GenieACS, inclusive serial de fabricantes
   diferentes; testar busca, paginação, quantidades e sinal antes da ativação.
5. Opcional: para enriquecimento IXC, fornecer variáveis **somente no ambiente
   privado do processo CLI**: \`JRC_IXC_URL=https://SEU_IXC\` e
   \`JRC_IXC_TOKEN=TOKEN_PRIVADO\`. A integração será desativada
   automaticamente caso alguma delas não esteja presente. Não imprimir token
   em logs e não inseri-lo em crontab público ou linha de comando.
6. Depois da homologação, agendar o processo por systemd timer ou cron isolado
   em intervalo inicial de 60 segundos. O \`flock\` impede duas execuções simultâneas.
   Ajustar o intervalo conforme a latência real da API IXC e tamanho da frota.
7. **Somente após inspeção do snapshot:** ativar em \`configurations\` a chave
   \`jrc_inventory_cache_enabled\` para \`1\` pela administração do banco,
   preservar os mesmos domínios, usuários e permissões; testar a interface.
8. Manter monitoramento de idade do cache. Após 15 minutos, status sinaliza
   \`stale\`; o último snapshot íntegro continua disponível, não deve ser
   confundido com uma medição em tempo real.

### Rollback imediato (sem excluir dados)
Definir \`configurations.jrc_inventory_cache_enabled\` para \`0\` ou remover
a chave. A API volta à consulta direta do GenieACS. Desabilitar agendamento
do coletor se necessário. Não é preciso apagar tabelas.

## Limites e pontos obrigatórios de homologação
- A integração IXC usa páginas de 100 registros, máximo de 200, e sinal informado
  em \`sinal_rx\` com data \`data_sinal\`. Não inventa potência quando ausente.
- Se o IXC alterar a API, falhar, truncar ou não permitir a listagem, a coleta
  do GenieACS continua, mas o status registra o problema.
- Sem \`data_sinal\`, um valor não tem comprovação temporal; validar a regra de
  validade de 24h com a operação.
- O coletor não faz varredura ativa das ONUs; reflete parâmetros já registrados.
- Em caso de volume reduzir >25% vs última geração, a coleta é rejeitada e
  permanece a última geração. Exclusões em massa legítimas exigem reavaliação.
- A interface personalizada de produção e a PR #181 de busca/paginação **não**
  são substituídas por este patch; a API preserva o contrato \`devices/hasMore\`.
  Depois da homologação, avaliar melhoria separada de busca paginada no backend.
- Se os resultados exibirem RX "Sem dado", verificar se o IXC fornece sinal
  atualizado, se o serial casa com o MAC de fibra e se o campo \`data_sinal\`
  está atualizado. Não mostrar 0 fracos como equivalência de 0 alarmes.
