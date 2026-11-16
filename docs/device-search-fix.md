# Busca do inventário: correção sobre a versão em produção

A fonte `assets/js/devices-fleet.js` deriva do `devices.js` enviado pelo operador em
16/11/2026. Preserva as seis colunas, links, enriquecimento IXC, ações e estilo do
painel personalizado. O instalador a publica como `/var/www/gacs/assets/js/devices.js`.
O `assets/js/devices.js` legado do repositório não deve ser copiado por cima dessa versão.

## Correções

- A mesma correspondência de serial, MAC normalizado, modelo, login e campos existentes
  vale para digitação, atualização automática, ordenação e paginação.
- Trocar página ou tamanho não limpa a busca. Contagem e paginação usam os resultados
  filtrados; a página é ajustada quando a lista diminui. Zero/uma página ocultam os controles
  inclusive em layouts cujo CSS sobrepõe `display`; o texto anterior também é atualizado.
- Carrega lotes numa lista temporária e publica somente o inventário completo. Durante a
  atualização a última lista completa continua pesquisável. Uma falha em lote posterior
  mantém a lista anterior e mostra aviso; a primeira carga não anuncia ausência antes de terminar.
- Atualizações simultâneas são evitadas. Respostas antigas do IXC não alteram uma pesquisa
  mais recente, e o enriquecimento das linhas não sobrescreve contadores de outro resultado.
- Correspondências de cliente retornadas pelo IXC são preservadas ao paginar e ordenar.

Isso corrige a busca sobre o inventário retornado pelo ACS; não cadastra ONUs ausentes nem
muda configuração TR-069. Falhas reais de API aparecem como falha de atualização, não como
inventário completo vazio. Não há alteração de firmware ou de serviços.

## Instalação após merge

```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && python3 scripts/deploy-device-search.py plan && sudo python3 scripts/deploy-device-search.py apply
```

O instalador exige que o JS atual corresponda ao SHA-256 do arquivo enviado
(`463558a1ca09a6e1b9799a29550ac54a71bc51d6ed471e88d44c65303f8e6541`) ou à versão corrigida.
Se houver mudança concorrente, aborta. Faz backup em `/var/backups/jrc-acs-device-search`,
valida PHP, valida JS com Node quando disponível, mantém proprietário/permissões e reverte
as trocas em erro. Em `devices.php`, altera somente a versão no endereço do script para
invalidar o cache do navegador. Não substitui a página nem altera cabeçalho, rodapé ou CSS.

Após `APLICADO_OK`, recarregar Dispositivos com Ctrl+F5 e testar o serial da ONU de bancada,
seu MAC com e sem separadores e o login PPPoE. Confirmar que a busca permanece
na próxima página e após o refresh automático. A existência da ONU depende do inventário real.

Reversão: restaurar `assets/js/devices.js` e `devices.php` do backup informado, verificando
antes que não houve alterações posteriores. Atualizar o navegador com Ctrl+F5.

## Testes

`node --test tests/devices-fleet.test.mjs` cobre 10 cenários de busca/paginação, cargas
incompletas, falhas e concorrência usando DOM e APIs simulados. `python3 tests/device-search-deploy.test.py`
cobre preservação/cache/backup/idempotência, rejeição de JS divergente e rollback. Não há
requisições à rede de produção nos testes. A validação na interface real ocorre após instalar.
