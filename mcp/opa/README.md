# Opa Suite - gerenciamento assistido pela IA

Este diretorio guarda **rascunhos versionados** para revisar fluxos, instrucoes e base de conhecimento da JR Conect. Nao e um mecanismo de publicacao automatica e nao altera o painel do Opa.

## Estado das integracoes

- Confirmado por teste no ACS: GET /api/v1/atendimento, corpo {"filter":{},"options":{"limit":1}}, HTTP 200 e status success usando token API.
- **Nao confirmados**: endpoints de listar/editar fluxos, instrucoes de IA ou bases de conhecimento. Nao presumir rotas, metodos nem payloads.
- A ferramenta MCP de leitura de atendimentos esta atras de `OPA_READ_ENABLED=false` por padrao e necessita revisao de autorizacao antes de habilitar.
- Nenhuma ferramenta de escrita deve ser exposta sem endpoints oficiais verificados, permissao minima, trilha de auditoria, backup/versionamento, modo simulacao e confirmacao humana.

## Procedimento

1. Documentar os fluxos, instrucoes e conhecimento atuais (sem dados pessoais).
2. Validar na documentacao/suporte Opa os endpoints e escopos precisos de leitura/escrita dessas tres areas.
3. Criar um adaptador de leitura com schema e testes em homologacao.
4. Gerar propostas de alteracoes e diffs sem alterar producao.
5. Publicar somente apos aprovacao explicita, com backup e rollback comprovado.

## Seguranca

Nao subir tokens, senhas, CPF, chats, protocolos reais, IDs de clientes, respostas de producao ou exports do Opa para este repositorio publico. Apenas exemplos sinteticos e regras de negocio revisadas. Limitar cada ferramenta por usuario/cliente OAuth, operacao e recurso; identidade autenticada por si so **nao equivale a autorizacao**.
