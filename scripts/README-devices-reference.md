# Lista de Dispositivos e menu lateral

Referência visual: https://www.ixcacs.com.br/demo?d=emT8Qefn

Mantém a paleta JR Conect, os links e as permissões existentes. A navegação desktop
usa uma faixa de 56px e expande por hover, foco ou botão de fixação. Em celular,
mantém o comportamento existente. Não modifica detalhes dos aparelhos.

O instalador altera os arquivos **instalados**, pois o layout atual foi atualizado
fora deste repositório. Exige âncoras conhecidas; se não encontrar, para antes de
escrever. Não substitui devices.php pelo exemplar antigo do Git.

## Instalação

```bash
cd /opt/jrc-acs-snmp-source
git checkout main
git pull --ff-only origin main
python3 scripts/deploy-devices-reference.py plan
python3 scripts/deploy-devices-reference.py apply
php -l scripts/audit-devices-inform.php
php scripts/audit-devices-inform.php
```

O apply executa `php -l` e `node --check` nos arquivos preparados **antes** da
primeira escrita, cria cópias ao lado dos arquivos com `.bak-cc-AAAAMMDD-HHMMSS`,
preserva permissões e atualiza os includes para `?v=20261117-cc1`. Em falha de
escrita, restaura os arquivos já tocados. Nenhuma tarefa TR-069 é solicitada.

## Comportamento

- Cabeçalhos: Inter/system-ui 14px, peso 700, sem caixa alta. Monoespaçada só nas células.
- Padrão: IPv6, Descrição e Última pré-configuração ocultos, disponíveis em Configurações.
  Migra uma vez as preferências antigas somando essas três colunas; depois respeita
  as escolhas feitas pelo usuário. Visões personalizadas continuam disponíveis.
- DNS: primeiro endereço e pílula +N; tooltip e descrição acessível dos restantes.
- Linhas compactas; no desktop largo, colunas ópticas permanecem dentro da tabela.
- Chegada continua usando `registered_at`; pré-configuração usa apenas
  `last_bootstrap_at` posterior ao registro. BOOT não é fallback de BOOTSTRAP.
  Ausente, inválido ou igual/anterior ao registro resulta em “—”.

## Limites da verificação

O JS instalado usa `last_inform_at` ou `last_inform_timestamp` para a conexão e
considera online Inform nas últimas 24h. Isso não confirma conexão instantânea.
O endpoint instalado `/api/get-devices-page.php` não está neste repositório;
o patch não muda sua ordenação nem inventa um mapeamento de datas.
É necessário conferir esse endpoint no servidor para confirmar o mapeamento
`_registered`/`_lastBootstrap` e a ordenação global, antes de declarar a auditoria concluída.

`audit-devices-inform.php` consulta o NBI diretamente, com projeção limitada,
seleciona 5 online e 2 offline e imprime `_lastInform` UTC e o horário esperado
em America/Sao_Paulo, além das datas de registro/BOOTSTRAP/BOOT. A comparação
com os sete registros da tela continua pendente até observar os resultados.
Não publicar a saída real da auditoria em PRs ou fixtures.

## Reversão

Use o timestamp exato impresso no apply:

```bash
cd /opt/jrc-acs-snmp-source
python3 scripts/deploy-devices-reference.py rollback --stamp AAAAMMDD-HHMMSS
```

Depois recarregue com Ctrl+F5. Para voltar às preferências anteriores da lista,
remova somente `jrc-devices-hidden-cols-v4` no armazenamento local; a chave v3 foi preservada.
O rollback restaura CSS, JS e os includes PHP, sem alterar dados dos aparelhos.
