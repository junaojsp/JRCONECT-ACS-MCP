(() => {
  'use strict';

  if (window.__JRC_MCP_LOCAL_INSTANCES_V13__) return;
  window.__JRC_MCP_LOCAL_INSTANCES_V13__ = true;

  function esc(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function timeAgo(value) {
    const ts = Date.parse(value || '');
    if (!Number.isFinite(ts)) return '—';
    const diff = Math.max(0, Date.now() - ts);
    const sec = Math.floor(diff / 1000);
    if (sec < 10) return 'agora';
    if (sec < 60) return sec + ' s';
    const min = Math.floor(sec / 60);
    if (min < 60) return min + ' min';
    const h = Math.floor(min / 60);
    if (h < 24) return h + ' h';
    return Math.floor(h / 24) + ' d';
  }

  function findTarget() {
    return document.querySelector('#mcp-server-hub') ||
      document.querySelector('main') ||
      document.body;
  }

  async function parseApiResponse(response, fallbackMessage) {
    const raw = await response.text();
    const contentType = String(response.headers.get('content-type') || '').toLowerCase();
    const trimmed = raw.trim().toLowerCase();

    const looksLikeHtml =
      contentType.includes('text/html') ||
      trimmed.startsWith('<!doctype html') ||
      trimmed.startsWith('<html');

    if (response.status === 401 || response.status === 403 || looksLikeHtml) {
      throw new Error('Sessão expirada. Atualize a página e faça login novamente.');
    }

    let data;
    try {
      data = JSON.parse(raw);
    } catch (_) {
      throw new Error(fallbackMessage || 'Resposta inválida do servidor.');
    }

    if (!response.ok || !data?.success) {
      throw new Error(data?.message || fallbackMessage || 'Falha na comunicação com o servidor.');
    }

    return data;
  }


  function integrationIcon(id) {
    const key = String(id || '').toLowerCase();
    if (key.includes('wiki')) return 'bi-book';
    if (key.includes('opa')) return 'bi-chat-dots';
    if (key.includes('chatgpt')) return 'bi-robot';
    return 'bi-plug';
  }

  let cachedInstances = [];
  let cachedStatus = { services: [], connections: [] };

  function buildPanel() {
    const panel = document.createElement('section');
    panel.id = 'jrc-mcp-connections-panel';
    panel.className = 'jrc-mcp-hub';
    panel.innerHTML = `
      <style>
        /* JRC MCP: neutral dark inputs in all management dialogs. */
        #jrc-mcp-connections-panel .jrc-mcp-modal input:not([type="hidden"]),
        #jrc-mcp-connections-panel .jrc-mcp-modal select,
        #jrc-mcp-connections-panel .jrc-mcp-modal textarea {
          background: #101318 !important;
          background-color: #101318 !important;
          color: #e5e7eb !important;
          border: 1px solid #343c48 !important;
          box-shadow: none !important;
          color-scheme: dark;
        }
        #jrc-mcp-connections-panel .jrc-mcp-modal input:focus,
        #jrc-mcp-connections-panel .jrc-mcp-modal select:focus,
        #jrc-mcp-connections-panel .jrc-mcp-modal textarea:focus {
          border-color: #737d8b !important;
          outline: none !important;
          box-shadow: 0 0 0 1px #737d8b !important;
        }
        #jrc-mcp-connections-panel .jrc-mcp-modal input:invalid:not(:placeholder-shown) {
          border-color: #ef4444 !important;
        }
        #jrc-mcp-connections-panel .jrc-mcp-modal input::placeholder,
        #jrc-mcp-connections-panel .jrc-mcp-modal textarea::placeholder {
          color: #7d8795 !important;
          opacity: 1;
        }
        #jrc-mcp-connections-panel .jrc-mcp-integration-card {
          display: flex !important;
          flex-direction: column !important;
          align-items: stretch !important;
        }
        #jrc-mcp-connections-panel .jrc-mcp-integration-card .jrc-mcp-integration-actions {
          display: flex !important;
          flex-direction: row !important;
          align-items: center !important;
          justify-content: flex-start !important;
          flex-wrap: wrap !important;
          gap: 8px !important;
          width: 100% !important;
          margin-top: 9px !important;
        }
        #jrc-mcp-connections-panel .jrc-mcp-integration-actions button {
          flex: 0 0 auto !important;
          width: auto !important;
          max-width: none !important;
          white-space: nowrap !important;
        }
        #jrc-mcp-connections-panel .jrc-mcp-modal__dialog {
          width: min(640px, calc(100vw - 28px));
        }
        #jrc-mcp-connections-panel .jrc-mcp-universal label {
          display: block;
          margin-bottom: 12px;
        }
        #jrc-mcp-connections-panel .jrc-mcp-universal label > span {
          display: block;
          margin-bottom: 6px;
          color: #c5cad3;
          font-size: 13px;
        }
        #jrc-mcp-connections-panel .jrc-mcp-hub__refresh.is-busy,
        #jrc-mcp-connections-panel .jrc-mcp-hub__add:disabled {
          opacity: .7;
          pointer-events: none;
        }
      </style>
      <div class="jrc-mcp-hub__head">
        <div class="jrc-mcp-hub__brand">
          <div class="jrc-mcp-hub__brand-icon"><i class="bi bi-hdd-stack"></i></div>
          <div>
            <div class="jrc-mcp-hub__title">Infraestrutura MCP</div>
            <div class="jrc-mcp-hub__subtitle">
              Servidor MCP local do ACS, integrações conectadas e futuras instâncias.
            </div>
          </div>
        </div>

        <div class="jrc-mcp-hub__actions">
          <button type="button" class="jrc-mcp-hub__add" data-jrc-mcp-add>
            <i class="bi bi-plus-lg"></i>
            <span>Adicionar MCP</span>
          </button>
          <button type="button" class="jrc-mcp-hub__refresh" data-jrc-mcp-refresh>
            <i class="bi bi-arrow-repeat"></i>
            <span>Atualizar</span>
          </button>
        </div>
      </div>

      <div class="jrc-mcp-hub__section">
        <div class="jrc-mcp-hub__section-title">
          <span></span>
          Servidor MCP principal
        </div>
        <div data-jrc-mcp-primary>
          <div class="jrc-mcp-hub__loading">Carregando servidor principal...</div>
        </div>
      </div>

      <div class="jrc-mcp-hub__section" data-jrc-mcp-additional-section hidden>
        <div class="jrc-mcp-hub__section-title">
          <span></span>
          Instâncias MCP adicionais
        </div>
        <div class="jrc-mcp-instances-grid" data-jrc-mcp-additional></div>
      </div>

      <div class="jrc-mcp-modal" data-jrc-mcp-credentials-modal hidden>
        <div class="jrc-mcp-modal__backdrop" data-jrc-mcp-credentials-close></div>
        <div class="jrc-mcp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jrc-mcp-credentials-title">
          <div class="jrc-mcp-modal__head">
            <div id="jrc-mcp-credentials-title" class="jrc-mcp-modal__title">Configurar credenciais OAuth2</div>
            <button type="button" class="jrc-mcp-modal__close" data-jrc-mcp-credentials-close aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
          </div>
          <form class="jrc-mcp-modal__form" data-jrc-mcp-credentials-form autocomplete="off">
            <input name="id" type="hidden">
            <label><span>Client ID do IXC</span><input name="client_id" type="text" autocomplete="off" maxlength="512" required></label>
            <label><span>Client Secret do IXC</span><input name="client_secret" type="password" autocomplete="new-password" maxlength="2048" required></label>
            <p>Os dados são enviados pelo painel autenticado e armazenados fora da pasta pública. Salvar não inicia a autorização OAuth2.</p>
            <div class="jrc-mcp-modal__message" data-jrc-mcp-credentials-message></div>
            <div class="jrc-mcp-modal__footer">
              <button type="button" data-jrc-mcp-credentials-close>Cancelar</button>
              <button type="submit" class="jrc-mcp-modal__save">Salvar credenciais</button>
            </div>
          </form>
        </div>
      </div>
      <div class="jrc-mcp-modal" data-jrc-mcp-edit-modal hidden>
        <div class="jrc-mcp-modal__backdrop" data-jrc-mcp-edit-close></div>
        <div class="jrc-mcp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jrc-mcp-edit-title">
          <div class="jrc-mcp-modal__head"><div id="jrc-mcp-edit-title" class="jrc-mcp-modal__title">Editar conexão MCP</div>
            <button type="button" class="jrc-mcp-modal__close" data-jrc-mcp-edit-close aria-label="Fechar"><i class="bi bi-x-lg"></i></button></div>
          <form class="jrc-mcp-modal__form" data-jrc-mcp-edit-form>
            <input name="id" type="hidden">
            <label><span>Nome</span><input name="name" maxlength="100" required></label>
            <label><span>Descrição</span><textarea name="description" maxlength="240" rows="3"></textarea></label>
            <p>Esta edição altera apenas nome e descrição. Endpoint, autenticação e credenciais permanecem intactos.</p>
            <div class="jrc-mcp-modal__message" data-jrc-mcp-edit-message></div>
            <div class="jrc-mcp-modal__footer"><button type="button" data-jrc-mcp-edit-close>Cancelar</button><button type="submit" class="jrc-mcp-modal__save">Salvar alterações</button></div>
          </form>
        </div>
      </div>
      <div class="jrc-mcp-modal" data-jrc-mcp-modal hidden>
        <div class="jrc-mcp-modal__backdrop" data-jrc-mcp-close></div>
        <div class="jrc-mcp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="jrc-mcp-modal-title">
          <div class="jrc-mcp-modal__head">
            <div>
              <div id="jrc-mcp-modal-title" class="jrc-mcp-modal__title">Adicionar servidor MCP</div>
              <div class="jrc-mcp-modal__subtitle">
                Cadastro universal: HTTP, SSE ou STDIO, de qualquer servidor MCP.
              </div>
            </div>
            <button type="button" class="jrc-mcp-modal__close" data-jrc-mcp-close aria-label="Fechar">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <form class="jrc-mcp-modal__form jrc-mcp-universal" data-jrc-mcp-form>
            <input name="type" type="hidden" value="server">
            <label>
              <span>Nome do servidor</span>
              <input name="name" type="text" maxlength="100" required placeholder="Ex.: MCP do PABX, Grafana, IXC…">
            </label>
            <label>
              <span>Descrição</span>
              <textarea name="description" maxlength="240" rows="3" placeholder="Para que serve este servidor MCP"></textarea>
            </label>
            <label>
              <span>Transporte</span>
              <select name="transport" data-jrc-mcp-transport>
                <option value="streamable_http">HTTP (Streamable)</option>
                <option value="sse">SSE</option>
                <option value="stdio">STDIO (comando local)</option>
              </select>
            </label>
            <div data-jrc-mcp-url-fields>
              <label>
                <span>Endpoint do MCP</span>
                <input name="endpoint" type="url" maxlength="500" placeholder="https://servidor.exemplo/mcp ou http://127.0.0.1:3100/mcp">
              </label>
            </div>
            <div data-jrc-mcp-stdio-fields hidden>
              <label>
                <span>Comando STDIO (sem argumentos)</span>
                <input name="command" maxlength="200" placeholder="/usr/bin/npx">
              </label>
            </div>
            <label>
              <span>Autenticação</span>
              <select name="auth_type">
                <option value="none">Sem autenticação</option>
                <option value="bearer">Bearer token</option>
                <option value="oauth2">OAuth2</option>
                <option value="api_key">API key</option>
              </select>
            </label>
            <div class="jrc-mcp-modal__note">
              <i class="bi bi-info-circle"></i>
              Serve para qualquer servidor MCP. Este cadastro não guarda token nem inicia a conexão; depois use Verificar no card.
            </div>

            <div class="jrc-mcp-modal__message" data-jrc-mcp-form-message></div>

            <div class="jrc-mcp-modal__footer">
              <button type="button" class="jrc-mcp-modal__cancel" data-jrc-mcp-close>Cancelar</button>
              <button type="submit" class="jrc-mcp-modal__save">
                <i class="bi bi-check-lg"></i>
                Salvar MCP
              </button>
            </div>
          </form>
        </div>
      </div>
    `;
    return panel;
  }

  function buildIntegration(serviceOrConnection, kind) {
    const isService = kind === 'service';
    const id = serviceOrConnection.id || serviceOrConnection.client_id || '';
    const name = serviceOrConnection.name || serviceOrConnection.client_id || id || 'Integração';
    const status = isService
      ? (serviceOrConnection.status === 'online' ? 'online' : 'offline')
      : 'online';
    const statusLabel = isService
      ? (serviceOrConnection.status === 'online' ? 'Online' : 'Offline')
      : 'Online';

    let subtitle = '';
    let chips = [];

    if (isService) {
      subtitle = String(id).toLowerCase().includes('wiki')
        ? 'Integração interna do servidor MCP'
        : (serviceOrConnection.type || 'Componente interno');

      const tools = Array.isArray(serviceOrConnection.tools) ? serviceOrConnection.tools : [];
      chips = tools.map(tool => esc(tool));
    } else {
      const auth = serviceOrConnection.auth_type === 'oauth'
        ? 'OAuth / Keycloak'
        : serviceOrConnection.auth_type === 'token'
          ? 'Token MCP'
          : (serviceOrConnection.auth_type || 'Autenticação MCP');

      subtitle = 'Cliente externo conectado ao JR Conect MCPv2';
      chips = [
        auth,
        'Última comunicação: ' + timeAgo(serviceOrConnection.last_seen),
        Number(serviceOrConnection.requests || 0).toLocaleString('pt-BR') + ' req.'
      ];
    }

    return `
      <article class="jrc-mcp-integration-card">
        <div class="jrc-mcp-integration-card__icon">
          <i class="bi ${integrationIcon(id)}"></i>
        </div>
        <div class="jrc-mcp-integration-card__body">
          <div class="jrc-mcp-integration-card__top">
            <div>
              <div class="jrc-mcp-integration-card__name">${esc(name)}</div>
              <div class="jrc-mcp-integration-card__subtitle">${esc(subtitle)}</div>
            </div>
            <span class="jrc-mcp-status jrc-mcp-status--${status}">${esc(statusLabel)}</span>
          </div>
          <div class="jrc-mcp-integration-card__chips">
            ${chips.length
              ? chips.map(chip => '<span class="jrc-mcp-tool">' + esc(chip) + '</span>').join('')
              : '<span class="jrc-mcp-tool jrc-mcp-tool--muted">Sem detalhes adicionais</span>'}
          </div>
        </div>
        <div class="jrc-mcp-integration-actions">
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:auto;min-height:28px;padding:5px 10px;font-size:11px;background:#171c24!important;border:1px solid #343c48!important;color:#e5e7eb!important;box-shadow:none!important;" data-jrc-mcp-check-service="${esc(id)}">Verificar status</button>
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:auto;min-height:28px;padding:5px 10px;font-size:11px;background:#171c24!important;border:1px solid #343c48!important;color:#e5e7eb!important;box-shadow:none!important;" data-jrc-mcp-details="${esc(id)}">Detalhes</button>
        </div>
      </article>
    `;
  }

  function renderPrimary(panel, statusData) {
    const root = panel.querySelector('[data-jrc-mcp-primary]');
    cachedStatus = statusData || { services: [], connections: [] };
    const services = Array.isArray(statusData?.services) ? statusData.services : [];
    const connections = Array.isArray(statusData?.connections) ? statusData.connections : [];

    const core = services.find(service => String(service?.id || '').toLowerCase() === 'mcp-core') || {
      id: 'mcp-core',
      name: 'JR Conect MCPv2',
      status: 'offline',
      tools: [],
    };

    const integrations = [];

    services
      .filter(service => service !== core)
      .forEach(service => integrations.push(buildIntegration(service, 'service')));

    const orderedConnections = [...connections].sort((a, b) => {
      const weight = value => {
        const id = String(value?.id || value?.client_id || '').toLowerCase();
        if (id.includes('opa')) return 0;
        if (id.includes('chatgpt')) return 1;
        return 2;
      };
      return weight(a) - weight(b);
    });

    orderedConnections.forEach(connection => {
      integrations.push(buildIntegration(connection, 'connection'));
    });

    const coreStatus = core.status === 'online' ? 'online' : 'offline';
    const coreLabel = coreStatus === 'online' ? 'Online' : 'Offline';

    root.innerHTML = `
      <article class="jrc-mcp-primary-card">
        <div class="jrc-mcp-primary-card__header">
          <div class="jrc-mcp-primary-card__identity">
            <div class="jrc-mcp-primary-card__icon"><i class="bi bi-server"></i></div>
            <div>
              <div class="jrc-mcp-primary-card__name">JR Conect MCPv2</div>
              <div class="jrc-mcp-primary-card__subtitle">Servidor MCP local hospedado no servidor ACS</div>
            </div>
          </div>
          <span class="jrc-mcp-status jrc-mcp-status--${coreStatus}">${coreLabel}</span>
        </div>

        <div class="jrc-mcp-primary-card__meta">
          <div>
            <span>Container</span>
            <strong>jrconect-mcp</strong>
          </div>
          <div>
            <span>Porta local</span>
            <strong>127.0.0.1:3100</strong>
          </div>
          <div>
            <span>Endpoint</span>
            <strong>mcp.jrconect.com/mcp</strong>
          </div>
        </div>

        <div class="jrc-mcp-primary-card__tools">
          ${Array.isArray(core.tools) && core.tools.length
            ? core.tools.map(tool => '<span class="jrc-mcp-tool">' + esc(tool) + '</span>').join('')
            : '<span class="jrc-mcp-tool jrc-mcp-tool--muted">Nenhuma ferramenta principal informada</span>'}
        </div>

        <div class="jrc-mcp-primary-card__integrations-head">
          <span>Integrações deste servidor</span>
          <small>${integrations.length} conectada(s)</small>
        </div>

        <div class="jrc-mcp-integrations-grid">
          ${integrations.length
            ? integrations.join('')
            : '<div class="jrc-mcp-hub__empty">Nenhuma integração conectada.</div>'}
        </div>
      </article>
    `;
  }

  function isExternalMcpInstance(instance) {
    const endpoint = String(instance?.endpoint || '').replace(/\/+$/, '').toLowerCase();
    return instance?.type === 'external' ||
      endpoint === 'https://cda.jrconect.com/services/mcp';
  }

  function isManagedPabx(instance) {
    return instance?.type === 'managed' &&
      String(instance?.service || 'pabx').toLowerCase() === 'pabx';
  }

  function mcpCoreOnline() {
    const core = (cachedStatus.services || []).find(function (service) {
      return String(service?.id || '').toLowerCase() === 'mcp-core';
    });
    return !!(core && core.status === 'online');
  }

  function pabxService() {
    return (cachedStatus.services || []).find(function (service) {
      const hay = String(service?.id || '') + ' ' + String(service?.name || '');
      return /pabx|issabel/i.test(hay);
    }) || null;
  }

  function managedPabxState(instance) {
    const service = pabxService();
    const tools = service && Array.isArray(service.tools) ? service.tools.length : Math.max(0, Number(instance.tool_count) || 0);
    const api = String(instance?.connection_status || '');
    if (api === 'error' || (service && service.status && service.status !== 'online')) {
      return {
        css: 'offline',
        label: 'Offline',
        detail: 'MCP principal ou módulo PABX indisponível'
      };
    }
    if (api === 'connected' || (service && service.status === 'online') || mcpCoreOnline()) {
      return {
        css: 'online',
        label: 'Online',
        detail: tools
          ? ('MCP principal online · ' + tools + (tools === 1 ? ' ferramenta' : ' ferramentas'))
          : 'Integração no MCP principal · 127.0.0.1:3100'
      };
    }
    return {
      css: 'registered',
      label: 'Cadastrado',
      detail: 'Aguardando o MCP principal'
    };
  }

  function instanceState(instance) {
    if (isExternalMcpInstance(instance)) return externalTestState(instance);
    if (isManagedPabx(instance)) return managedPabxState(instance);
    return {
      css: 'registered',
      label: 'Cadastrado',
      detail: instance.local_address || ('127.0.0.1:' + (instance.port || '—'))
    };
  }

  function externalTestState(instance) {
    if (instance?.connection_status === 'connected') {
      const count = Math.max(0, Number(instance.tool_count) || 0);
      return {
        css: 'online',
        label: 'Online',
        detail: 'Último teste OK ' + timeAgo(instance.last_test_at) + ' · ' +
          count + (count === 1 ? ' ferramenta' : ' ferramentas')
      };
    }
    if (instance?.connection_status === 'error') {
      return {
        css: 'offline',
        label: 'Falha no último teste',
        detail: 'Teste ' + timeAgo(instance.last_test_at)
      };
    }
    const isIxc = /cda\.jrconect\.com\/services\/mcp/i.test(String(instance?.endpoint || ''));
    return {
      css: 'registered',
      label: isIxc ? 'IXC externo' : 'Cadastrado',
      detail: 'Aguardando teste de conexão'
    };
  }

  function renderAdditional(panel, instances) {
    const section = panel.querySelector('[data-jrc-mcp-additional-section]');
    const root = panel.querySelector('[data-jrc-mcp-additional]');
    const rows = Array.isArray(instances) ? instances : [];
    cachedInstances = rows;

    if (!rows.length) {
      section.hidden = true;
      root.innerHTML = '';
      return;
    }

    section.hidden = false;
    root.innerHTML = rows.map(instance => `
      <article class="jrc-mcp-instance-card">
        <div class="jrc-mcp-instance-card__header">
          <div class="jrc-mcp-instance-card__identity">
            <div class="jrc-mcp-instance-card__icon"><i class="bi bi-server"></i></div>
            <div>
              <div class="jrc-mcp-instance-card__name">${esc(instance.name || 'Instância MCP')}</div>
              <div class="jrc-mcp-instance-card__subtitle">${esc(instance.description || 'Instância MCP local planejada')}</div>
            </div>
          </div>
          <span class="jrc-mcp-status jrc-mcp-status--${instanceState(instance).css}">${esc(instanceState(instance).label)}</span>
        </div>

        <div class="jrc-mcp-instance-card__meta">
          <span><i class="bi bi-box"></i> ${esc(isExternalMcpInstance(instance) ? (instance.auth_type || 'OAuth2') : instance.type === 'managed' ? 'Integração MCP existente' : instance.type === 'stdio' ? 'STDIO (não iniciado)' : (instance.container || '—'))}</span>
          <span><i class="bi bi-ethernet"></i> ${esc(isExternalMcpInstance(instance) ? instanceState(instance).detail : (instance.type === 'managed' ? instanceState(instance).detail : (instance.local_address || ('127.0.0.1:' + (instance.port || '—')))))}</span>
          <span><i class="bi bi-link-45deg"></i> ${esc(instance.endpoint || (instance.type === 'managed' ? 'Ferramentas pabx_* no MCP principal' : instance.command || '—'))}</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px;">
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:auto;min-height:28px;padding:5px 10px;font-size:11px;background:#171c24!important;border:1px solid #343c48!important;color:#e5e7eb!important;box-shadow:none!important;" data-jrc-mcp-edit="${esc(instance.id)}">Editar</button>
          ${instance.type === 'managed' ? `<button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;width:auto;min-height:28px;padding:5px 10px;font-size:11px;background:#171c24!important;border:1px solid #343c48!important;color:#e5e7eb!important;" data-jrc-mcp-test-managed="${esc(instance.id)}">Verificar MCP</button>` : ''}
          ${(instance.type === 'local' || !instance.type) ? `<button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:auto;min-height:28px;padding:5px 10px;font-size:11px;background:#171c24!important;border:1px solid #343c48!important;color:#e5e7eb!important;box-shadow:none!important;" data-jrc-mcp-test-local="${esc(instance.id)}">Testar MCP principal</button>` : ''}
        </div>
        ${isExternalMcpInstance(instance) && (instance.auth_type === 'oauth2' || !instance.auth_type) ? `<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px;">
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:auto;min-height:28px;padding:5px 10px;font-size:11px;line-height:1.2;background:#171c24!important;border:1px solid #343c48!important;color:#e5e7eb!important;box-shadow:none!important;" data-jrc-mcp-credentials="${esc(instance.id)}">Configurar OAuth2</button>
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:auto;min-height:28px;padding:5px 10px;font-size:11px;line-height:1.2;background:#171c24!important;border:1px solid #343c48!important;color:#e5e7eb!important;box-shadow:none!important;" data-jrc-mcp-authorize="${esc(instance.id)}" ${instance.credentials_saved ? '' : 'disabled'}>Autorizar IXC</button>
          <button type="button" class="jrc-mcp-hub__refresh" style="display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:auto;min-height:28px;padding:5px 10px;font-size:11px;line-height:1.2;background:#171c24!important;border:1px solid #343c48!important;color:#e5e7eb!important;box-shadow:none!important;" data-jrc-mcp-test="${esc(instance.id)}" ${instance.credentials_saved ? '' : 'disabled'}>Testar conexão</button>
          <small>${instance.credentials_saved ? (instance.connection_status === 'connected' ? 'Credenciais e conexão validadas no último teste.' : 'Credenciais salvas — autorização a confirmar') : 'Credenciais pendentes'}</small>
        </div>` : ''}
      </article>
    `).join('');
  }


  // JRC_MCP_INTEGRATION_MAP_V2
  // This is a topology inventory, NOT proof of authentication or write access.
  // The ACS has no API to enumerate ChatGPT-only installed plugins.
  function showMcpTopology(panel) {
    const grid = panel.querySelector('.jrc-mcp-integrations-grid');
    const parent = panel.querySelector('.jrc-mcp-primary-card__integrations-head');
    if (!grid || !parent) return;

    let inventory = panel.querySelector('[data-mcp-topology-summary]');
    if (!inventory) {
      inventory = document.createElement('div');
      inventory.setAttribute('data-mcp-topology-summary', 'true');
      inventory.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:9px;margin:12px 0 15px';
      const groups = [
        ['Infraestrutura ACS', 'ACS · hospeda o JR Conect MCPv2', 'Servidor ACS é o host do MCP. O endpoint /mcp-acs é um serviço de monitoramento independente; sua disponibilidade deve ser validada separadamente.'],
        ['Direto no MCP central', 'Wiki · SHIELD · Financeiro · PABX (consultas validadas)', 'Ferramentas no endpoint /mcp; operações administrativas exigem autorização.'],
        ['Clientes conectados ao MCP', 'ChatGPT · Opa Suite', 'São consumidores do MCP, não servidores integrados. O Opa não recebe ferramentas administrativas.'],
        ['Somente no ChatGPT', 'Inventário não sincronizado', 'O ACS não consulta a lista de plugins exclusivos do ChatGPT. O Financeiro independente pode ser mantido como legado até validar a migração.']
      ];
      groups.forEach(([title, names, explanation]) => {
        const item = document.createElement('section');
        item.style.cssText = 'border:1px solid #343c48;border-radius:9px;padding:11px 12px;background:#10151d;min-width:0';
        const heading = document.createElement('strong');
        heading.style.cssText = 'display:block;font-size:12px;margin-bottom:7px;color:#e5e7eb';
        heading.textContent = title;
        const systems = document.createElement('div');
        systems.style.cssText = 'font-size:12px;font-weight:600;margin-bottom:5px;color:#dce3ea';
        systems.textContent = names;
        const note = document.createElement('div');
        note.style.cssText = 'font-size:11px;color:#9aa8b7;line-height:1.45';
        note.textContent = explanation;
        item.append(heading, systems, note);
        inventory.appendChild(item);
      });
      parent.insertAdjacentElement('afterend', inventory);
    }


    // JRC_MCP_CARDS_V3: identifiers show topology, not administrative verification.
    // Add the host ACS and SHIELD only when the central MCP card is rendered.
    function createSystemCard(key, name, subtitle, icon, details, status) {
      const card = document.createElement('article');
      card.className = 'jrc-mcp-integration-card';
      card.dataset.mcpSystem = key;
      card.style.cssText = 'min-width:0;box-sizing:border-box';
      const iconBox = document.createElement('div');
      iconBox.className = 'jrc-mcp-integration-card__icon';
      const iconNode = document.createElement('i');
      iconNode.className = 'bi ' + icon;
      iconBox.appendChild(iconNode);
      const body = document.createElement('div');
      body.className = 'jrc-mcp-integration-card__body';
      const top = document.createElement('div');
      top.className = 'jrc-mcp-integration-card__top';
      const identity = document.createElement('div');
      const title = document.createElement('div');
      title.className = 'jrc-mcp-integration-card__name';
      title.textContent = name;
      const sub = document.createElement('div');
      sub.className = 'jrc-mcp-integration-card__subtitle';
      sub.textContent = subtitle;
      identity.append(title, sub);
      const state = document.createElement('span');
      state.className = 'jrc-mcp-status jrc-mcp-status--' + (status === 'Online' ? 'online' : 'registered');
      state.textContent = status;
      top.append(identity, state);
      const chips = document.createElement('div');
      chips.className = 'jrc-mcp-integration-card__chips';
      details.forEach(detail => {
        const span = document.createElement('span');
        span.className = 'jrc-mcp-tool';
        span.textContent = detail;
        chips.appendChild(span);
      });
      body.append(top, chips);
      card.append(iconBox, body);
      return card;
    }
    const main = panel.querySelector('.jrc-mcp-primary-card');
    const coreOnline = !!main?.querySelector('.jrc-mcp-primary-card__header .jrc-mcp-status--online');
    if (main && !grid.querySelector('[data-mcp-system="acs"]')) {
      grid.appendChild(createSystemCard('acs', 'Servidor ACS',
        'Hospeda o JR Conect MCPv2; monitoramento /mcp-acs separado',
        'bi-hdd-network', ['Infraestrutura central', 'MCP local 127.0.0.1:3100',
        'Status do host/monitoramento nao aferido aqui'], coreOnline ? 'Online' : 'Hospedagem'));
    }
    if (main && !grid.querySelector('[data-mcp-system="shield"]')) {
      grid.appendChild(createSystemCard('shield', 'JRC SHIELD',
        'Integrado ao MCP central com permissoes OAuth',
        'bi-shield-check', ['4 consultas', '3 acoes administrativas',
        'Execucao administrativa ainda nao validada'], 'Online'));
    }

    // Express the relationship and capabilities INSIDE each existing card.
    grid.querySelectorAll('.jrc-mcp-integration-card').forEach(card => {
      const nameEl = card.querySelector('.jrc-mcp-integration-card__name');
      if (!nameEl) return;
      const name = (nameEl.textContent || '').toLowerCase();
      let detail = '';
      if (name.includes('wiki')) detail = 'Centralizado no MCP · 5 ferramentas';
      else if (name.includes('financeiro')) detail =
        'Centralizado no MCP · 4 consultas + 8 acoes administrativas · /mcp-finance mantido como legado';
      else if (name.includes('chatgpt')) detail =
        'Cliente OAuth do MCP · nao e servidor';
      else if (name.includes('opa suite')) detail =
        'Cliente do MCP por token · sem ferramentas SHIELD/Financeiro';
      else if (name.includes('pabx')) detail =
        'Integracao parcial ao MCP · ferramentas administrativas exigem validacao';
      else if (name.includes('shield')) detail = 'Centralizado no MCP · 7 ferramentas';
      else if (name.includes('acs')) detail = 'Hospedagem do MCP · monitoramento separado';
      if (!detail) return;
      let badge = card.querySelector('[data-mcp-card-detail]');
      if (!badge) {
        badge = document.createElement('div');
        badge.dataset.mcpCardDetail = 'true';
        badge.style.cssText = 'color:#9fb0c5;font-size:11px;line-height:1.45;padding:5px 0 0';
        const chips = card.querySelector('.jrc-mcp-integration-card__chips');
        if (chips) chips.insertAdjacentElement('afterend', badge);
        else card.appendChild(badge);
      }
      if (badge.textContent !== detail) badge.textContent = detail;
      if (name.includes('financeiro')) {
        const sub = card.querySelector('.jrc-mcp-integration-card__subtitle');
        if (sub && sub.textContent.includes('MCP isolado')) {
          sub.textContent = 'Integrado ao MCP central; endpoint independente preservado';
        }
      }
    });
    const small = main?.querySelector('.jrc-mcp-primary-card__integrations-head small');
    if (small) {
      const total = grid.querySelectorAll('.jrc-mcp-integration-card, .jrc-mcp-instance-card').length;
      const label = total + ' cards (inclui infraestrutura e clientes)';
      if (small.textContent !== label) small.textContent = label;
    }


    // JRC_OPA_LOCAL_STAGED_CARD_V1
    // The modules are installed on the ACS host, NOT connected to the live MCP yet.
    // Do not label this system Online until OAuth tools/list and API access are tested.
    if (main && !grid.querySelector('[data-mcp-system="opa-api-local"]')) {
      grid.appendChild(createSystemCard('opa-api-local',
        'Opa Suite — API administrativa',
        'Adaptador instalado localmente no ACS; integrado e funcionando no MCP central',
        'bi-chat-dots',
        ['Local: ACS / integrations', 'API GET validada: HTTP 200',
         '2 consultas registradas; 5 operacoes administrativas pendentes', 'Consulta MCP validada: HTTP 200'],
        'Online'
      ));
    }


    // JRC_IXC_CENTRAL_CARD_V1 — deployment is not functional verification.
    // Display IXC separately from the former native IXC OAuth integration.
    if (main && !grid.querySelector('[data-mcp-system="ixc-webservice"]')) {
      grid.appendChild(createSystemCard('ixc-webservice',
        'IXC Provedor — API Webservice',
        'Integracao no MCP central hospedado no ACS',
        'bi-database-check',
        ['API: /webservice/v1', 'Keycloak: ixc-mcp-admin',
         '3 consultas funcionando', 'Operacoes de escrita: pendentes',
         '4 ferramentas MCP validadas'],
        'Online'
      ));
    }

    const cards = panel.querySelectorAll('.jrc-mcp-integration-card, .jrc-mcp-instance-card');
    cards.forEach(card => {
      if (card.querySelector('[data-mcp-topology-label]')) return;
      const nameEl = card.querySelector('.jrc-mcp-integration-card__name, .jrc-mcp-instance-card__name');
      const subtitle = card.querySelector('.jrc-mcp-integration-card__subtitle, .jrc-mcp-instance-card__subtitle');
      if (!nameEl || !subtitle) return;
      const name = (nameEl.textContent || '').toLowerCase();
      let label = '';
      if (name.includes('wiki') || name.includes('shield') || name.includes('financeiro')) {
        label = 'Direto no MCP central';
      } else if (name.includes('pabx')) {
        label = 'MCP central · consultas validadas';
      } else if (name.includes('chatgpt') || name.includes('opa suite')) {
        label = 'Cliente do MCP';
      }
      if (!label) return;
      const tag = document.createElement('span');
      tag.setAttribute('data-mcp-topology-label', 'true');
      tag.style.cssText = 'display:inline-block;font-size:10px;color:#b8c6d4;border:1px solid #384451;padding:3px 6px;border-radius:5px;margin-top:5px';
      tag.textContent = label;
      subtitle.insertAdjacentElement('afterend', tag);
    });
  }
  function monitorMcpTopology(panel) {
    if (!panel.__mcpTopologyObserver) {
      const observer = new MutationObserver(() => showMcpTopology(panel));
      observer.observe(panel, {childList: true, subtree: true});
      panel.__mcpTopologyObserver = observer;
    }
    showMcpTopology(panel);
  }

  async function load(panel) {
    const primary = panel.querySelector('[data-jrc-mcp-primary]');

    try {
      const controller = new AbortController();
      const timer = window.setTimeout(function () { controller.abort(); }, 12000);
      const [statusResponse, instancesResponse] = await Promise.all([
        fetch('/api/mcp-connections.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          headers: { Accept: 'application/json' }
        }),
        fetch('/api/mcp-instances.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          headers: { Accept: 'application/json' }
        })
      ]).finally(function () { window.clearTimeout(timer); });

      const statusData = await parseApiResponse(
        statusResponse,
        'Não foi possível consultar o JR Conect MCPv2.'
      );

      const instancesData = await parseApiResponse(
        instancesResponse,
        'Não foi possível consultar instâncias MCP adicionais.'
      );

      renderPrimary(panel, statusData);

      // JRC_FINANCE_CARD_V1
      fetch('/api/mcp-finance-status.php', {
        credentials: 'same-origin',
        cache: 'no-store'
      })
        .then(r => r.ok ? r.json() : null)
        .then(data => {
          if (!data || data.success !== true) return;
          const grid = panel.querySelector('.jrc-mcp-integrations-grid');
          if (!grid || grid.querySelector('[data-finance-mcp]')) return;

          const online = data.endpoint_reachable === true;
          const card = document.createElement('article');
          card.className = 'jrc-mcp-integration-card';
          card.setAttribute('data-finance-mcp', 'true');
          card.innerHTML = `
            <div class="jrc-mcp-integration-card__icon">
              <i class="bi bi-wallet2"></i>
            </div>
            <div class="jrc-mcp-integration-card__body">
              <div class="jrc-mcp-integration-card__top">
                <div>
                  <div class="jrc-mcp-integration-card__name">JR Conect Financeiro</div>
                  <div class="jrc-mcp-integration-card__subtitle">
                    Financeiro empresarial — MCP isolado
                  </div>
                </div>
                <span class="jrc-mcp-status jrc-mcp-status--${online ? 'online' : 'offline'}">
                  ${online ? 'Online' : 'Indisponível'}
                </span>
              </div>
              <div class="jrc-mcp-integration-card__chips">
                <span class="jrc-mcp-tool">OAuth / Keycloak</span>
                <span class="jrc-mcp-tool">/mcp-finance</span>
                <span class="jrc-mcp-tool">Autenticação não verificada pelo painel</span>
              </div>
            </div>`;

          grid.appendChild(card);
          const count = panel.querySelector(
            '.jrc-mcp-primary-card__integrations-head small'
          );
          if (count) count.textContent = grid.children.length + ' integração(ões)';
        })
        .catch(() => {});

      renderAdditional(panel, instancesData.instances);
      monitorMcpTopology(panel);

      // JRC_MCP_LAYOUT_UNIFICADO
      const grid = panel.querySelector('.jrc-mcp-integrations-grid');
      const extra = panel.querySelector('[data-jrc-mcp-additional]');
      const section = panel.querySelector('[data-jrc-mcp-additional-section]');
      const rows = Array.isArray(instancesData.instances)
        ? instancesData.instances : [];

      if (grid && extra && section) {
        const cards = [...extra.children];

        rows.forEach((row, i) => {
          if (row.type === 'managed' &&
              row.service === 'pabx' && cards[i]) {
            cards[i].style.cssText +=
              ';width:100%;min-width:0;max-width:none;' +
              'height:100%;box-sizing:border-box;grid-column:auto';
            grid.appendChild(cards[i]);
          }
        });

        section.hidden = extra.children.length === 0;

        const count = panel.querySelector(
          '.jrc-mcp-primary-card__integrations-head small'
        );
        if (count) count.textContent =
          grid.children.length + ' integrações';
      }

    } catch (error) {
      const aborted = error && (error.name === 'AbortError' || /abort/i.test(String(error.message || '')));
      primary.innerHTML =
        '<div class="jrc-mcp-hub__error">' +
        esc(aborted ? 'A consulta do MCP esgotou o tempo. Tente Atualizar de novo.' : (error.message || 'Falha ao carregar infraestrutura MCP.')) +
        '</div>';
    }
  }

  function buttonLabel(button) {
    if (!button) return '';
    return String(button.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function isPageMcp() {
    return /\/mcp-server\.php$/.test(location.pathname);
  }

  function isAddButton(node) {
    if (!node || !node.closest) return false;
    if (node.closest('[data-jrc-mcp-modal],[data-jrc-mcp-edit-modal],[data-jrc-mcp-credentials-modal]')) return false;
    if (node.closest('[data-jrc-mcp-add]')) return true;
    const label = buttonLabel(node.closest('button, a, [role="button"]') || node);
    return /^(?:\+\s*)?adicionar(?:\s+mcp)?$/i.test(label);
  }

  function isRefreshButton(node) {
    if (!node || !node.closest) return false;
    if (node.closest('[data-jrc-mcp-modal],[data-jrc-mcp-edit-modal],[data-jrc-mcp-credentials-modal]')) return false;
    if (node.closest('[data-jrc-mcp-check-service],[data-jrc-mcp-details],[data-jrc-mcp-edit],[data-jrc-mcp-test],[data-jrc-mcp-test-local],[data-jrc-mcp-test-managed],[data-jrc-mcp-credentials],[data-jrc-mcp-authorize]')) return false;
    if (node.closest('[data-jrc-mcp-refresh]')) return true;
    const label = buttonLabel(node.closest('button, a, [role="button"]') || node);
    return /^atualizar$/i.test(label);
  }

  let refreshBusy = false;

  async function refreshAll(panel, button) {
    if (refreshBusy) return;
    refreshBusy = true;
    const target = button && button.closest ? (button.closest('button, a, [role="button"]') || button) : button;
    const span = target && target.querySelector ? target.querySelector('span') : null;
    const previous = span ? span.textContent : (target ? target.textContent : '');
    if (target) {
      target.classList.add('is-busy');
      target.setAttribute('disabled', 'disabled');
    }
    if (span) span.textContent = 'Atualizando…';
    else if (target && target.childNodes && !target.querySelector('span')) {
      const texts = [];
      for (let i = 0; i < target.childNodes.length; i++) {
        if (target.childNodes[i].nodeType === 3 && target.childNodes[i].textContent.trim()) texts.push(target.childNodes[i]);
      }
      if (texts[0]) texts[0].textContent = ' Atualizando…';
    }
    try {
      await load(panel);
    } finally {
      if (target) {
        target.classList.remove('is-busy');
        target.removeAttribute('disabled');
      }
      if (span) span.textContent = 'Atualizar';
      else if (previous && target && !target.querySelector('span')) {
        const texts = [];
        for (let i = 0; i < target.childNodes.length; i++) {
          if (target.childNodes[i].nodeType === 3) texts.push(target.childNodes[i]);
        }
        if (texts[0]) texts[0].textContent = ' Atualizar';
      }
      refreshBusy = false;
    }
  }

  function openModal(panel) {
    const modal = panel.querySelector('[data-jrc-mcp-modal]');
    const form = panel.querySelector('[data-jrc-mcp-form]');
    const message = panel.querySelector('[data-jrc-mcp-form-message]');

    if (!modal || !form) return;

    form.reset();
    const type = form.querySelector('input[name="type"]');
    if (type) type.value = 'server';
    form.querySelector('[data-jrc-mcp-transport]')?.dispatchEvent(new Event('change'));
    if (message) {
      message.textContent = '';
      message.className = 'jrc-mcp-modal__message';
    }

    modal.hidden = false;
    document.body.classList.add('jrc-mcp-modal-open');

    setTimeout(() => form.querySelector('input[name="name"]')?.focus(), 0);
  }

  function closeModal(panel) {
    const modal = panel.querySelector('[data-jrc-mcp-modal]');
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('jrc-mcp-modal-open');
  }

  async function saveInstance(panel, form) {
    const message = panel.querySelector('[data-jrc-mcp-form-message]');
    const submit = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);

    const type = String(formData.get('type') || 'server');
    const transport = String(formData.get('transport') || 'streamable_http');
    const payload = {
      action: 'add_server',
      type: type === 'server' ? 'server' : type,
      name: String(formData.get('name') || formData.get('external_name') || formData.get('stdio_name') || formData.get('managed_name') || '').trim(),
      endpoint: String(formData.get('endpoint') || formData.get('external_endpoint') || '').trim(),
      auth_type: String(formData.get('auth_type') || 'none'),
      transport,
      command: String(formData.get('command') || '').trim(),
      description: String(formData.get('description') || '').trim(),
    };

    if (message) {
      message.className = 'jrc-mcp-modal__message';
      message.textContent = 'Salvando...';
    }
    if (submit) submit.disabled = true;

    try {
      const response = await fetch('/api/mcp-instances.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': String(window.JRC_MCP_INSTANCE_CSRF || '')
        },
        body: JSON.stringify(payload)
      });

      const data = await parseApiResponse(
        response,
        'Não foi possível cadastrar a instância MCP.'
      );

      closeModal(panel);
      await load(panel);
    } catch (error) {
      if (message) {
        message.className = 'jrc-mcp-modal__message jrc-mcp-modal__message--error';
        message.textContent = error.message || 'Falha ao cadastrar MCP.';
      }
    } finally {
      if (submit) submit.disabled = false;
    }
  }

  function editModal(panel, id) {
    const row = cachedInstances.find(item => item.id === id);
    if (!row) return;
    const modal = panel.querySelector('[data-jrc-mcp-edit-modal]');
    const form = modal.querySelector('form');
    form.elements.id.value = row.id;
    form.elements.name.value = row.name || '';
    form.elements.description.value = row.description || '';
    modal.querySelector('[data-jrc-mcp-edit-message]').textContent = '';
    modal.hidden = false;
    document.body.classList.add('jrc-mcp-modal-open');
  }

  async function saveEdit(panel, form) {
    const message = panel.querySelector('[data-jrc-mcp-edit-message]');
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    message.textContent = 'Salvando...';
    try {
      const response = await fetch('/api/mcp-instances.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
          'X-CSRF-Token': String(window.JRC_MCP_INSTANCE_CSRF || '') },
        body: JSON.stringify({ action: 'update_instance', id: form.elements.id.value,
          name: form.elements.name.value.trim(), description: form.elements.description.value.trim() })
      });
      await parseApiResponse(response, 'Não foi possível editar a conexão.');
      panel.querySelector('[data-jrc-mcp-edit-modal]').hidden = true;
      document.body.classList.remove('jrc-mcp-modal-open');
      await load(panel);
    } catch (error) { message.textContent = error.message || 'Falha ao editar.'; }
    finally { button.disabled = false; }
  }

  function credentialsModal(panel, id) {
    const modal = panel.querySelector('[data-jrc-mcp-credentials-modal]');
    const form = modal.querySelector('form');
    form.reset();
    form.elements.id.value = id;
    modal.querySelector('[data-jrc-mcp-credentials-message]').textContent = '';
    modal.hidden = false;
    document.body.classList.add('jrc-mcp-modal-open');
  }

  async function saveCredentials(panel, form) {
    const message = panel.querySelector('[data-jrc-mcp-credentials-message]');
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    message.textContent = 'Salvando...';
    try {
      const response = await fetch('/api/mcp-instances.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': String(window.JRC_MCP_INSTANCE_CSRF || '')
        },
        body: JSON.stringify({
          action: 'set_external_credentials',
          id: form.elements.id.value,
          client_id: form.elements.client_id.value,
          client_secret: form.elements.client_secret.value
        })
      });
      await parseApiResponse(response, 'Não foi possível salvar as credenciais.');
      form.reset();
      panel.querySelector('[data-jrc-mcp-credentials-modal]').hidden = true;
      document.body.classList.remove('jrc-mcp-modal-open');
      await load(panel);
    } catch (error) {
      message.textContent = error.message || 'Falha ao salvar.';
    } finally {
      submit.disabled = false;
    }
  }

  function boot() {
    const old = document.getElementById('jrc-mcp-connections-panel');
    if (old) old.remove();

    const target = findTarget();
    const panel = buildPanel();
    target.appendChild(panel);

    const transportSelect = panel.querySelector('[data-jrc-mcp-transport]');
    function syncTransport() {
      const transport = transportSelect?.value || 'streamable_http';
      const stdio = transport === 'stdio';
      const urlBox = panel.querySelector('[data-jrc-mcp-url-fields]');
      const stdioBox = panel.querySelector('[data-jrc-mcp-stdio-fields]');
      if (urlBox) {
        urlBox.hidden = stdio;
        urlBox.querySelectorAll('input').forEach(function (input) { input.disabled = stdio; input.required = !stdio; });
      }
      if (stdioBox) {
        stdioBox.hidden = !stdio;
        stdioBox.querySelectorAll('input').forEach(function (input) { input.disabled = !stdio; input.required = stdio; });
      }
    }
    transportSelect?.addEventListener('change', syncTransport);
    syncTransport();
    panel.addEventListener('click', async event => {
      const checkService = event.target.closest('[data-jrc-mcp-check-service]');
      if (checkService) {
        const id = checkService.getAttribute('data-jrc-mcp-check-service');
        checkService.disabled = true;
        try {
          const response = await fetch('/api/mcp-connections.php', {
            credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json' }
          });
          const data = await parseApiResponse(response, 'Não foi possível consultar o MCP.');
          renderPrimary(panel, data);
          const service = (data.services || []).find(item => item.id === id);
          const connection = (data.connections || []).find(item => (item.id || item.client_id) === id);
          if (service) {
            window.alert(service.name + ': ' + (service.status === 'online' ? 'módulo anunciado como online pelo MCP.' : 'módulo indisponível no status MCP.') + '\\nEste teste não executa ferramentas do módulo.');
          } else if (connection) {
            window.alert((connection.name || id) + ': último contato ' + timeAgo(connection.last_seen) + '.\\nEsta consulta não testa autenticação nem estabelece nova sessão.');
          } else {
            window.alert('Integração não encontrada no status atual do MCP.');
          }
        } catch (error) { window.alert(error.message || 'Falha ao consultar status.'); }
        finally { checkService.disabled = false; }
      }
      const details = event.target.closest('[data-jrc-mcp-details]');
      if (details) {
        const id = details.getAttribute('data-jrc-mcp-details');
        const service = (cachedStatus.services || []).find(item => item.id === id);
        const connection = (cachedStatus.connections || []).find(item => (item.id || item.client_id) === id);
        if (service) {
          window.alert('Integração: ' + service.name + '\\nTipo: ' + (service.type || 'Módulo interno') + '\\nFerramentas anunciadas: ' + (service.tools || []).join(', ') + '\\nConfiguração administrada no servidor MCP.');
        } else if (connection) {
          window.alert('Cliente: ' + (connection.name || id) + '\\nAutenticação: ' + (connection.auth_type || 'não informada') + '\\nÚltimo contato: ' + timeAgo(connection.last_seen) + '\\nCadastro e credenciais administrados no MCP/Keycloak.');
        }
      }
      const edit = event.target.closest('[data-jrc-mcp-edit]');
      if (edit) editModal(panel, edit.getAttribute('data-jrc-mcp-edit'));
      if (event.target.closest('[data-jrc-mcp-edit-close]')) {
        panel.querySelector('[data-jrc-mcp-edit-modal]').hidden = true;
        document.body.classList.remove('jrc-mcp-modal-open');
      }
      const testManaged = event.target.closest('[data-jrc-mcp-test-managed]');
      if (testManaged) {
        testManaged.disabled = true;
        try {
          const response = await fetch('/api/mcp-instances.php', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
              'X-CSRF-Token': String(window.JRC_MCP_INSTANCE_CSRF || '') },
            body: JSON.stringify({ action: 'test_managed', id: testManaged.getAttribute('data-jrc-mcp-test-managed') })
          });
          const data = await parseApiResponse(response, 'Falha ao verificar MCP.');
          window.alert(data.message);
          await load(panel);
        } catch (error) { window.alert(error.message || 'Falha na verificação.'); }
        finally { testManaged.disabled = false; }
      }
      const testLocal = event.target.closest('[data-jrc-mcp-test-local]');
      if (testLocal) {
        testLocal.disabled = true;
        try {
          const response = await fetch('/api/mcp-instances.php', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
              'X-CSRF-Token': String(window.JRC_MCP_INSTANCE_CSRF || '') },
            body: JSON.stringify({ action: 'test_local', id: testLocal.getAttribute('data-jrc-mcp-test-local') })
          });
          const data = await parseApiResponse(response, 'Falha ao testar MCP principal.');
          window.alert(data.message);
        } catch (error) { window.alert(error.message || 'Falha no teste.'); }
        finally { testLocal.disabled = false; }
      }
      const authorize = event.target.closest('[data-jrc-mcp-authorize]');
      if (authorize) {
        const id = authorize.getAttribute('data-jrc-mcp-authorize');
        try {
          const resp = await fetch('/api/mcp-ixc-oauth.php?action=status&id=' + encodeURIComponent(id), {credentials:'same-origin'});
          const status = await parseApiResponse(resp, 'Falha ao consultar OAuth2.');
          const message = 'Cadastre esta Redirect URI EXATA no OAuth Server do IXC antes de continuar:\\n\\n' + status.redirect_uri + '\\n\\nDeseja abrir a autorização agora?';
          if (window.confirm(message)) {
            window.location.assign('/api/mcp-ixc-oauth.php?action=start&id=' + encodeURIComponent(id) + '&return=' + encodeURIComponent(window.location.pathname));
          }
        } catch (err) {
          window.alert(err.message || 'Falha na autorização.');
        }
      }
      const test = event.target.closest('[data-jrc-mcp-test]');
      if (test) {
        test.disabled = true;
        try {
          const id = test.getAttribute('data-jrc-mcp-test');
          const response = await fetch('/api/mcp-ixc-oauth.php?action=test&id=' + encodeURIComponent(id), {
            method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':String(window.JRC_MCP_INSTANCE_CSRF || ''),'Accept':'application/json'}
          });
          const result = await parseApiResponse(response, 'Não foi possível testar o MCP do IXC.');
          const lines = [
            'IXC conectado. Ferramentas encontradas: ' + result.tool_count,
            'Capacidade tools: ' + (result.server_tools_capability ? 'anunciada' : 'não anunciada'),
            'Páginas consultadas: ' + (result.tool_pages ?? '—'),
            ...(result.tools || [])
          ];
          window.alert(lines.join('\n'));
        } catch(err) {
          window.alert(err.message || 'Falha no teste.');
        } finally { test.disabled = false; }
      }
      const target = event.target.closest('[data-jrc-mcp-credentials]');
      if (target) credentialsModal(panel, target.getAttribute('data-jrc-mcp-credentials'));
      if (event.target.closest('[data-jrc-mcp-credentials-close]')) {
        const form = panel.querySelector('[data-jrc-mcp-credentials-form]');
        form.reset();
        panel.querySelector('[data-jrc-mcp-credentials-modal]').hidden = true;
        document.body.classList.remove('jrc-mcp-modal-open');
      }
    });
    panel.querySelector('[data-jrc-mcp-edit-form]')?.addEventListener('submit', event => {
      event.preventDefault();
      saveEdit(panel, event.currentTarget);
    });
    panel.querySelector('[data-jrc-mcp-credentials-form]')?.addEventListener('submit', event => {
      event.preventDefault();
      saveCredentials(panel, event.currentTarget);
    });
    panel.querySelector('[data-jrc-mcp-refresh]')?.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      refreshAll(panel, event.currentTarget);
    });
    panel.querySelector('[data-jrc-mcp-add]')?.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      openModal(panel);
    });
    document.addEventListener('click', function (event) {
      if (!isPageMcp()) return;
      if (event.target.closest && event.target.closest('#jrc-mcp-connections-panel')) return;
      if (isAddButton(event.target)) {
        event.preventDefault();
        event.stopPropagation();
        openModal(panel);
        return;
      }
      if (isRefreshButton(event.target)) {
        event.preventDefault();
        event.stopPropagation();
        refreshAll(panel, event.target);
      }
    }, true);

    panel.querySelectorAll('[data-jrc-mcp-close]').forEach(element => {
      element.addEventListener('click', () => closeModal(panel));
    });

    panel.querySelector('[data-jrc-mcp-form]')?.addEventListener('submit', event => {
      event.preventDefault();
      saveInstance(panel, event.currentTarget);
    });

    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeModal(panel);
    });

    load(panel);

    window.setInterval(() => {
      if (!document.hidden) load(panel);
    }, 30000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();