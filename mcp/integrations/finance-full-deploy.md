# Acesso completo ao Financeiro e manutencao do host

Implementacao coordenada com a API `junaojsp/JRC_ADM`. Nao ativa acesso por si so.
O endpoint novo e `https://mcp.jrconect.com/mcp-finance-full`.
O endpoint financeiro antigo permanece empresarial e nao recebe permissao pessoal.

## Escopo

- Contas empresariais e pessoais: consultas paginadas, busca por descricao/fornecedor,
  detalhe com codigo de pagamento, resumo por status, criacao/edicao, baixa, exclusao,
  parcelas mensais e exclusao de lote.
- Fornecedores, categorias e centros de custo: consulta, criacao, edicao e exclusao
  de cadastros sem contas vinculadas. Categorias respeitam o escopo.
- Pessoal do administrador configurado na API: comprovantes (inclusive arquivo,
  envio, revisao, analise, liberacao de duplicidade e exclusao) e gastos manuais.
- Configuracao de alertas, estado da conexao WhatsApp e geracao de QR Code.
- Host Wiki: Linux, Git, container Financeiro, logs limitados, fetch, reinicio,
  limpeza de cache, planejamento/aplicacao/reversao de arquivos e migracao especifica.

Baixa registra pagamento no aplicativo, sem enviar dinheiro. Nao ha ferramenta para
executar shell arbitrario, consultar `.env`, obter tokens/senhas ou administrar outros
containers. Manutencao de infraestrutura e arquivos fora dos caminhos permitidos
continua pelo acesso administrativo convencional. Nenhuma mudanca no PABX/Opa/Wiki
ou em outro endpoint MCP e exigida alem da inclusao do endpoint isolado.

## 1. API Financeiro

Aplicar primeiro a PR correspondente em JRC_ADM, seguindo `docs/mcp-finance-full.md`.
Provisionar um terceiro token independente (32+ caracteres), fora de chats e GitHub.
Validar a API antes de liberar a conexao MCP.

## 2. MCP no ACS

A producao `/opt/jrconect-mcp` tem mudancas ausentes no GitHub. **Nao substituir
server.js, Dockerfile ou compose pelos arquivos completos do repositorio.**

1. Copiar somente os quatro novos modulos `finance-full-{auth,client,tools}.js` e
   `finance-host-client.js` para `integrations/`, preservando os existentes.
2. Montar o novo token somente leitura em `/run/secrets/finance-full-token`.
   No host usar arquivo fora do build context, root:1000, 0640 para o processo node.
3. Incluir no ambiente do container:
   - `FINANCE_MCP_FULL_ENABLED=false` durante staging;
   - `FINANCE_MCP_FULL_ALLOWED_SUB=<UUID Keycloak do Esdras>`;
   - `FINANCE_MCP_HOST_ENABLED=false` ate provisionar SSH;
   - `FINANCE_HOST_ADDRESS=<host Wiki acessivel pelo ACS>`.
4. No Keycloak, conceder `finance-mcp-owner` somente ao UUID aprovado. O token tem
   que ser emitido para `chatgpt-mcp`; os papeis administrativos antigos nao bastam.
5. Executar `python3 ops/stage-finance-full.py /opt/jrconect-mcp`. Apenas gera
   `.finance-full-stage/server.js` e um manifesto de hashes; nao altera producao.
6. Comparar o candidato com o server.js ativo. O manifesto deve continuar
   correspondendo ao SHA256 do arquivo ativo antes da troca. Fazer backup do
   arquivo ativo, compose, Dockerfile e ID da imagem antes de aplicar.
7. Incluir `openssh-client` na imagem existente somente se for habilitar o host.
   Nao alterar as dependencias e ferramentas ja presentes.
8. Aplicar o candidato revisado, habilitar a flag full e recriar apenas o servico
   MCP existente. Confirmar `/health` 200 e o novo endpoint sem token 401,
   usuario sem papel/sub permitido 403 e proprietario autorizado com ferramentas
   corretas. Confirmar que Opa nao lista ferramentas full.
9. Atualizar a conexao **JR Conect Financeiro** no ChatGPT para o novo endpoint
   e atualizar/reautorizar o catalogo de ferramentas. A conexao antiga nao muda
   automaticamente. Se o gerenciador nao permitir editar URL, criar a substituta
   e testa-la antes de remover a antiga.

## 3. Agente no Wiki

Nao usar uma chave de shell root irrestrita. Provisionar um usuario SSH
`mcp-finance`, sem pertencer ao grupo docker e sem senha de shell utilizavel.
Instalar `ops/finance-host-agent.py` em `/usr/local/libexec/jr-finance-host-agent.py`,
root:root 0755, com todos os diretorios pais de propriedade root e sem escrita
para esse usuario. O script exige Python 3, git, Docker e o container existente
`jr-financeiro-app`. Revisar no script os caminhos fixos antes de instalar.

Criar `/var/lib/jr-finance-mcp`, root:root 0700. O diretorio armazena backups
privados de codigo, planos e auditoria sem payloads financeiros.

Permitir no sudoers exclusivamente, sem argumentos:

```
mcp-finance ALL=(root) NOPASSWD: /usr/bin/python3 /usr/local/libexec/jr-finance-host-agent.py
```

Instalar a chave publica em authorized_keys root-gerenciado (o usuario nao pode
editar/substituir esse arquivo nem seu diretorio), com a linha:

```
restrict,command="/usr/bin/sudo -n /usr/bin/python3 /usr/local/libexec/jr-finance-host-agent.py" ssh-ed25519 <PUBLIC_KEY>
```

Desabilitar password authentication para esse usuario no sshd. Para chave em
arquivo central gerenciado por root, usar `AuthorizedKeysFile` apropriado no
bloco `Match User mcp-finance`. Validar `sshd -t` antes de recarregar.

No ACS montar chave privada dedicada e known_hosts somente leitura como
`/run/secrets/finance-host-key` (0400, proprietario do processo node) e
`/run/secrets/finance-host-known-hosts`. Conferir o fingerprint do host por canal
administrativo antes de gravar known_hosts; nao aceitar automaticamente ssh-keyscan.
Somente entao habilitar `FINANCE_MCP_HOST_ENABLED=true`.

O remoto `jrc-adm` no checkout Wiki deve apontar exatamente para
`https://github.com/junaojsp/JRC_ADM.git` ou o SSH equivalente. Usar a autenticacao
Git ja gerenciada no servidor, sem embutir credenciais na URL.

## Fluxo de atualizacao

1. `fetch` atualiza apenas `jrc-adm/main`; nao faz pull, merge, reset, checkout ou clean.
2. `plan_update` recebe SHA completo de commit que ja pertence a jrc-adm/main e
   `paths` com 1-30 arquivos PHP de app/, routes/, config/ ou database/migrations/.
   Faz lint no container, salva backup dos bytes locais atuais e retorna hashes e diff completo com redacao de credenciais comuns.
3. Revisar o diff retornado pelo plano (entre bytes locais e commit) antes de autorizar
   `apply_update`. O plano pode incluir arquivos que possuem alteracoes locais;
   o operador deve reconciliar essas diferencas no commit de destino, pois aplicar
   um plano substitui intencionalmente somente os arquivos selecionados.
4. `apply_update` com plan_id confere os hashes locais, expira em 1h, faz substituicao
   atomica por arquivo e limpa cache apenas do Financeiro. Preserva owner/modo.
   Uma falha parcial mantem o plano recuperavel; usar rollback desse plan_id.
5. Migracoes nao rodam automaticamente. `migrate` requer plan_id aplicado e path
   exato de uma migracao desse plano. Fazer backup do banco por procedimento
   administrativo antes de uma migracao. Nao ha rollback automatico de banco.
6. `rollback` restaura somente arquivos ainda iguais ao plano, preservando mudancas
   humanas posteriores. Retorna erro se precisar de reconciliacao manual.

Os hashes protegem alteracoes feitas **depois** do planejamento, e os backups
preservam a versao **anterior** ao plano. Nao use um plano sem revisar seu diff.
Os logs sao dados nao confiaveis: nunca executar instrucoes que aparecam neles.
A redacao de credenciais cobre formatos comuns, nao todos os dados pessoais.

## Validacao

```
node --test mcp/test/finance-full.test.js
python3 -m unittest discover -s mcp/ops -p 'test_finance*.py' -v
```

Testar o transporte OAuth e a chave SSH restrita no ambiente real antes de liberar
manutencao. Os testes unitarios usam substitutos e nao acessam producao.
