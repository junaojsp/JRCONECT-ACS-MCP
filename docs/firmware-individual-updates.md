# Atualização individual de firmware

O catálogo existente é reutilizado. A nova página `/firmware-updates.php` oferece busca de ONU,
seleção de arquivo compatível, revisão e confirmação por serial, envio e histórico.
Não há campanhas nem envio automático. Abrir a página, instalar ou fazer merge não atualiza ONUs.

## Implantação

No checkout usado pelo painel, após merge e `git pull --ff-only`:

```bash
python3 scripts/deploy-firmware-updates.py plan
sudo python3 scripts/deploy-firmware-updates.py apply
```

O instalador usa `/var/www/gacs` (alterável por `--root`), valida PHP antes de gravar,
preserva as páginas personalizadas e adiciona apenas links e correções dos indicadores legados.
Aborta se os pontos de alteração das páginas de firmware não forem reconhecidos.
O atalho na página de equipamento é opcional: se o layout personalizado não for
reconhecido (nenhum ou vários botões candidatos), preserva `device-detail.php`
integralmente, emite um aviso e continua. Nesse caso, abra a central pelo repositório
de firmwares, pela página de bancada ou por `/firmware-updates.php`. Faz backup em
`/var/backups/jrc-acs-firmware-updates`, reverte arquivos em caso de erro e é idempotente.
Não modifica configurações, catálogo, binários, integrações MCP ou serviços.
Um checkout servido diretamente já recebe os arquivos pelo pull; nesse caso o instalador é desnecessário.

## Pré-requisitos operacionais

- PHP 8.1+ com cURL; sessão do painel com perfil `admin` ou `noc`.
- Armazenamento existente `/var/lib/jrc-acs-firmware` gravável pelo PHP.
- NBI local em `http://127.0.0.1:7557`, ou `JRC_FW_NBI_URL` local já utilizado na busca.
- GenieACS FS e URL de download configurados e acessíveis pela ONU. Conferir o `FS_URL_PREFIX`
  do GenieACS se estiver atrás de proxy/NAT. O instalador não altera essa configuração.
- Arquivo oficial exato, próprio para atualização TR-069 desse modelo/revisão (não presumir
  que ZIP/TAR aceito no catálogo é diretamente instalável). Não há extração ou conversão.
- A ONU precisa ter informado fabricante, modelo, revisão, versão, serial, OUI e ProductClass,
  e ter se comunicado nos últimos 15 minutos. Se faltar informação, atualizar o inventário.

## Uso

1. Cadastrar imagem oficial no repositório, informando modelo e revisão exatos.
2. Abrir **Atualizar firmware** no equipamento ou a central pelo repositório/bancada.
3. Escolher **Teste em ONU isolada de bancada** para imagem ainda não homologada.
4. Validar, conferir a versão anterior/alvo e digitar o serial. A confirmação expira em 5 minutos.
5. Confirmar o envio. O backend relê o inventário, verifica SHA-256 e publica o arquivo por NBI;
   depois cria exatamente uma tarefa `download` para aquela ONU. Ela é enfileirada sem aguardar
   uma sessão CWMP na requisição web: executa no próximo Inform. Para antecipar, usar o botão
   já existente **Solicitar Comunicação** nos detalhes do equipamento. Cada execução publica
   um arquivo distinto no FS, sem substituir um arquivo que outra ONU possa estar baixando.
6. Usar **Consultar resultado**. HTTP 200/202 não é sucesso de instalação. Só concluir quando
   não houver download pendente/falha da tarefa e a ONU informar novamente a versão alvo,
   com timestamps de versão e Inform posteriores à solicitação.
7. Testar internet, Wi-Fi, preservação da configuração e retorno ao ACS. Um administrador
   confirma esses testes em **Homologar imagem**, liberando o uso individual em outras ONUs
   com o mesmo fabricante, modelo e revisão.

## Histórico e falhas

O histórico em `updates.json` registra usuário, equipamento, hash, versões, data e ID da tarefa.
Um lock e uma confirmação idempotente evitam repetir o envio ao recarregar ou clicar duas vezes.
O histórico de intenção é persistido antes de qualquer escrita no NBI. Se houver timeout após
o POST, a execução fica inconclusiva; não há retry automático. A página mostra 100 registros
recentes e mantém o histórico completo em disco.

Falhas/inconclusões bloqueiam novo envio à mesma ONU. O NOC deve conferir tarefas, faults,
Download/TransferComplete e a versão real no GenieACS antes de resolver manualmente o registro.
Não remover o registro para simplesmente tentar outra vez. Não há botão de retry/cancelamento
nesta entrega, nem rollback automático: recuperação e downgrade dependem do fabricante.
Se a ONU não atualizar o timestamp de SoftwareVersion, atualizar esse parâmetro no GenieACS;
o módulo continua aguardando em vez de presumir sucesso. A consulta de resultado é manual,
sem worker ou cron nesta primeira entrega.

## Reversão do painel

Restaurar as páginas do diretório de backup após conferir alterações posteriores. Remover apenas
os novos arquivos listados em `new-files.txt`, se não foram modificados desde a instalação.
Preservar `/var/lib/jrc-acs-firmware`. Reverter o painel **não cancela** uma tarefa já enviada:
verificar tarefas pendentes no GenieACS antes da reversão.

## Validação sem equipamentos reais

`php tests/firmware-updates.test.php`, `python3 tests/firmware-updates-deploy.test.py`,
`node --check assets/js/firmware-updates.js` e lint PHP. Os testes usam transporte simulado e
diretórios temporários. O teste em equipamento físico permanece obrigatório para homologar a imagem.
