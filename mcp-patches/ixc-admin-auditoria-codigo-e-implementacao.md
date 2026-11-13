# Implementação administrativa IXC — pré-requisitos técnicos verificados

Código-base analisado: pacote `jrconect-mcp-ixc-codigo.tar.gz` fornecido pelo operador (cópia anterior às PRs recentes).

## Constatações do código

1. `integrations/ixc-webservice-client.js` exporta `createIxcWebserviceClient()` com **apenas** o método `list(resource, options)`. Não há implementação de criação, atualização, exclusão ou cancelamento.
2. O cliente mantém uma allowlist de recursos, incluindo `cliente`, `cliente_contrato`, `fn_areceber`, `su_oss_chamado`, `su_oss_assunto`, `su_ticket`, `vd_contratos`, `vd_contratos_produtos`, `radusuarios` e outros.
3. O transporte existente envia `ixcsoft: listar` e `GET` com JSON, Basic Auth armazenado em segredo. **Não é seguro inferir** os métodos/headers/payloads de escrita a partir disso.
4. `integrations/ixc-os-support-tools.js` contém `ixc_os_localizar_assunto` e `ixc_os_contagem_assunto_status`. O método de consulta de OS detalhada ainda precisa de validação de campos e política de dados.
5. O pacote é uma fotografia do código e pode estar desatualizado em relação à produção. Validar `server.js`, autenticação e integrações antes de aplicar alterações.

## Implementação requerida na fase 1 — consultas de retenção

- Ferramenta de leitura paginada de OS com assunto 6 ou 27 e intervalo de datas.
- Verificar o filtro **no retorno de cada linha**; se ignorado, falhar sem divulgar dados de outro assunto.
- Retornar ID, assunto, data, status e motivo apenas quando os campos forem confirmados no IXC; tratar texto livre como potencialmente contendo dados pessoais e **não assumir que regex simples anonimiza**.
- Testes automatizados com respostas sintéticas para filtros, paginação, ausência de dados, erros e privacidade.

## Implementação requerida na fase 2 — escrita administrativa

- Criar métodos explícitos no cliente por operação aprovada, após verificar a documentação da API IXC e testar em ambiente isolado.
- `ixc-mcp-admin` + allowlist OAuth + escopo por recurso/ação; `ixc-mcp-read` continua sem escrita.
- Fluxo de preparação de operação: exibir resumo de recurso/ID/campos, gerar confirmação vinculada ao usuário, ação, hash do payload, expiração curta e uso único.
- Fluxo de execução: revalidar autorização, confirmação e estado atual do registro; rejeitar confirmação ausente, expirada, reutilizada ou alterada.
- Auditoria estruturada sem tokens e dados pessoais desnecessários.
- Cancelamento, exclusão, alteração de faturamento e baixa financeira: controles reforçados; sem escrita em massa ou ação automática.
- Testes negativos e de regressão para as integrações já existentes.

## Bloqueio atual

O código de produção do ACS não está versionado integralmente neste repositório. O pacote recebido contém somente uma cópia anterior às últimas alterações. **Não implementar escrita por suposição, nem aplicar um patch cego em produção.**

Próximo insumo necessário: cópia atualizada, sem segredos, dos arquivos `server.js`, `integrations/ixc-webservice-client.js`, `integrations/ixc-webservice-tools.js`, `integrations/ixc-os-support-tools.js` e `integrations/ixc-webservice-auth.js`, mais documentação ou exemplos seguros dos endpoints de escrita IXC habilitados na instalação.
