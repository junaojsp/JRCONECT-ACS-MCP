# Aba MCP na dashboard ACS

Este projeto adiciona somente uma interface informativa, sem segredos, logs, acesso ao socket Docker ou chamadas a API Opa Suite.

**Atenção:** a versão de `configuration.php` no ACS em produção tem abas adicionais ausentes neste repositório. **Não substitua esse arquivo pela versão do GitHub.**

Aplicação no ACS:

1. Faça backup/snapshot antes do deploy.
2. Copie `views/partials/mcp_configuration_tab.php` para `/var/www/gacs/views/partials/`.
3. Execute `python3 scripts/install_mcp_tab.py --target /var/www/gacs/configuration.php` no servidor após copiar o script, ou execute apontando para uma cópia para validação.
4. Confira `php -l /var/www/gacs/configuration.php` e a aba Configuração MCP no navegador.

A alteração cria um backup `configuration.php.bak-mcp-YYYYMMDD-HHMMSS` automaticamente. A aba mostra status **Não verificado**, e o endpoint HTTPS é apenas proposto. Não implica MCP funcional, nem integração Opa.
