# Repositório de firmware — primeira entrega

## Objetivo
Guardar imagens oficiais de firmware no ACS, com metadados por fabricante, modelo, revisão de hardware e versão, **sem enviar firmware a nenhuma ONU** nesta fase.

## Proteções
- Utiliza o login atual do ACS (`requireLogin()`) e token CSRF para uploads.
- Mantém os binários em `/var/lib/jrc-acs-firmware`, fora da raiz pública do servidor web.
- Aceita extensões comuns (.bin, .img, .fw, .trx, .zip, .tar, .npk), limitadas a 256 MiB; não executa nem extrai esses arquivos.
- Calcula o SHA-256 de cada arquivo e valida o hash do fabricante, quando informado.
- Preserva o nome original apenas como metadado; o arquivo é salvo com nome aleatório.
- Bloqueia duplicatas de mesmo SHA-256/modelo/revisão.
- Registra todos os cadastros como **não homologados**.
- Não contém nenhuma operação de GenieACS FS/NBI, CWMP, Download ou atualizações em massa.

## Implantação
Após o merge, no servidor:

```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only && python3 scripts/deploy-firmware-repository.py plan
```

Somente após conferir o resultado `PLAN_OK`:

```bash
cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-firmware-repository.py apply
```

O instalador presume que o PHP roda como `www-data`. Se o processo PHP-FPM/Apache usa outro usuário, execute com `--php-user=USUARIO_REAL`, após verificação. O armazenamento deve ser acessível ao usuário efetivo do PHP. O script cria backup do menu, valida sintaxe PHP e não substitui o `firmware-manager.php` anterior.

## Limitações e fase seguinte
- O catálogo é um índice JSON com lock exclusivo e gravação atômica, adequado à implantação inicial de um operador; migração para banco de dados pode ser planejada antes de operações em escala.
- As permissões existentes de `requireLogin()` são respeitadas. Se houver múltiplos perfis futuramente, exigir RBAC de administrador específico para uploads.
- Valores de `upload_max_filesize` e `post_max_size` no PHP podem exigir ajuste pelo administrador para arquivos maiores.
- Nesta primeira fase não há verificação automática de identidade do firmware, assinatura do fabricante, compatibilidade de modelos ou instalação em dispositivos. Os arquivos são apenas arquivados.
- Próximas fases: integração segura com catálogo real de ONUs; homologação em bancada; envio individual com confirmação; campanhas em massa e painel de firmware desatualizado por último.

## Reversão
O instalador registra o caminho do backup do menu. Antes de reverter, preservar `/var/lib/jrc-acs-firmware` e `firmware-library.php` para não perder o inventário. Para retornar o menu anterior, restaurar apenas o backup do `header.php` criado nesta instalação, após conferir que não houve mudanças posteriores nele.
