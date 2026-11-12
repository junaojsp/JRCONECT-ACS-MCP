(() => {
  'use strict';

  if (window.__JRC_MCP_LOCAL_INSTANCES_V11__) return;
  window.__JRC_MCP_LOCAL_INSTANCES_V11__ = true;

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

        #jrc-mcp-connections-panel .jrc-mcp-integrations-grid > .jrc-mcp-managed-unified {
          box-sizing: border-box;
          width: 100%;
          min-width: 0;
          max-width: none;
          height: 100%;
          margin: 0;
          display: flex;
          flex-direction: column;
          justify-content: space-between;
          grid-column: auto;
        }
        #jrc-mcp-connections-panel .jrc-mcp-managed-unified .jrc-mcp-instance-card__meta {
          flex-wrap: wrap;
          overflow-wrap: anywhere;
        }

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
              <div id="jrc-mcp-modal-title" class="jrc-mcp-modal__title">Adicionar instância MCP</div>
              <div class="jrc-mcp-modal__subtitle">
                Planeje uma nova instância MCP no próprio servidor ACS.
              </div>
            </div>
            <button type="button" class="jrc-mcp-modal__close" data-jrc-mcp-close aria-label="Fechar">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <form class="jrc-mcp-modal__form" data-jrc-mcp-form>
            <label>
              <span>Tipo de conexão</span>
              <select name="type" data-jrc-mcp-type>
                <option value="local">Servidor MCP local</option>
                <option value="external">Servidor MCP remoto (HTTP / SSE)</option><option value="stdio">Servidor MCP STDIO (cadastro)</option><option value="managed">PABX Issabel 5 (integração existente)</option>
              </select>
            </label>
            <div class="jrc-mcp-form-grid" data-jrc-mcp-local-fields>
              <label>
                <span>Nome da instância</span>
                <input name="name" type="text" maxlength="100" required placeholder="Ex.: MCP Financeiro">
              </label>

              <label>
                <span>Container Docker</span>
                <input name="container" type="text" maxlength="80" required placeholder="jrconect-mcp-financeiro">
              </label>

              <label>
                <span>Porta local</span>
                <input name="port" type="number" min="1024" max="65535" required placeholder="3200">
              </label>

              <label>
                <span>Endpoint público</span>
                <input name="endpoint" type="url" maxlength="500" required placeholder="https://mcp-financeiro.jrconect.com/mcp">
              </label>
            </div>
            <div data-jrc-mcp-external-fields hidden>
              <label>
                <span>Nome da conexão</span>
                <input name="external_name" type="text" maxlength="100" placeholder="IXC Provedor JR CONECT">
              </label>
              <label>
                <span>Endpoint HTTPS do MCP externo</span>
                <input name="external_endpoint" type="url" maxlength="500" placeholder="https://cda.jrconect.com/services/mcp">
              </label>
              <label><span>Transporte</span><select name="transport"><option value="streamable_http">Streamable HTTP</option><option value="sse">SSE legado</option></select></label><label><span>Autenticação</span><select name="auth_type"><option value="oauth2">OAuth2</option><option value="bearer">Bearer Token (configuração posterior)</option><option value="api_key">API Key (configuração posterior)</option><option value="none">Sem autenticação</option></select></label><p>Credenciais e tokens não são armazenados neste formulário. OAuth2 do IXC é configurado separadamente.</p>
            </div>

            <div data-jrc-mcp-stdio-fields hidden><label><span>Nome</span><input name="stdio_name" maxlength="100"></label><label><span>Executável STDIO (sem argumentos)</span><input name="command" maxlength="200" placeholder="/usr/bin/node"></label><p>Cadastro informativo; não executa comandos.</p></div><div data-jrc-mcp-managed-fields hidden><label><span>Nome da integração</span><input name="managed_name" maxlength="100" value="JR Conect — PABX Issabel 5"></label><p>Vincula a integração existente. Não cria outro servidor nem altera o Issabel.</p></div><label>
              <span>Descrição</span>
              <textarea name="description" maxlength="240" rows="3" placeholder="Finalidade dessa instância MCP"></textarea>
            </label>

            <div class="jrc-mcp-modal__note">
              <i class="bi bi-info-circle"></i>
              O cadastro não inicia conexões nem cria containers automaticamente. A instância ficará como
              <strong>Cadastrada</strong> até ser implantada.
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
      : 'Conectado';

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

  function buildFinanceEndpointCard(finance) {
    if (!finance || !finance.success) return '';
    const reachable = finance.endpoint_reachable === true;
    const label = reachable ? 'Endpoint protegido' : 'Indisponível';
    const status = reachable ? 'online' : 'offline';
    return `
      <article class="jrc-mcp-integration-card">
        <div class="jrc-mcp-integration-card__icon"><i class="bi bi-wallet2"></i></div>
        <div class="jrc-mcp-integration-card__body">
          <div class="jrc-mcp-integration-card__top">
            <div>
              <div class="jrc-mcp-integration-card__name">JR Conect Financeiro</div>
              <div class="jrc-mcp-integration-card__subtitle">MCP Financeiro empresarial — endpoint isolado</div>
            </div>
            <span class="jrc-mcp-status jrc-mcp-status--${status}">${label}</span>
          </div>
          <div class="jrc-mcp-integration-card__chips">
            <span class="jrc-mcp-tool">OAuth / Keycloak</span>
            <span class="jrc-mcp-tool">/mcp-finance</span>
            <span class="jrc-mcp-tool">Autenticação e ferramentas não verificadas pelo painel</span>
          </div>
        </div>
      </article>
    `;
  }

  function renderPrimary(panel, statusData, financeData = null) {
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

    if (financeData && financeData.success) {
      integrations.push(buildFinanceEndpointCard(financeData));
    }

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

  function externalTestState(instance) {
    if (instance?.connection_status === 'connected') {
      const count = Math.max(0, Number(instance.tool_count) || 0);
      return {
        css: 'online',
        label: 'Conectado',
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
    return {
      css: 'registered',
      label: 'IXC externo',
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
          <span class="jrc-mcp-status jrc-mcp-status--${isExternalMcpInstance(instance) ? externalTestState(instance).css : 'registered'}">${isExternalMcpInstance(instance) ? externalTestState(instance).label : 'Cadastrado'}</span>
        </div>

        <div class="jrc-mcp-instance-card__meta">
          <span><i class="bi bi-box"></i> ${esc(isExternalMcpInstance(instance) ? (instance.auth_type || 'OAuth2') : instance.type === 'managed' ? 'Integração MCP existente' : instance.type === 'stdio' ? 'STDIO (não iniciado)' : (instance.container || '—'))}</span>
          <span><i class="bi bi-ethernet"></i> ${esc(isExternalMcpInstance(instance) ? externalTestState(instance).detail : (instance.local_address || ('127.0.0.1:' + (instance.port || '—'))))}</span>
          <span><i class="bi bi-link-45deg"></i> ${esc(instance.endpoint || (instance.type === 'managed' ? 'Ferramentas pabx_* existentes' : instance.command || '—'))}</span>
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

  async function load(panel) {
    const primary = panel.querySelector('[data-jrc-mcp-primary]');

    try {
      const [statusResponse, instancesResponse, financeData] = await Promise.all([
        fetch('/api/mcp-connections.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        }),
        fetch('/api/mcp-instances.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        }),
        fetch('/api/mcp-finance-status.php', {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        }).then(response => response.ok ? response.json() : null).catch(() => null)
      ]);

      const statusData = await parseApiResponse(
        statusResponse,
        'Não foi possível consultar o JR Conect MCPv2.'
      );

      const instancesData = await parseApiResponse(
        instancesResponse,
        'Não foi possível consultar instâncias MCP adicionais.'
      );

      renderPrimary(panel, statusData, financeData);
      renderAdditional(panel, instancesData.instances);

      // Keep the existing PABX integration card in the main grid, with the
      // same status/actions. Other additional instances remain below.
      const mainGrid = panel.querySelector('.jrc-mcp-integrations-grid');
      const extraGrid = panel.querySelector('[data-jrc-mcp-additional]');
      const extraSection = panel.querySelector('[data-jrc-mcp-additional-section]');
      const rows = Array.isArray(instancesData.instances) ? instancesData.instances : [];
      if (mainGrid && extraGrid && extraSection) {
        const cards = [...extraGrid.children];
        rows.forEach((row, index) => {
          if (row.type === 'managed' && row.service === 'pabx' && cards[index]) {
            const card = cards[index];
            card.classList.add('jrc-mcp-managed-unified');
            mainGrid.appendChild(card);
          }
        });
        extraSection.hidden = extraGrid.children.length === 0;
        const count = panel.querySelector('.jrc-mcp-primary-card__integrations-head small');
        if (count) count.textContent = mainGrid.children.length + ' integração(ões)';
      }

    } catch (error) {
      primary.innerHTML =
        '<div class="jrc-mcp-hub__error">' +
        esc(error.message || 'Falha ao carregar infraestrutura MCP.') +
        '</div>';
    }
  }

  function openModal(panel) {
    const modal = panel.querySelector('[data-jrc-mcp-modal]');
    const form = panel.querySelector('[data-jrc-mcp-form]');
    const message = panel.querySelector('[data-jrc-mcp-form-message]');

    if (!modal || !form) return;

    form.reset();
    form.querySelector('[data-jrc-mcp-type]')?.dispatchEvent(new Event('change'));
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

    const type = String(formData.get('type') || 'local');
    const isExternal = type === 'external';
    const payload = {
      type,
      name: String(formData.get(type === 'managed' ? 'managed_name' : type === 'stdio' ? 'stdio_name' : isExternal ? 'external_name' : 'name') || '').trim(),
      container: String(formData.get('container') || '').trim(),
      port: String(formData.get('port') || '').trim(),
      endpoint: String(formData.get(isExternal ? 'external_endpoint' : 'endpoint') || '').trim(),
      auth_type: String(formData.get('auth_type') || 'oauth2'),
      transport: String(formData.get('transport') || 'streamable_http'),
      command: String(formData.get('command') || '').trim(),
      service: type === 'managed' ? 'pabx' : '',
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

    const typeSelect = panel.querySelector('[data-jrc-mcp-type]');
    function syncType() {
      const type = typeSelect?.value || 'local';
      for (const [selector, visible] of [
        ['[data-jrc-mcp-local-fields]', type === 'local'],
        ['[data-jrc-mcp-external-fields]', type === 'external'],
        ['[data-jrc-mcp-stdio-fields]', type === 'stdio'],
        ['[data-jrc-mcp-managed-fields]', type === 'managed']
      ]) {
        const group = panel.querySelector(selector);
        if (!group) continue;
        group.hidden = !visible;
        group.querySelectorAll('input,select').forEach(input => { input.disabled = !visible; });
      }
    }
    typeSelect?.addEventListener('change', syncType);
    syncType();
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
    panel.querySelector('[data-jrc-mcp-refresh]')?.addEventListener('click', () => load(panel));
    panel.querySelector('[data-jrc-mcp-add]')?.addEventListener('click', () => openModal(panel));

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