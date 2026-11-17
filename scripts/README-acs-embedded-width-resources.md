# Ações: segunda cópia embutida do adaptador de largura

A #261 removeu o include `jrc-acoes-width.js`. A inspeção posterior do arquivo público em produção confirmou uma segunda cópia **idêntica** anexada ao final de `assets/js/device-control-fix.js`, depois de `JRC_ACOES_LOADER_V1 END`. Por isso o CSS v2 já está servido corretamente, mas a largura inline volta e a sobreposição continua.

O novo instalador retira somente esse sufixo exato, verificado pelo SHA256 do fixture. Preserva byte por byte todo o prefixo de 105.223 bytes, incluindo o loader de Ações e todas as funções operacionais. Verifica JS completo por Node, cria backup, escreve atomicamente e desfaz em caso de falha. Não altera o CSS aprovado ou comandos do CPE. A referência de JS na página já usa versão temporal; recarregar a página cria um documento sem os estilos inline antigos.

```bash
cd /opt/jrc-acs-snmp-source
git checkout main
git pull --ff-only origin main
python3 scripts/deploy-acs-embedded-width-fix.py plan
python3 scripts/deploy-acs-embedded-width-fix.py apply
```

## CPU/RAM: sair do cache vazio para uma leitura direcionada

`audit-acs-device-resources.php` continua somente leitura do cache, sem mudança de comportamento. A nova ferramenta tem outro nome e requer `--refresh` para solicitar uma leitura ao CPE:

```bash
php scripts/read-acs-device-resources.php FHTT99F5A9D0 --refresh
```

Sem a flag, faz somente GET. Com a flag e leituras incompletas, identifica a raiz DeviceInfo já existente, verifica tarefas pendentes e envia **no máximo um** `refreshObject` de DeviceInfo com connection request. Não altera parâmetros, Wi-Fi, WAN, permissões ou firmware; não reinicia o equipamento. O acesso permanece local pelo NBI configurado. Não há novo endpoint web.

- `EXECUTADA`: o NBI respondeu 200; os parâmetros são lidos novamente para verificar o resultado.
- `AGUARDANDO_INFORM`: resposta 202; a leitura está na fila, ainda não é coleta concluída.
- `JA_AGENDADA`: há uma leitura pendente, sem novo POST.
- `DISPONIVEL` em CPU/memória só aparece quando há percentuais normalizados reais.
- Ausência após a leitura exige verificar o que o modelo reporta. Não se inventa um percentual nem se afirma falta de suporte só por ausência no cache.

Referência NBI: https://docs.genieacs.com/en/latest/api-reference.html (refreshObject e respostas 200/202).

## Validação

`python3 tests/device-reference/embedded-width.test.py`: remoção exata, prefixo preservado, reaplicação e recusa de fonte divergente. Também aplicado localmente ao JS completo baixado do endereço de produção: `node --check` passou e o prefixo original foi confirmado idêntico.

PHP de `JrcDeviceResourceProbe`, CLI e testes analisado por php-parser. `tests/device-reference/resource-probe.test.php` cobre cache sem POST, 202 sem falso sucesso, apenas uma leitura escopada, não duplicar tarefa, CPU zero e cálculo de memória; o teste executável ainda requer PHP no servidor. Não houve execução da leitura no CPE ou validação visual pós-deploy nesta sessão. O percentual continua pendente até a ONU retornar os dados.
