/* JR CONECT - selected Wi-Fi and router account controls; monitoring preserved. */
(() => {
    'use strict';

    const state = { csrf: '', wifi: [], accounts: [], permissions: {}, loading: null, wifiId: '', accountId: '', refreshBusy: false, wifiBand: '', wifiByBand: {}, scanMessage: '' };
    const traffic = { sessionKey: null, last: null, samples: [], polling: false, lastPoll: 0, online: false, lastAccountingAt: null, latestSession: null, ixcLoginId: null, livePolling: false, liveLastPoll: 0, liveAvailable: false, liveDisabledUntil: 0, liveReason: null, liveSource: null, concentrator: null, report: null, reportLoading: false, reportLoadedFor: null, reportPollKey: null, reportLastPoll: 0, pingSamples: [], pingPolling: false, pingLastPoll: 0 };
    let dialog = null;
    const secretTimers = new Map();
    const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
    const val = value => value === null || value === undefined || value === '' ? 'Não informado' : String(value);
    const statusLabel = item => item.enabled === true ? 'Habilitada' : item.enabled === false ? 'Desabilitada' : 'Estado não informado';
    const shortId = item => item.instance_label || (item.id || '').split('.').slice(-4).join('.');
    const rowById = (kind, id) => (kind === 'wifi' ? state.wifi : state.accounts).find(row => row.id === id);
    const selected = kind => rowById(kind, kind === 'wifi' ? state.wifiId : state.accountId);
    const permitted = kind => !!state.permissions[kind === 'wifi' ? 'wifi' : 'admin'];

    function toast(message, type='info') {
        if (typeof window.showToast === 'function') window.showToast(message, type);
        else console[type === 'danger' ? 'error' : 'log']('[CPE]', message);
    }
    async function request(payload=null) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 25000);
        const options = { credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{ Accept:'application/json' } };
        let url = '/api/device-control.php';
        if (payload) {
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = state.csrf;
            options.body = JSON.stringify({ device_id:window.DEVICE_ID || '', ...payload });
        } else url += '?device_id=' + encodeURIComponent(window.DEVICE_ID || '');
        try {
            const r = await fetch(url, options);
            let data;
            try { data = await r.json(); } catch (_) { throw new Error('Resposta inválida. Confirme sua sessão e atualize a página.'); }
            if (!r.ok || !data.success) throw new Error(data.message || 'Operação não confirmada.');
            return data;
        } catch (e) {
            if (e.name === 'AbortError') throw new Error('Tempo limite. Se estava salvando, confira a leitura antes de reenviar.');
            throw e;
        } finally { clearTimeout(timer); }
    }
    function passwordLabel(item) {
        if (item.password_state === 'available') return '••••••••';
        if (item.password_state === 'concealed') return 'Oculta pelo equipamento';
        if (item.password_state === 'not_collected') return 'Ainda não coletada';
        return 'Não informada pelo equipamento';
    }
    function securityLabel(raw) {
        return ({WPAand11i:'WPA/WPA2', '11i':'WPA2', WPA:'WPA', None:'Sem segurança informada', Basic:'Basic (informado pelo modem)'})[raw] || val(raw);
    }
    function dateLabel(raw) {
        if (!raw) return 'Horário da leitura não informado';
        const d = new Date(raw);
        return Number.isNaN(d.getTime()) ? 'Horário da leitura não informado' : 'Última leitura: ' + d.toLocaleString('pt-BR');
    }

    const wifiBandNames = {'2.4':'2,4 GHz','5':'5 GHz (5,8)','6':'6 GHz','unknown':'Banda não informada'};
    const bandOf = item => ['2.4','5','6'].includes(item.band) ? item.band : 'unknown';
    function wifiRows(band) { return state.wifi.filter(row => bandOf(row) === band); }
    function syncWifiSelection() {
        if (!state.wifiBand) {
            state.wifiBand=['2.4','5','6','unknown'].find(band=>wifiRows(band).length) || '2.4';
        }
        const rows=wifiRows(state.wifiBand);
        const current=selected('wifi');
        if (current && bandOf(current)===state.wifiBand) return;
        const saved=rows.find(row=>row.id===state.wifiByBand[state.wifiBand]);
        state.wifiId=(saved || rows.find(row=>row.enabled===true) || rows[0])?.id || '';
        if(state.wifiId) state.wifiByBand[state.wifiBand]=state.wifiId;
    }
    function bandControls() {
        const bands=['2.4','5'];
        if(wifiRows('6').length) bands.push('6');
        if(wifiRows('unknown').length) bands.push('unknown');
        return `<div class="jr-wifi-band-switch" role="group" aria-label="Banda Wi-Fi">
            ${bands.map(band=>{
                const count=wifiRows(band).length;
                const selectedBand=state.wifiBand===band;
                return `<button type="button" class="acs-soft-btn ${selectedBand?'primary':''}"
                    data-band="${band}" aria-pressed="${selectedBand}" onclick="jrSelectWifiBand('${band}')">
                    <i class="bi bi-wifi"></i><span>${wifiBandNames[band]}</span>
                    <small>${count?count+' SSID'+(count>1?'s':''):'Não coletada'}</small>
                </button>`;
            }).join('')}
        </div>`;
    }
    window.jrSelectWifiBand=band => {
        if(!Object.prototype.hasOwnProperty.call(wifiBandNames,band)) return;
        state.wifiBand=band;
        const saved=state.wifiByBand[band];
        state.wifiId=saved || '';
        syncWifiSelection();
        renderWifi();
    };

    function manager(kind, item, rows) {
        const wifi = kind === 'wifi';
        const options = rows.map(row => {
            const title = wifi ? `${val(row.ssid)} · ${row.label} · ${statusLabel(row)} · ${shortId(row)}` : `${val(row.username)} · ${row.label} · ${shortId(row)}`;
            return `<option value="${esc(row.id)}" ${row.id === item.id ? 'selected' : ''}>${esc(title)}</option>`;
        }).join('');
        const canEdit = permitted(kind) && (item[wifi ? 'ssid_writable' : 'username_writable'] || item.password_writable);
        return `<div class="jr-manager" data-kind="${kind}">
            <label for="jr-select-${kind}">${wifi ? 'Selecione a rede Wi-Fi' : 'Selecione a conta do roteador'}</label>
            <select id="jr-select-${kind}" class="form-select" onchange="jrSelectControl('${kind}',this.value)">${options}</select>
            <div class="jr-selection-meta"><span>${esc(item.label)}</span>${wifi ? `<span class="jr-selection-badge ${item.enabled === true ? 'on' : ''}">${esc(statusLabel(item))}</span>` : ''}</div>
            <dl class="jr-manager-values">
                <div><dt>${wifi ? 'SSID atual' : 'Usuário atual'}</dt><dd>${esc(val(item[wifi ? 'ssid' : 'username']))}</dd></div>
                ${wifi ? `<div><dt>Canal</dt><dd>${esc(item.auto_channel === true ? 'Automático' : val(item.channel))}</dd></div><div><dt>Segurança</dt><dd>${esc(securityLabel(item.security))}</dd></div>` : ''}
                <div><dt>Senha atual</dt><dd class="jr-manager-secret"><span id="jr-secret-${kind}">${esc(passwordLabel(item))}</span><button type="button" class="acs-eye-btn" aria-label="Mostrar senha atual" onclick="jrRevealControl('${kind}')" ${item.password_state !== 'available' ? 'disabled' : ''}><i class="bi bi-eye"></i></button></dd></div>
            </dl>
            <p class="jr-manager-note">${esc(dateLabel(item.password_reported_at))}. ${wifi ? 'A edição afeta somente a rede selecionada.' : 'A senha de acesso ao roteador é diferente da senha do Wi-Fi.'}</p>
            <div class="jr-manager-actions">
                <button type="button" class="acs-soft-btn" onclick="jrRefreshControl('${kind}')" ${state.refreshBusy ? 'disabled' : ''}><i class="bi bi-arrow-clockwise"></i> Atualizar leitura</button>
                <button type="button" class="acs-soft-btn primary" onclick="jrOpenControl('${kind}')" ${!(wifi ? permitted(kind) : canEdit) ? 'disabled' : ''}><i class="bi bi-sliders"></i> ${wifi ? 'Gerenciar Wi-Fi ' + esc(wifiBandNames[bandOf(item)]) : 'Alterar acesso'}</button>
            </div>
            ${!canEdit ? '<p class="jr-manager-note">O equipamento não confirmou escrita para esta seleção.</p>' : ''}
        </div>`;
    }
    function wifiCardForBand(band) {
        const rows=wifiRows(band);
        const item=rows.find(row=>row.id===state.wifiByBand[band]) || rows.find(row=>row.enabled===true) || rows[0] || null;
        const label=wifiBandNames[band] || band;

        if(!item) {
            return `<article class="jr-wifi-summary-row missing">
                <div class="jr-wifi-row-main">
                    <div class="jr-wifi-row-title">
                        <i class="bi bi-wifi"></i>
                        <div><strong>${esc(label)}</strong><span>Nenhuma interface identificada</span></div>
                    </div>
                </div>
                <div class="jr-wifi-row-actions">
                    <button type="button" class="acs-soft-btn" onclick="jrRefreshControl('wifi',true)" ${state.refreshBusy || !permitted('wifi')?'disabled':''}>
                        <i class="bi bi-arrow-repeat"></i> Detectar
                    </button>
                </div>
            </article>`;
        }

        const active=item.enabled===true;
        const channel=item.auto_channel===true?'Automático':val(item.channel);
        const clients=val(item.clients ?? item.associated_devices ?? item.total_associations ?? 'Não informado');

        return `<article class="jr-wifi-summary-row">
            <div class="jr-wifi-row-main">
                <div class="jr-wifi-row-title">
                    <i class="bi bi-wifi"></i>
                    <div>
                        <strong>${esc(label)}</strong>
                        <span class="${active?'on':'off'}">${esc(statusLabel(item).toUpperCase())}</span>
                    </div>
                </div>

                <div class="jr-wifi-row-data">
                    <div><span>SSID</span><strong>${esc(val(item.ssid))}</strong></div>
                    <div><span>Canal</span><strong>${esc(channel)}</strong></div>
                    <div><span>Segurança</span><strong>${esc(securityLabel(item.security))}</strong></div>
                    <div><span>Clientes</span><strong>${esc(clients)}</strong></div>
                </div>
            </div>

            <div class="jr-wifi-row-actions">
                <button type="button" class="acs-soft-btn primary" onclick="jrManageBand('${band}')" ${!permitted('wifi')?'disabled':''}>
                    <i class="bi bi-sliders"></i> Gerenciar
                </button>
            </div>
        </article>`;
    }

    function unifiedSummaryCard() {
        return `<article class="jr-wifi-summary-row unified">
            <div class="jr-wifi-row-main">
                <div class="jr-wifi-row-title">
                    <i class="bi bi-diagram-3"></i>
                    <div>
                        <strong>Rede Unificada</strong>
                        <span>SMART CONNECT</span>
                    </div>
                </div>

                <div class="jr-wifi-row-data">
                    <div><span>Tipo</span><strong>Band Steering</strong></div>
                    <div><span>Bandas</span><strong>Conforme equipamento</strong></div>
                    <div><span>Tecnologia</span><strong>Wi-Fi 6/7 se suportado</strong></div>
                    <div><span>Estado</span><strong>Consultar modem</strong></div>
                </div>
            </div>

            <div class="jr-wifi-row-actions">
                <button type="button" class="acs-soft-btn primary" onclick="jrOpenUnifiedWifi()">
                    <i class="bi bi-sliders"></i> Gerenciar
                </button>
            </div>
        </article>`;
    }

    window.jrManageBand=band => {
        if(!Object.prototype.hasOwnProperty.call(wifiBandNames,band)) return;
        state.wifiBand=band;
        const rows=wifiRows(band);
        const item=rows.find(row=>row.id===state.wifiByBand[band]) || rows.find(row=>row.enabled===true) || rows[0];
        if(!item){ toast('Nenhuma rede '+wifiBandNames[band]+' foi identificada.','danger'); return; }
        state.wifiId=item.id;
        state.wifiByBand[band]=item.id;
        window.jrOpenControl('wifi');
    };

    window.jrOpenUnifiedWifi=() => {
        const section=document.querySelector('.acs-approved-wifi');
        if(typeof window.jrShowUnifiedPanel==='function'){
            window.jrShowUnifiedPanel();
            section?.scrollIntoView({block:'center',behavior:'smooth'});
            return;
        }
        toast('A identificação da rede unificada ainda não carregou. Atualize a leitura e tente novamente.','info');
    };

    function renderWifi() {
        const box=document.querySelector('.acs-approved-wifi .acs-wifi-reference-grid');
        if(!box)return;
        // JRC_WIFI_SINGLE_RENDER_OWNER_V1
        // O card oficial (.acs-wifi-health-list) pertence ao device-detail.js.
        // Este modulo continua mantendo estado, refresh e modal de edicao,
        // mas nao deve substituir novamente a grade principal.
        const jrcOfficialWifiHealth = box.querySelector('.acs-wifi-health-list');
        if (jrcOfficialWifiHealth) {
            if (typeof window.jrcApplyWifiClientCounters === 'function' && window.currentDeviceData) {
                window.jrcApplyWifiClientCounters(window.currentDeviceData);
            }
            return;
        }

        syncWifiSelection();
        box.innerHTML=`<div class="jr-wifi-summary-shell">
            <div class="jr-wifi-summary-list">
                ${wifiCardForBand('2.4')}
                ${wifiCardForBand('5')}
                ${unifiedSummaryCard()}
            </div>
            <div class="jr-wifi-summary-footer">
                <div>
                    <button type="button" class="acs-soft-btn" id="jr-detect-wifi" onclick="jrRefreshControl('wifi',true)" ${state.refreshBusy || !permitted('wifi')?'disabled':''}>
                        <i class="bi bi-arrow-repeat"></i> ${state.refreshBusy?'Consultando...':'Detectar redes do modem'}
                    </button>
                    <button type="button" id="jr-wifi-diagnostic" class="acs-soft-btn" onclick="jrDownloadWifiDiagnostic()" ${!permitted('wifi')?'disabled':''}>
                        <i class="bi bi-file-earmark-pdf"></i> Diagnóstico PDF
                    </button>
                </div>
                <div class="jr-wifi-health-strip" aria-live="polite">
                    <span><i class="bi bi-wifi"></i> <strong>${state.wifi.length}</strong> rede(s) identificada(s)</span>
                    <span><i class="bi bi-shield-check"></i> Leitura via TR-069</span>
                    <span><i class="bi bi-${state.refreshBusy ? 'arrow-repeat' : 'clock-history'}"></i> ${state.refreshBusy ? 'Consulta em andamento' : 'Dados disponíveis para suporte'}</span>
                </div>
                <small>SSID e senha podem ser alterados quando o modem confirma escrita. Canal e segurança permanecem somente leitura até o parâmetro gravável ser validado.</small>
                <p class="jr-wifi-scan-status" role="status" aria-live="polite">${esc(state.scanMessage)}</p>
            </div>
        </div>`;
    }

    function renderAccounts() {
        const box = document.querySelector('.acs-approved-admin .acs-reference-list.credentials');
        const oldButton = document.getElementById('get-credentials-btn');
        if (oldButton) { oldButton.style.display='none'; oldButton.onclick=null; }
        if (!box) return;
        if (!state.permissions.admin) {
            box.innerHTML=`<div class="jr-manager jr-permission-notice"><i class="bi bi-shield-lock"></i><p>Credenciais de acesso restritas a NOC/Admin.</p><small>Perfil verificado: ${esc(state.permissions.role || 'não informado')}. Solicite a conferência do perfil ao administrador.</small></div>`;
            return;
        }
        const item = selected('account');
        box.innerHTML = item ? manager('account',item,state.accounts) : `<div class="jr-manager"><p>O modem ainda não informou uma conta local compatível.</p><p class="jr-manager-note">A leitura depende dos parâmetros disponibilizados pelo firmware.</p><button type="button" class="acs-soft-btn" onclick="jrRefreshControl('account')" ${state.refreshBusy ? 'disabled' : ''}>Atualizar leitura</button></div>`;
    }
    async function enhanceControls() {
        if (!window.DEVICE_ID) return;
        if (state.loading) return state.loading;
        state.loading=(async () => {
            try {
                const data=await request();
                state.csrf=data.csrf || '';
                state.permissions=data.permissions || {};
                // Remove duplicate IDs, not distinct SSIDs on the same band.
                state.wifi=Array.isArray(data.wifi)
                    ? [...new Map(data.wifi.filter(row=>row && typeof row.id==='string').map(row=>[row.id,row])).values()]
                    : [];
                state.accounts=Array.isArray(data.accounts)?data.accounts:[];
                // A stable parameter ID survives sorting, polling and insertion/removal of other SSIDs.
                syncWifiSelection();
                if (!selected('account')) state.accountId=state.accounts[0]?.id || '';
                renderWifi(); renderAccounts();
            } catch (e) {
                for (const selector of ['.acs-approved-wifi .acs-wifi-reference-grid','.acs-approved-admin .acs-reference-list.credentials']) {
                    const box=document.querySelector(selector);
                    if (box) box.innerHTML=`<div class="jr-empty-control danger">${esc(e.message)}</div>`;
                }
            } finally { state.loading=null; }
        })();
        return state.loading;
    }
    window.jrSelectControl=(kind,id) => {
        if (!rowById(kind,id)) return;
        if (kind==='wifi') {
            state.wifiId=id;
            state.wifiBand=bandOf(rowById('wifi',id));
            state.wifiByBand[state.wifiBand]=id;
        } else state.accountId=id;
        renderWifi(); renderAccounts();
    };
    function secretPayload(kind,item) {
        return { action:'reveal_secret', kind, [kind==='wifi'?'interface_id':'account_id']:item.id };
    }
    window.jrRevealControl=async kind => {
        const item=selected(kind), el=document.getElementById('jr-secret-'+kind);
        if (!item || !el || item.password_state!=='available' || !permitted(kind)) return;
        if (el.dataset.showing==='1') { el.textContent=passwordLabel(item); el.dataset.showing='0'; return; }
        const button=el.parentElement.querySelector('button'); button.disabled=true;
        try {
            const result=await request(secretPayload(kind,item));
            if (!el.isConnected || selected(kind)?.id!==item.id) return;
            el.textContent=result.password; el.dataset.showing='1';
            clearTimeout(secretTimers.get(kind));
            secretTimers.set(kind,setTimeout(()=>{if(el.isConnected){el.textContent=passwordLabel(item);el.dataset.showing='0';}},30000));
        } catch(e) { toast(e.message,'danger'); }
        finally { if(button.isConnected)button.disabled=false; }
    };
    function note(text,error=false) {
        const el=document.getElementById('jr-control-note');
        if(el){el.textContent=text;el.classList.toggle('danger',error);}
    }
    function ensureModal() {
        if(document.getElementById('jrDeviceControlModal')) return;
        document.body.insertAdjacentHTML('beforeend',`
        <div class="modal fade jr-control-modal" id="jrDeviceControlModal" tabindex="-1" aria-labelledby="jr-control-title">
          <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><div><small id="jr-control-kicker">EQUIPAMENTO</small><h5 id="jr-control-title" class="modal-title">Gerenciar</h5></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
              <div class="jr-field" id="jr-editor-wifi-field" hidden>
                <label for="jr-editor-wifi">Qual rede deseja alterar?</label>
                <select id="jr-editor-wifi" class="form-control" onchange="jrChangeEditorWifi(this.value)"></select>
              </div>
              <p id="jr-control-target" class="jr-target"></p>
              <div class="jr-field"><label id="jr-user-label" for="jr-control-user">SSID</label><input id="jr-control-user" class="form-control" autocomplete="off"></div>
              <div class="jr-field"><label for="jr-control-current">Senha atual informada pelo equipamento</label><div class="jr-password-input"><input id="jr-control-current" class="form-control" type="password" readonly autocomplete="off"><button id="jr-show-current" type="button" onclick="jrRevealModalCurrent()" aria-label="Mostrar senha atual"><i class="bi bi-eye"></i></button></div></div>
              <div class="jr-field"><label for="jr-control-password">Nova senha</label><div class="jr-password-input"><input id="jr-control-password" type="password" class="form-control" autocomplete="new-password" placeholder="Em branco mantém a senha atual"><button type="button" onclick="jrToggleModalPassword()" aria-label="Mostrar nova senha"><i class="bi bi-eye"></i></button></div></div>
              <div class="jr-field"><label for="jr-control-confirm">Confirmar nova senha</label><input id="jr-control-confirm" class="form-control" type="password" autocomplete="new-password"></div>
              <div class="jr-control-note" id="jr-control-note" role="status" aria-live="polite"></div>
            </div>
            <div class="modal-footer"><button type="button" class="acs-soft-btn" data-bs-dismiss="modal"><i class="bi bi-arrow-left"></i> Voltar para Redes Wi-Fi</button><button id="jr-control-save" type="button" class="acs-soft-btn primary" onclick="jrSaveDeviceControl()"><i class="bi bi-check2"></i> Salvar nesta seleção</button></div>
          </div></div>
        </div>`);
        const modal=document.getElementById('jrDeviceControlModal');
        modal.addEventListener('hide.bs.modal',event=>{if(dialog?.saving)event.preventDefault();});
        modal.addEventListener('hidden.bs.modal',()=>{
            dialog=null;
            for(const id of ['jr-control-current','jr-control-password','jr-control-confirm']) document.getElementById(id).value='';
        });
    }
    function renderEditorWifiOptions(id) {
        const select=document.getElementById('jr-editor-wifi');
        const bands=['2.4','5',...['6','unknown'].filter(b=>wifiRows(b).length)];
        select.innerHTML=bands.map(band=>{
            const rows=wifiRows(band);
            const options=rows.length ? rows.map(row=>
                `<option value="${esc(row.id)}">${esc(val(row.ssid)+' · '+statusLabel(row)+' · '+shortId(row))}</option>`).join('')
                : '<option value="" disabled>Não coletada — use Detectar redes do modem</option>';
            return `<optgroup label="${esc(wifiBandNames[band])}">${options}</optgroup>`;
        }).join('');
        select.value=id;
        select.disabled=!!dialog?.saving;
    }
    window.jrChangeEditorWifi=id => {
        const d=dialog, row=rowById('wifi',id);
        const select=document.getElementById('jr-editor-wifi');
        if(!d || d.kind!=='wifi' || d.saving || !row) {
            if(d && select) select.value=d.item.id;
            return;
        }
        if(row.id===d.item.id) return;
        const dirty=document.getElementById('jr-control-user').value!==String(d.item.ssid??'')
            || document.getElementById('jr-control-password').value!=='' || document.getElementById('jr-control-confirm').value!=='';
        if(dirty && !window.confirm('Descartar os campos ainda não salvos e escolher outra rede?')) {
            select.value=d.item.id;
            return;
        }
        // jrOpenControl creates a new immutable target snapshot. Polling cannot change it.
        window.jrSelectControl('wifi',row.id);
        window.jrOpenControl('wifi');
    };
    function wifiDiagnosticPdf(result) {
        const printable = value => String(value ?? '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
            .replace(/[\\()]/g, ch => '\\' + ch)
            .replace(/[^\x20-\x7E]/g,'?');
        const lines = ['JR CONECT TELECOM - DIAGNOSTICO WI-FI', 'Gerado em: ' + new Date().toLocaleString('pt-BR'), 'Origem: ACS / TR-069', ''];
        const add = (value, indent=0, label='') => {
            if (value === null || value === undefined || value === '') {
                lines.push(' '.repeat(indent) + label + 'Nao informado'); return;
            }
            if (Array.isArray(value)) {
                if (label) lines.push(' '.repeat(indent) + label);
                if (!value.length) lines.push(' '.repeat(indent + 2) + 'Sem registros');
                value.forEach((item,index) => add(item, indent + 2, '[' + (index + 1) + '] '));
                return;
            }
            if (typeof value === 'object') {
                if (label) lines.push(' '.repeat(indent) + label);
                Object.entries(value).forEach(([key,item]) => add(item, indent + 2, key + ': '));
                return;
            }
            const prefix = ' '.repeat(indent) + label;
            const text = prefix + String(value);
            for (let offset=0; offset<text.length; offset+=92) lines.push(text.slice(offset, offset+92));
        };
        add(result.diagnostic || {}, 0);
        const pageLines=46, pages=[];
        for(let i=0;i<lines.length;i+=pageLines) pages.push(lines.slice(i,i+pageLines));
        const objects=['<< /Type /Catalog /Pages 2 0 R >>',''];
        const pageIds=[];
        pages.forEach((page,index) => {
            const pageId=3 + index * 2, contentId=pageId + 1;
            pageIds.push(pageId + ' 0 R');
            const content=['BT','/F1 10 Tf','50 795 Td'];
            page.forEach((line,lineIndex) => {
                if(lineIndex) content.push('0 -15 Td');
                content.push('(' + printable(line) + ') Tj');
            });
            content.push('ET');
            const stream=content.join('\n');
            objects[pageId-1]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents ' + contentId + ' 0 R >>';
            objects[contentId-1]='<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream';
        });
        objects[1]='<< /Type /Pages /Kids [' + pageIds.join(' ') + '] /Count ' + pages.length + ' >>';
        let pdf='%PDF-1.4\n', offsets=[0];
        objects.forEach((object,index) => { offsets[index+1]=pdf.length; pdf+=(index+1)+' 0 obj\n'+object+'\nendobj\n'; });
        const xref=pdf.length;
        pdf+='xref\n0 '+(objects.length+1)+'\n0000000000 65535 f \n';
        offsets.slice(1).forEach(offset => { pdf+=String(offset).padStart(10,'0')+' 00000 n \n'; });
        pdf+='trailer\n<< /Size '+(objects.length+1)+' /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF';
        return new Blob([pdf], {type:'application/pdf'});
    }
    window.jrDownloadWifiDiagnostic=async () => {
        if(!permitted('wifi')) return;
        const button=document.getElementById('jr-wifi-diagnostic');
        if(button) button.disabled=true;
        try {
            const result=await request({action:'wifi_diagnostics',kind:'wifi'});
            const blob=wifiDiagnosticPdf(result);
            const url=URL.createObjectURL(blob), anchor=document.createElement('a');
            anchor.href=url; anchor.download='diagnostico-wifi-jrconect.pdf';
            document.body.appendChild(anchor); anchor.click(); anchor.remove();
            setTimeout(()=>URL.revokeObjectURL(url),1000);
        } catch(e) {toast(e.message,'danger');}
        finally {if(button?.isConnected)button.disabled=false;}
    };
    window.jrOpenControl=kind => {
        const item=selected(kind);
        if(!item || !permitted(kind)) return;
        if(dialog?.saving) return;
        if(kind!=='wifi' && !item.username_writable && !item.password_writable) return;
        ensureModal();
        dialog={kind,item:{...item},saving:false,currentShown:false};
        const wifi=kind==='wifi';
        const choiceField=document.getElementById('jr-editor-wifi-field');
        choiceField.hidden=!wifi;
        if(wifi) renderEditorWifiOptions(item.id);
        document.getElementById('jr-control-title').textContent=wifi?'Gerenciar Wi-Fi '+wifiBandNames[bandOf(item)]:'Alterar acesso ao roteador';
        document.getElementById('jr-control-kicker').textContent=item.label || 'EQUIPAMENTO';
        document.getElementById('jr-control-target').textContent=wifi?wifiBandNames[bandOf(item)]+' · '+val(item.ssid)+' · '+shortId(item):val(item.username)+' · '+shortId(item);
        document.getElementById('jr-user-label').textContent=wifi?'Nome da rede (SSID)':'Usuário de acesso';
        const user=document.getElementById('jr-control-user');
        user.value=item[wifi?'ssid':'username'] || ''; user.disabled=!item[wifi?'ssid_writable':'username_writable'];
        user.maxLength=wifi?32:64;
        const current=document.getElementById('jr-control-current');
        current.type=item.password_state==='available'?'password':'text'; current.value=passwordLabel(item);
        document.getElementById('jr-show-current').disabled=item.password_state!=='available';
        for(const id of ['jr-control-password','jr-control-confirm']) {
            const input=document.getElementById(id); input.value=''; input.type='password'; input.disabled=!item.password_writable; input.maxLength=wifi?63:64;
        }
        note(wifi?'Somente o SSID selecionado será alterado. Canal e segurança permanecem como estão. Trocar SSID ou senha pode desconectar os clientes dessa rede.':'A alteração afeta o acesso ao roteador, não o Wi-Fi. Garanta um acesso local alternativo antes de trocar a credencial.');
        const editable=item[wifi?'ssid_writable':'username_writable'] || item.password_writable;
        if(!editable) note('Esta interface foi identificada, mas o modem não confirmou escrita. Atualize a leitura; não será utilizada outra banda como substituta.');
        document.getElementById('jr-control-save').disabled=!editable;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('jrDeviceControlModal')).show();
    };
    window.jrRevealModalCurrent=async () => {
        const d=dialog;
        if(!d || d.saving || d.item.password_state!=='available') return;
        const input=document.getElementById('jr-control-current'), button=document.getElementById('jr-show-current');
        if(d.currentShown){input.value=passwordLabel(d.item);input.type='password';d.currentShown=false;return;}
        button.disabled=true;
        try {
            const result=await request(secretPayload(d.kind,d.item));
            if(dialog!==d) return;
            input.type='text'; input.value=result.password; d.currentShown=true;
            setTimeout(()=>{if(dialog===d){input.type='password';input.value=passwordLabel(d.item);d.currentShown=false;}},30000);
        } catch(e) { note(e.message,true); }
        finally { if(dialog===d)button.disabled=false; }
    };
    window.jrToggleModalPassword=() => {
        const input=document.getElementById('jr-control-password');
        if(input) input.type=input.type==='password'?'text':'password';
    };
    function setSaving(flag) {
        if(!dialog) return;
        dialog.saving=flag;
        const choice=document.getElementById('jr-editor-wifi');
        if(choice) choice.disabled=flag;
        for(const button of document.querySelectorAll('#jrDeviceControlModal .modal-footer button, #jrDeviceControlModal .btn-close')) button.disabled=flag;
    }
    window.jrSaveDeviceControl=async () => {
        const d=dialog;
        if(!d || d.saving) return;
        const wifi=d.kind==='wifi', item=d.item;
        if(!permitted(d.kind) || (!item[wifi?'ssid_writable':'username_writable'] && !item.password_writable)) return;
        const user=document.getElementById('jr-control-user').value;
        const password=document.getElementById('jr-control-password').value;
        const confirm=document.getElementById('jr-control-confirm').value;
        if(password!==confirm){note('A confirmação da nova senha não confere.',true);return;}
        const payload={action:wifi?'update_wifi':'update_account',revision:item.revision,[wifi?'interface_id':'account_id']:item.id};
        if(wifi) payload.wifi_band=bandOf(item);
        if(item[wifi?'ssid_writable':'username_writable'] && user!==String(item[wifi?'ssid':'username']??'')) payload[wifi?'ssid':'username']=user;
        if(item.password_writable && password) payload.password=password;
        if(!('ssid' in payload)&&!('username' in payload)&&!('password' in payload)){note('Nenhuma alteração foi informada.');return;}
        setSaving(true); note('Enviando alteração somente para a seleção indicada...');
        try {
            const result=await request(payload);
            if(dialog!==d)return;
            setSaving(false);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('jrDeviceControlModal')).hide();
            toast(result.message,result.queued?'info':'success');
            setTimeout(enhanceControls,1000); // GET only; no automatic extra tasks or retries.
        } catch(e) {if(dialog===d)note(e.message,true);}
        finally {if(dialog===d)setSaving(false);}
    };
    window.jrRefreshControl=async (kind='wifi', allWifi=false) => {
        if(kind==='admin')kind='account';
        if(state.refreshBusy || !permitted(kind))return;
        const item=selected(kind);
        const scanAll=kind==='wifi' && allWifi===true;
        if(scanAll) state.scanMessage='Buscando as interfaces Wi-Fi do modem...';
        state.refreshBusy=true; renderWifi(); renderAccounts();
        try {
            const payload={action:'refresh',kind};
            if(scanAll) payload.scope='all';
            else if(item)payload[kind==='wifi'?'interface_id':'account_id']=item.id;
            const result=await request(payload);
            if(scanAll) state.scanMessage=result.message;
            toast(result.message,result.partial?'warning':'info');
            await enhanceControls();
            // Read cache again after asynchronous refresh; never submit duplicate tasks.
            if(scanAll) {
                setTimeout(enhanceControls,3000);
                setTimeout(enhanceControls,8000);
            }
        } catch(e) {
            if(scanAll) state.scanMessage=e.message;
            toast(e.message,'danger');
        }
        finally {state.refreshBusy=false;renderWifi();renderAccounts();}
    };

    /* ---------- Monitoramento ---------- */
    function fmtBytes(bytes) {
        let n=Number(bytes); if(!Number.isFinite(n)||n<0)return 'Sem leitura';
        const u=['B','KB','MB','GB','TB']; let i=0; while(n>=1024&&i<u.length-1){n/=1024;i++;}
        return (i===0?n.toFixed(0):n.toFixed(n>=100?0:n>=10?1:2))+' '+u[i];
    }
    function fmtDuration(seconds) {
        const n=Number(seconds); if(!Number.isFinite(n)||n<0)return 'N/D';
        const d=Math.floor(n/86400),h=Math.floor(n%86400/3600),m=Math.floor(n%3600/60);
        return [d?d+'d':'',h?h+'h':'',m+'min'].filter(Boolean).join(' ');
    }
    /* JRC_WAN_OVERVIEW_FIX_V1 */
    window.JRC_WAN_OVERVIEW_FIX = '20261023-1';
    function jrcWanNodes(id) {
        if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(id)) return [];
        return Array.from(document.querySelectorAll('[id="' + id + '"]'));
    }
    function jrcWanVisible(node) {
        return !!node && node.getClientRects().length > 0 &&
            getComputedStyle(node).visibility !== 'hidden';
    }
    function jrcWanElement(id) {
        const nodes = jrcWanNodes(id);
        return nodes.find(jrcWanVisible) || nodes[0] || null;
    }
    function jrcWanActive() {
        return !document.hidden && (monitoringActive() ||
            jrcWanNodes('bandwidth-bars').some(jrcWanVisible));
    }
    /* JRC_WAN_OVERVIEW_FIX_V2 */
    window.JRC_WAN_OVERVIEW_FIX = '20261023-2';
    function jrcWanPaintLastSample(message) {
        const samples = Array.isArray(traffic.samples) ? traffic.samples : [];
        const last = samples.length ? samples[samples.length - 1] : null;
        if (!last || !Number.isFinite(last.down) || !Number.isFinite(last.up)) return false;
        setLiveRate('live-rx', last.down);
        setLiveRate('live-tx', last.up);
        jrcWanNodes('bandwidth-bars').forEach(node => { node.innerHTML = chartHtml(samples); });
        if (message) setMonitorText('bandwidth-sample-status', message);
        return true;
    }
    function jrcWanNumber(value) {
        if (typeof value !== 'number' && typeof value !== 'string') return null;
        if (typeof value === 'string' && value.trim() === '') return null;
        const number = Number(value);
        return Number.isFinite(number) && number >= 0 ? number : null;
    }
    /* JRC_IXC_SSE_REALTIME_V1 */
    window.JRC_IXC_SSE_REALTIME = '20261023-1';

    function jrcStopIxcSse() {
        if (traffic.ixcSseRetryTimer) {
            clearTimeout(traffic.ixcSseRetryTimer);
            traffic.ixcSseRetryTimer = null;
        }
        if (traffic.ixcSse) {
            try { traffic.ixcSse.close(); } catch (_) {}
            traffic.ixcSse = null;
        }
        traffic.ixcSseConnected = false;
    }

    /* JRC_IXC_CURRENT_SMOOTH_V1 */
    function jrcPaintIxcSseSample(sample) {
        if (!sample) return false;
        const down = jrcWanNumber(sample.download_mbps);
        const up = jrcWanNumber(sample.upload_mbps);
        if (down === null || up === null) return false;

        const parsed = sample.sample_time ? new Date(sample.sample_time).getTime() : Date.now();
        const at = Number.isFinite(parsed) ? parsed : Date.now();

        traffic.liveAvailable = true;
        traffic.liveReason = null;
        traffic.liveDisabledUntil = 0;
        traffic.liveSource = 'ixc-sse';
        traffic.ixcSseLast = {download_mbps:down, upload_mbps:up, sample_time:new Date(at).toISOString()};

        traffic.samples.push({at, down, up, source:'ixc-sse'});
        const cutoff = Date.now() - (5 * 60 * 1000);
        traffic.samples = traffic.samples.filter(row => Number(row.at) >= cutoff).slice(-360);

        // O EventSource do IXC pode alternar amostras muito baixas/zero entre
        // atualizacoes. Para os cards "agora", usa apenas a media das amostras
        // reais dos ultimos 3 segundos. A curva permanece com os valores brutos.
        /* JRC_IXC_CURRENT_SMOOTH_1S_V1 */
        const currentCutoff = at - 1000;
        const current = traffic.samples.filter(row =>
            Number(row.at) >= currentCutoff &&
            Number(row.at) <= at &&
            Number.isFinite(row.down) &&
            Number.isFinite(row.up)
        );
        const currentDown = current.length
            ? current.reduce((sum,row)=>sum+Math.max(0,row.down),0)/current.length
            : down;
        const currentUp = current.length
            ? current.reduce((sum,row)=>sum+Math.max(0,row.up),0)/current.length
            : up;

        setLiveRate('live-rx', currentDown);
        setLiveRate('live-tx', currentUp);
        setMonitorText('jr-monitor-source', 'IXC EventSource + IXC/RADIUS');
        setMonitorText('jr-monitor-live-source', 'IXC EventSource');
        setMonitorText('jr-source-live-badge', 'IXC AO VIVO');
        setMonitorText('jr-source-live-state', 'Stream IXC conectado');
        setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • ' + new Date(at).toLocaleTimeString('pt-BR'));

        jrcWanNodes('bandwidth-bars').forEach(node => {
            node.innerHTML = chartHtml(traffic.samples);
        });
        return true;
    }

    function jrcScheduleIxcSse(loginId, delay = 2500) {
        if (!loginId || !jrcWanActive()) return;
        if (traffic.ixcSseRetryTimer) clearTimeout(traffic.ixcSseRetryTimer);
        traffic.ixcSseRetryTimer = setTimeout(() => {
            traffic.ixcSseRetryTimer = null;
            jrcStartIxcSse(loginId);
        }, delay);
    }

    function jrcStartIxcSse(loginId) {
        const id = Number(loginId);
        if (!Number.isInteger(id) || id < 1 || !jrcWanActive() || typeof EventSource === 'undefined') return false;

        if (traffic.ixcSse && traffic.ixcSseLoginId === id &&
            traffic.ixcSse.readyState !== EventSource.CLOSED) {
            return true;
        }

        jrcStopIxcSse();
        traffic.ixcSseLoginId = id;
        setMonitorText('bandwidth-sample-status', 'Conectando ao stream ao vivo do IXC...');

        const source = new EventSource('/api/stream-ixc-live-traffic.php?login_id=' + encodeURIComponent(id));
        traffic.ixcSse = source;

        source.onopen = () => {
            if (traffic.ixcSse !== source) return;
            traffic.ixcSseConnected = true;
            setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • conexao nativa');
        };

        /* JRC_IXC_NATIVE_SSE_PROXY_V1 */
        source.addEventListener('trafego', event => {
            if (traffic.ixcSse !== source) return;
            let raw;
            try { raw = JSON.parse(event.data); } catch (_) { return; }

            const rxBps = Number(raw?.rx);
            const txBps = Number(raw?.tx);
            if (!Number.isFinite(rxBps) || !Number.isFinite(txBps) || rxBps < 0 || txBps < 0) return;

            jrcPaintIxcSseSample({
                download_mbps: rxBps / 1000000,
                upload_mbps: txBps / 1000000,
                sample_time: new Date().toISOString(),
                transport: 'ixc-native-sse'
            });
        });

        source.addEventListener('end', () => {
            if (traffic.ixcSse !== source) return;
            source.close();
            traffic.ixcSse = null;
            traffic.ixcSseConnected = false;
            if (traffic.ixcSseLast) {
                setMonitorText('bandwidth-sample-status', 'IXC AO VIVO • reconectando stream...');
            }
            jrcScheduleIxcSse(id, 700);
        });

        source.onerror = () => {
            if (traffic.ixcSse !== source) return;
            source.close();
            traffic.ixcSse = null;
            traffic.ixcSseConnected = false;
            if (traffic.ixcSseLast) jrcPaintIxcSseSample(traffic.ixcSseLast);
            setMonitorText('jr-source-live-state', 'Stream IXC indisponivel; fallback ativo');
            setMonitorText('bandwidth-sample-status', 'Stream IXC indisponivel; usando fallback NE8000/TR-069.');
            jrcScheduleIxcSse(id, 5000);
        };
        return true;
    }

    async function jrcWanJson(url) {
        const allowed = ['/api/get-radius-session.php', '/api/get-ixc-live-traffic.php',
            '/api/get-ne-live-traffic.php', '/api/get-tr069-live-traffic.php'];
        const parsed = new URL(url, window.location.href);
        if (parsed.origin !== window.location.origin || !allowed.includes(parsed.pathname)) {
            return {success:false, available:false, message:'Endpoint WAN inesperado.'};
        }
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(parsed.href, {credentials:'same-origin',
                cache:'no-store', signal:controller.signal, headers:{Accept:'application/json'}});
            if (!response.ok) return {success:false, available:false, message:'HTTP ' + response.status};
            const data = await response.json();
            if (!data || typeof data !== 'object' || Array.isArray(data)) {
                return {success:false, available:false, message:'Resposta WAN invalida.'};
            }
            return data;
        } catch (error) {
            return {success:false, available:false, message:error.name === 'AbortError'
                ? 'Tempo limite de 15s.' : 'Falha de rede ou resposta nao JSON.'};
        } finally { clearTimeout(timer); }
    }

    function monitoringActive() {
        const pane=document.getElementById('monitoring');
        return !!pane && pane.classList.contains('active') && pane.classList.contains('show');
    }
    function monitorCustomerPlanName() {
        try {
            return typeof cachedCustomerSummary!=='undefined'
                ? String(cachedCustomerSummary?.contract?.plan || '')
                : '';
        } catch(_) { return ''; }
    }
    function monitorPlanMbps() {
        const plan=monitorCustomerPlanName().trim();
        if(!plan)return null;
        const m=plan.match(/(\d+(?:[.,]\d+)?)\s*(GIGA|GB|G|MEGA|MB|M)?/i);
        if(!m)return null;
        let value=Number(String(m[1]).replace(',','.'));
        if(!Number.isFinite(value)||value<=0)return null;
        const unit=String(m[2]||'M').toUpperCase();
        if(unit==='GIGA'||unit==='GB'||unit==='G')value*=1000;
        return value;
    }
    function monitorPlanLabel() {
        const mbps=monitorPlanMbps();
        const name=monitorCustomerPlanName();
        if(mbps!==null)return mbps.toLocaleString('pt-BR',{maximumFractionDigits:1})+' Mbps';
        return name || 'Não identificado';
    }
    function parseRadiusTime(value) {
        if(!value)return null;
        const t=new Date(String(value).trim().replace(' ','T')).getTime();
        return Number.isFinite(t)?t:null;
    }
    function monitorStats() {
        const samples=traffic.samples.filter(s=>Number.isFinite(s.down)&&Number.isFinite(s.up));
        if(!samples.length)return {latest:null,peakDown:null,peakUp:null,avgDown:null,avgUp:null};
        const latest=samples[samples.length-1];
        const peakDown=Math.max(...samples.map(s=>s.down));
        const peakUp=Math.max(...samples.map(s=>s.up));
        const avgDown=samples.reduce((a,s)=>a+s.down,0)/samples.length;
        const avgUp=samples.reduce((a,s)=>a+s.up,0)/samples.length;
        return {latest,peakDown,peakUp,avgDown,avgUp};
    }
    function fmtMbps(value) {
        const n=Number(value);
        return Number.isFinite(n)?n.toFixed(n>=100?1:2):'--';
    }
    function fmtLiveRate(valueMbps) {
        const n=Number(valueMbps);
        if(!Number.isFinite(n) || n < 0) return {value:'--',unit:'Mbps'};
        if(n >= 1) return {
            value:n.toFixed(n>=100?1:n>=10?2:3),
            unit:'Mbps'
        };
        if(n >= 0.001) return {
            value:(n*1000).toFixed(n*1000>=100?0:n*1000>=10?1:2),
            unit:'Kbps'
        };
        return {
            value:(n*1000000).toFixed(n*1000000>=100?0:n*1000000>=10?1:2),
            unit:'bps'
        };
    }
    function setLiveRate(prefix, valueMbps) {
        const value = jrcWanNumber(valueMbps);
        // The overview has a static Mbps label. Never display Kbps as Mbps.
        setMonitorText(prefix + '-mbps', value === null ? '--' :
            value.toFixed(value > 0 && value < 0.01 ? 4 : 2));
        setMonitorText(prefix + '-unit', 'Mbps');
    }
    function fmtAccountingAge(ms) {
        if(!Number.isFinite(ms)||ms<0)return 'N/D';
        const seconds=Math.floor(ms/1000);
        if(seconds<60)return seconds+'s atrás';
        const minutes=Math.floor(seconds/60);
        if(minutes<60)return minutes+'min atrás';
        return Math.floor(minutes/60)+'h atrás';
    }
    /* JRC_WAN_STATUS_BACKGROUND_ONLY_V1 */
    function jrcHideWanTechnicalStatus() {
        document.querySelectorAll('[id="bandwidth-sample-status"]').forEach(node => {
            node.style.display = 'none';
            node.setAttribute('aria-hidden','true');
        });
    }

    function setMonitorText(id,value) {
        jrcWanNodes(id).forEach(node => {
            node.textContent = value ?? 'N/D';
            if (id === 'bandwidth-sample-status') {
                node.style.display = 'none';
                node.setAttribute('aria-hidden','true');
            }
        });
    }
    function monitorDiagnosis() {
        const stats=monitorStats();
        const plan=monitorPlanMbps();
        const usage=plan&&stats.latest?Math.max(0,(stats.latest.down/plan)*100):null;
        const age=traffic.lastAccountingAt?Date.now()-traffic.lastAccountingAt:null;
        let level='collecting',title='Coletando dados',text='Aguardando contabilizações consecutivas do IXC/RADIUS.';

        if(!traffic.online){
            level='danger'; title='Sem sessão PPPoE'; text='Nenhuma sessão RADIUS ativa foi confirmada para este cliente.';
        } else if(stats.latest){
            if(age!==null && age>180000){
                level='warning'; title='Contabilização atrasada';
                text='A sessão está ativa, mas a última contabilização do RADIUS está há '+fmtAccountingAge(age)+'.';
            } else if(usage!==null && usage>=90){
                level='warning'; title='Uso elevado do plano';
                text='O download atual está usando aproximadamente '+Math.round(usage)+'% da velocidade nominal do plano.';
            } else {
                level='ok'; title='Sessão normal';
                text=usage===null
                    ? 'Sessão ativa e contabilização sendo recebida. O plano não foi identificado para calcular o percentual de uso.'
                    : 'Sessão ativa, contabilização atualizada e uso atual em aproximadamente '+Math.round(usage)+'% do plano.';
            }
        }

        const box=document.getElementById('jr-monitor-diagnosis');
        if(box){
            box.classList.remove('ok','warning','danger','collecting');
            box.classList.add(level);
        }
        setMonitorText('jr-monitor-diagnosis-title',title);
        setMonitorText('jr-monitor-diagnosis-text',text);
        return {usage,age};
    }
    function updateMonitoringInsights() {
        const stats=monitorStats();
        const plan=monitorPlanMbps();
        const diagnosis=monitorDiagnosis();

        setMonitorText('jr-monitor-plan',monitorPlanLabel());
        setMonitorText('jr-monitor-plan-use',diagnosis.usage===null?'Uso atual: aguardando amostra':'Uso atual: '+Math.min(999,diagnosis.usage).toFixed(0)+'%');
        setMonitorText('jr-monitor-peak',stats.peakDown===null?'--':fmtMbps(stats.peakDown)+' ↓ / '+fmtMbps(stats.peakUp)+' ↑');
        setMonitorText('jr-monitor-average',stats.avgDown===null?'--':fmtMbps(stats.avgDown)+' ↓ / '+fmtMbps(stats.avgUp)+' ↑');
        setMonitorText('jr-monitor-last-accounting',traffic.lastAccountingAt?new Date(traffic.lastAccountingAt).toLocaleString('pt-BR'):'N/D');
        setMonitorText('jr-monitor-last-accounting-text',traffic.lastAccountingAt?new Date(traffic.lastAccountingAt).toLocaleString('pt-BR'):'N/D');
        setMonitorText('jr-monitor-accounting-age',traffic.lastAccountingAt?fmtAccountingAge(Date.now()-traffic.lastAccountingAt):'Aguardando');
        setMonitorText('jr-monitor-source','IXC/RADIUS');
        setMonitorText('jr-monitor-bras',traffic.latestSession?.bras || 'N/D');

        const usageBar=document.getElementById('jr-monitor-plan-bar');
        if(usageBar){
            const pct=diagnosis.usage===null?0:Math.max(0,Math.min(100,diagnosis.usage));
            usageBar.style.width=pct+'%';
            usageBar.classList.toggle('high',pct>=90);
        }

        // Latência/perda/jitter só serão preenchidos quando houver diagnóstico CPE real.
        setMonitorText('jr-monitor-latency','Não coletada');
        setMonitorText('jr-monitor-loss','Não coletada');
        setMonitorText('jr-monitor-jitter','Não coletado');
    }
    function reportBytes(value) {
        const n=Number(value);
        if(!Number.isFinite(n)||n<0)return '0 B';
        return fmtBytes(n);
    }
    function reportDateLabel(value) {
        if(!value)return '';
        const d=new Date(value);
        return Number.isNaN(d.getTime())?String(value):d.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
    }
    function renderIxcEventHistory(report) {
        const daily=Array.isArray(report?.last_7_days?.daily)?report.last_7_days.daily:[];
        if(!daily.length)return '<div class="jr-ixc-empty">Sem histórico disponível nos últimos 7 dias.</div>';

        const series=[
            {key:'user',label:'Requisitado pelo usuário',cls:'user'},
            {key:'admin',label:'Requisitado pelo Administrador',cls:'admin'},
            {key:'nas',label:'Requisitado pelo Concentrador',cls:'nas'},
            {key:'nas_reboot',label:'Reboot de concentrador',cls:'reboot'},
            {key:'lost',label:'Perda de Conexão',cls:'lost'},
            {key:'other',label:'Outros eventos',cls:'other'}
        ];
        const max=Math.max(1,...daily.flatMap(d=>series.map(s=>Number(d[s.key]||0))));
        const x=i=>daily.length===1?50:(i/(daily.length-1))*100;
        const y=v=>90-(Number(v||0)/max)*72;
        const grid='<path d="M0 18H100M0 54H100M0 90H100" class="grid"/>';
        const lines=series.map(s=>{
            const pts=daily.map((d,i)=>x(i).toFixed(2)+','+y(d[s.key]).toFixed(2)).join(' ');
            const dots=daily.map((d,i)=>{
                const value=Number(d[s.key]||0);
                const tooltip=reportDateLabel(d.date)+' • '+s.label+' • '+value+' evento(s)';
                return '<circle cx="'+x(i).toFixed(2)+'" cy="'+y(value).toFixed(2)+'" r="1.8" class="'+s.cls+'"><title>'+esc(tooltip)+'</title></circle>';
            }).join('');
            return '<polyline points="'+pts+'" class="'+s.cls+'"/>'+dots;
        }).join('');
        const labels=daily.map(d=>'<span>'+reportDateLabel(d.date)+'</span>').join('');
        const legend=series.map(s=>{
            const total=daily.reduce((sum,d)=>sum+Number(d[s.key]||0),0);
            return '<span class="jr-event-legend-item"><i class="'+s.cls+'"></i><strong>'+s.label+'</strong><small>'+total+' evento(s)</small></span>';
        }).join('');
        return '<div class="jr-ixc-event-chart"><svg viewBox="0 0 100 100" preserveAspectRatio="none">'+grid+lines+'</svg><div class="jr-ixc-event-dates">'+labels+'</div></div>'+
            '<div class="jr-event-legend-title">Legenda dos eventos <small>Passe o mouse sobre os pontos do gráfico para ver os detalhes.</small></div>'+
            '<div class="jr-ixc-event-legend">'+legend+'</div>';
    }
    function reportDateTimeCell(value) {
        if(!value)return '<span class="jr-ixc-dash">—</span>';
        const d=new Date(value);
        if(Number.isNaN(d.getTime()))return esc(String(value));
        return '<span class="jr-ixc-date">'+d.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'2-digit'})+'</span>'+
            '<small>'+d.toLocaleTimeString('pt-BR')+'</small>';
    }

    function classifyIxcConnectionCause(cause) {
        const text=String(cause||'').trim();
        const normalized=text.toLowerCase();
        if(!normalized)return {key:'other',label:'—'};
        if(normalized.includes('admin'))return {key:'admin',label:text};
        if(normalized.includes('nas-reboot')||normalized.includes('reboot'))return {key:'reboot',label:text};
        if(normalized.includes('lost')||normalized.includes('carrier')||normalized.includes('service')||normalized.includes('timeout'))return {key:'lost',label:text};
        if(normalized.includes('nas')||normalized.includes('coa'))return {key:'nas',label:text};
        if(normalized.includes('user')||normalized.includes('request'))return {key:'user',label:text};
        return {key:'other',label:text};
    }

    function connectionStabilityMetrics(report) {
        const rows=Array.isArray(report?.last_7_days?.connections)?report.last_7_days.connections:[];
        const server=report?.last_7_days?.stability||{};
        const completed=rows.filter(row=>!row.online&&row.stopped_at);
        const lostRows=completed.filter(row=>classifyIxcConnectionCause(row.cause).key==='lost');
        const fallbackDurations=completed.map(row=>Number(row.seconds)).filter(value=>Number.isFinite(value)&&value>=0);
        const fallbackAvg=fallbackDurations.length?Math.round(fallbackDurations.reduce((sum,value)=>sum+value,0)/fallbackDurations.length):null;
        const cutoff=Date.now()-(24*60*60*1000);
        const fallbackLast24=completed.filter(row=>{
            const time=new Date(row.stopped_at).getTime();
            return Number.isFinite(time)&&time>=cutoff;
        });
        const fallbackLast24Lost=fallbackLast24.filter(row=>classifyIxcConnectionCause(row.cause).key==='lost');
        const current=rows.find(row=>row.online)||null;

        const connections=Number.isFinite(Number(server.connections))?Number(server.connections):rows.length;
        const disconnects=Number.isFinite(Number(server.disconnects))?Number(server.disconnects):completed.length;
        const lost=Number.isFinite(Number(server.lost))?Number(server.lost):lostRows.length;
        const avgSeconds=server.average_connected_seconds===null||server.average_connected_seconds===undefined
            ? fallbackAvg
            : Number(server.average_connected_seconds);
        const last24=Number.isFinite(Number(server.last_24h_disconnects))
            ? Number(server.last_24h_disconnects)
            : fallbackLast24.length;
        const last24Lost=Number.isFinite(Number(server.last_24h_lost))
            ? Number(server.last_24h_lost)
            : fallbackLast24Lost.length;

        let level='stable';
        let title='Estável';
        let message='Nenhuma desconexão registrada nas últimas 24 horas.';
        if(last24>0){
            level=(last24>=4||last24Lost>=2)?'danger':'warning';
            title=level==='danger'?'Instabilidade detectada':'Atenção';
            message=last24+' desconexão(ões) nas últimas 24h'+
                (last24Lost?' • '+last24Lost+' perda(s) de conexão':'')+'.';
        }

        return {
            connections,
            disconnects,
            lost,
            avgSeconds,
            last24,
            last24Lost,
            current,
            level,
            title,
            message
        };
    }

    function renderIxcStabilitySummary(report) {
        const metrics=connectionStabilityMetrics(report);
        const avg=metrics.avgSeconds===null?'N/D':fmtDuration(metrics.avgSeconds);
        const current=metrics.current;
        const currentHtml=current
            ? '<div class="jr-stability-current">'+
                '<span class="jr-stability-online-dot"></span>'+
                '<strong>ONLINE</strong>'+
                '<span>Conectado há '+esc(fmtDuration(Number(current.seconds)||0))+'</span>'+
                '<span>IP '+esc(current.ip||'N/D')+'</span>'+
                '<span>MAC '+esc(current.mac||'N/D')+'</span>'+
              '</div>'
            : '<div class="jr-stability-current offline"><strong>SEM SESSÃO ATIVA</strong><span>Nenhuma conexão PPPoE online no relatório atual.</span></div>';

        return '<div class="jr-stability-panel">'+
            '<div class="jr-stability-kpis">'+
                '<div><span>Conexões</span><strong>'+metrics.connections+'</strong><small>últimos 7 dias</small></div>'+
                '<div><span>Desconexões</span><strong>'+metrics.disconnects+'</strong><small>últimos 7 dias</small></div>'+
                '<div><span>Perda de conexão</span><strong>'+metrics.lost+'</strong><small>Lost-Carrier / similares</small></div>'+
                '<div><span>Tempo médio conectado</span><strong>'+esc(avg)+'</strong><small>sessões encerradas</small></div>'+
            '</div>'+
            '<div class="jr-stability-alert '+metrics.level+'">'+
                '<i class="bi '+(metrics.level==='stable'?'bi-check-circle-fill':metrics.level==='warning'?'bi-exclamation-triangle-fill':'bi-exclamation-octagon-fill')+'"></i>'+
                '<div><strong>'+metrics.title+'</strong><span>'+esc(metrics.message)+'</span></div>'+
            '</div>'+
            currentHtml+
        '</div>';
    }

    window.jrFilterConnectionReport=function(filter,button){
        const table=document.querySelector('.jr-ixc-connection-table');
        if(!table)return;
        let visible=0;
        table.querySelectorAll('tbody tr').forEach(row=>{
            let show=true;
            if(filter==='24h')show=row.dataset.last24==='1';
            else if(filter==='disconnects')show=row.dataset.online==='0';
            else if(filter!=='all')show=row.dataset.cause===filter;
            row.hidden=!show;
            if(show)visible++;
        });
        document.querySelectorAll('.jr-connection-filter').forEach(item=>item.classList.remove('active'));
        if(button)button.classList.add('active');
        const count=document.getElementById('jr-connection-visible-count');
        if(count)count.textContent=visible+' registro(s)';
    };

    function renderIxcConnectionReport(report) {
        const rows=Array.isArray(report?.last_7_days?.connections)?report.last_7_days.connections:[];
        if(!rows.length)return '<div class="jr-ixc-connection-report"><div class="jr-ixc-connection-report-head"><strong>Relatório de conexões</strong><span>Sem registros</span></div><div class="jr-ixc-empty jr-ixc-connection-empty">Nenhuma conexão encontrada nos últimos 7 dias.</div></div>';

        const cutoff=Date.now()-(24*60*60*1000);
        const body=rows.map(row=>{
            const seconds=Number(row.seconds);
            const cause=classifyIxcConnectionCause(row.cause);
            const stopTime=row.stopped_at?new Date(row.stopped_at).getTime():Number.NaN;
            const startTime=row.started_at?new Date(row.started_at).getTime():Number.NaN;
            const last24=(Number.isFinite(stopTime)&&stopTime>=cutoff)||(row.online&&Number.isFinite(startTime)&&startTime>=cutoff);
            return '<tr class="'+(row.online?'is-online':'')+'" data-cause="'+esc(cause.key)+'" data-online="'+(row.online?'1':'0')+'" data-last24="'+(last24?'1':'0')+'">'+
                '<td>'+esc(row.id??'—')+'</td>'+
                '<td>'+esc(row.ip||'—')+'</td>'+
                '<td>'+esc(row.mac||'—')+'</td>'+
                '<td class="jr-ixc-time-cell">'+reportDateTimeCell(row.started_at)+'</td>'+
                '<td class="jr-ixc-time-cell">'+reportDateTimeCell(row.stopped_at)+'</td>'+
                '<td>'+esc(Number.isFinite(seconds)?fmtDuration(seconds):'—')+'</td>'+
                '<td>'+esc(reportBytes(row.upload_bytes||0))+'</td>'+
                '<td>'+esc(reportBytes(row.download_bytes||0))+'</td>'+
                '<td class="jr-ixc-cause"><span class="jr-cause-badge '+cause.key+'" title="'+esc(cause.label)+'">'+esc(cause.label)+'</span></td>'+
            '</tr>';
        }).join('');

        const filters=[
            ['all','Todas'],
            ['24h','Últimas 24h'],
            ['disconnects','Quedas'],
            ['lost','Lost-Carrier'],
            ['user','User-Request'],
            ['nas','NAS'],
            ['admin','Admin']
        ].map(([key,label],index)=>'<button type="button" class="jr-connection-filter '+(index===0?'active':'')+'" onclick="window.jrFilterConnectionReport(\''+key+'\',this)">'+label+'</button>').join('');

        return '<div class="jr-ixc-connection-report">'+
            '<div class="jr-ixc-connection-report-head"><strong>Relatório de conexões</strong><span id="jr-connection-visible-count">'+rows.length+' registro(s)</span></div>'+
            '<div class="jr-connection-filters">'+filters+'</div>'+
            '<div class="jr-ixc-connection-table-wrap"><table class="jr-ixc-connection-table">'+
                '<thead><tr><th>ID</th><th>IP</th><th>MAC</th><th>Hora do Login</th><th>Hora do Logout</th><th>Tempo Conectado</th><th>Upload</th><th>Download</th><th>Motivo de desconexão</th></tr></thead>'+
                '<tbody>'+body+'</tbody>'+
            '</table></div>'+
        '</div>';
    }

    function renderIxcConsumption(report) {
        const daily=Array.isArray(report?.last_30_days?.daily)?report.last_30_days.daily:[];
        if(!daily.length)return '<div class="jr-ixc-empty">Sem consumo disponível nos últimos 30 dias.</div>';
        const values=daily.map(d=>({
            date:d.date,
            down:Number(d.download_bytes||0),
            up:Number(d.upload_bytes||0)
        }));
        const max=Math.max(1,...values.flatMap(v=>[v.down,v.up]));
        const bars=values.map(v=>{
            const dh=Math.max(2,(v.down/max)*100);
            const uh=Math.max(2,(v.up/max)*100);
            const title=reportDateLabel(v.date)+' • ↓ '+reportBytes(v.down)+' • ↑ '+reportBytes(v.up);
            return '<div class="jr-ixc-cons-day" title="'+esc(title)+'"><i class="down" style="height:'+dh.toFixed(1)+'%"></i><i class="up" style="height:'+uh.toFixed(1)+'%"></i></div>';
        }).join('');
        const total=report?.last_30_days?.total_bytes||0;
        return '<div class="jr-ixc-consumption"><div class="jr-ixc-cons-total">'+reportBytes(total)+'</div><div class="jr-ixc-cons-bars">'+bars+'</div><div class="jr-ixc-cons-axis"><span>'+reportDateLabel(values[0]?.date)+'</span><span>'+reportDateLabel(values[values.length-1]?.date)+'</span></div></div><div class="jr-ixc-event-legend"><span><i class="download"></i>Download</span><span><i class="upload"></i>Upload</span></div>';
    }
    function renderIxcAccessSummary(report) {
        const login=report?.login||{};
        const conc=report?.concentrator||{};
        const reportSession=report?.current_session||{};
        const session=traffic.latestSession||{};
        // A sessão RADIUS ativa é a fonte mais atual para estes campos.
        const connectedSeconds=session.seconds??reportSession.seconds??login.connected_seconds??null;
        const connected=connectedSeconds===null?'N/D':fmtDuration(connectedSeconds);
        const concentrator=traffic.concentrator?.name||session.bras||conc.name||conc.ip||'N/D';
        const rows=[
            ['Login',session.username||reportSession.username||login.username||'N/D','bi-person-check'],
            ['Conectado a',connected,'bi-clock-history'],
            ['IPv4',session.ip||reportSession.ip||login.ipv4||'N/D','bi-hdd-network'],
            ['IPv6',login.ipv6||'Sem resultado','bi-diagram-3'],
            ['Concentrador',concentrator,'bi-router'],
            ['Tecnologia',session.technology||reportSession.technology||'Fibra','bi-broadcast'],
            ['Tipo de autenticação',session.auth_type||reportSession.auth_type||'PPPoE','bi-key'],
            ['Interface de conexão',session.interface||reportSession.interface||login.interface||'N/D','bi-ethernet'],
            ['MAC',session.mac||reportSession.mac||login.mac||'N/D','bi-upc-scan']
        ];
        return rows.map(([label,value,icon])=>'<div><span><i class="bi '+icon+'"></i>'+esc(label)+'</span><strong>'+esc(value)+'</strong></div>').join('');
    }
    function refreshIxcAccessSummary() {
        const summary=document.getElementById('jr-ixc-access-summary');
        if (!summary) return;
        const html=renderIxcAccessSummary(traffic.report||{});
        if (summary.innerHTML!==html) summary.innerHTML=html;
    }
    /* JRC_WAN_TAB_USAGE_V1 */
    function jrcConsumptionVisible() {
        return jrcWanNodes('jr-ixc-consumption').some(jrcWanVisible);
    }
    function jrcPaintConsumption() {
        const data = traffic.report;
        if (!data) return false;
        const html = renderIxcConsumption(data);
        jrcWanNodes('jr-ixc-consumption').forEach(node => {
            if (node.innerHTML !== html) node.innerHTML = html;
        });
        const label = (data?.last_30_days?.source || 'IXC/RADIUS') + ' • ' +
            (traffic.reportUpdatedLabel || new Date().toLocaleTimeString('pt-BR'));
        jrcWanNodes('jr-ixc-consumption-source').forEach(node => { node.textContent = label; });
        return true;
    }
    async function loadIxcReplicaReport(force=false) {
        if (!monitoringActive() && !jrcConsumptionVisible()) return;
        const loginId=traffic.ixcLoginId||null;
        const username=traffic.latestSession?.username||null;
        if((!loginId && !username) || traffic.reportLoading)return;

        const reportKey=loginId?'id:'+String(loginId):'login:'+String(username);
        const now=Date.now();
        // Histórico não precisa consultar o IXC a cada segundo. Atualiza a cada 60 s.
        if(!force && traffic.reportPollKey===reportKey && now-traffic.reportLastPoll<60000)return;

        const params=new URLSearchParams();
        if(loginId) params.set('login_id',loginId);
        else params.set('login',username);

        traffic.reportLoading=true;
        traffic.reportPollKey=reportKey;
        traffic.reportLastPoll=now;
        try{
            const r=await fetch('/api/get-ixc-login-report.php?'+params.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
            const data=await r.json();
            if(!r.ok||!data?.success)throw new Error(data?.message||'Relatório IXC indisponível.');
            traffic.report=data;
            traffic.reportLoadedFor=reportKey;
            const events=document.getElementById('jr-ixc-events');
            const eventsSource=document.getElementById('jr-ixc-events-source');
            const consumption=document.getElementById('jr-ixc-consumption');
            const consumptionSource=document.getElementById('jr-ixc-consumption-source');
            const generatedAt=new Date(data.generated_at||Date.now());
            const updatedLabel=Number.isNaN(generatedAt.getTime())?new Date().toLocaleTimeString('pt-BR'):generatedAt.toLocaleTimeString('pt-BR');

            refreshIxcAccessSummary();
            if(events)events.innerHTML=renderIxcStabilitySummary(data)+renderIxcEventHistory(data)+renderIxcConnectionReport(data);
            if(eventsSource){
                const eventCount=Number(data?.last_7_days?.event_count||0);
                const connectionCount=Number(data?.last_7_days?.connection_count||0);
                eventsSource.textContent=(data?.last_7_days?.source||'IXC/RADIUS')+' • '+eventCount+' evento(s) • '+connectionCount+' conexão(ões) • '+updatedLabel;
            }
            traffic.reportUpdatedLabel=updatedLabel;
            jrcPaintConsumption();
        }catch(e){
            console.warn('[IXC MONITOR] relatório indisponível',e);
            const eventsSource=document.getElementById('jr-ixc-events-source');
            const consumptionSource=document.getElementById('jr-ixc-consumption-source');
            if(eventsSource)eventsSource.textContent='IXC/RADIUS • falha na atualização';
            jrcWanNodes('jr-ixc-consumption-source').forEach(node => { node.textContent='IXC/RADIUS • falha na atualização'; });
        }finally{traffic.reportLoading=false;}
    }

    window.loadIxcReplicaReport = loadIxcReplicaReport;

    /* JRC_WAN_CHART_VISIBLE_V1 */
    /* JRC_WAN_CHART_SELFCONTAINED_V1 */
    function chartHtml(samples) {
        const cutoff=Date.now()-(5*60*1000);
        const recent=(Array.isArray(samples)?samples:[])
            .filter(s=>Number(s.at)>=cutoff && Number.isFinite(s.down)&&Number.isFinite(s.up))
            .sort((a,b)=>Number(a.at)-Number(b.at));

        if(!recent.length){
            return '<div class="jr-monitor-wait"><i class="bi bi-activity"></i><span>Aguardando dados de trafego...</span></div>';
        }

        const rawMax=Math.max(0.000001,...recent.flatMap(s=>[Math.max(0,s.down),Math.max(0,s.up)]));
        let factor=1,unit='Mbps';
        if(rawMax<0.001){factor=1000000;unit='bps';}
        else if(rawMax<1){factor=1000;unit='Kbps';}

        const peak=rawMax*factor;
        const displayMax=Math.max(unit==='Mbps'?0.10:1, peak*1.20);
        const W=760,H=230;
        const L=62,R=738,T=18,B=188;
        const plotW=R-L, plotH=B-T;

        const x=(i)=>recent.length===1 ? L+plotW/2 : L+(i/(recent.length-1))*plotW;
        const y=(value)=>{
            const scaled=Math.max(0,Number(value)||0)*factor;
            return B-(scaled/displayMax)*plotH;
        };
        const clampY=value=>Math.max(T,Math.min(B,y(value)));

        const fmtAxis=v=>v>=100?v.toFixed(0):v>=10?v.toFixed(1):v.toFixed(2);
        const fmtTime=t=>new Date(t).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});

        const pathFor=field=>{
            if(recent.length===1){
                const xx=x(0).toFixed(2), yy=clampY(recent[0][field]).toFixed(2);
                return 'M '+xx+' '+yy+' L '+xx+' '+yy;
            }
            return recent.map((s,i)=>(i?'L ':'M ')+x(i).toFixed(2)+' '+clampY(s[field]).toFixed(2)).join(' ');
        };

        const dots=(field,color)=>recent.map((s,i)=>
            '<circle cx="'+x(i).toFixed(2)+'" cy="'+clampY(s[field]).toFixed(2)+'" r="3" fill="'+color+'">'+
            '<title>'+fmtTime(s.at)+' • '+field.toUpperCase()+' '+(Math.max(0,Number(s[field])||0)*factor).toFixed(2)+' '+unit+'</title></circle>'
        ).join('');

        const areaFor=field=>{
            const pts=recent.map((s,i)=>x(i).toFixed(2)+','+clampY(s[field]).toFixed(2)).join(' ');
            return L+','+B+' '+pts+' '+R+','+B;
        };

        const first=fmtTime(recent[0].at);
        const last=fmtTime(recent[recent.length-1].at);
        const midX=(L+R)/2;
        const topLabel=fmtAxis(displayMax);
        const midLabel=fmtAxis(displayMax/2);

        return '<div style="width:100%;height:230px;position:relative;overflow:hidden">'+
          '<svg viewBox="0 0 '+W+' '+H+'" preserveAspectRatio="none" style="display:block;width:100%;height:230px;overflow:visible" role="img" aria-label="Grafico WAN em tempo real">'+
            '<defs>'+
              '<linearGradient id="jrcWanDownArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#38bdf8" stop-opacity=".22"/><stop offset="100%" stop-color="#38bdf8" stop-opacity="0"/></linearGradient>'+
              '<linearGradient id="jrcWanUpArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#34d399" stop-opacity=".18"/><stop offset="100%" stop-color="#34d399" stop-opacity="0"/></linearGradient>'+
            '</defs>'+
            '<line x1="'+L+'" y1="'+T+'" x2="'+R+'" y2="'+T+'" stroke="#334155" stroke-width="1" stroke-dasharray="4 5"/>'+
            '<line x1="'+L+'" y1="'+((T+B)/2)+'" x2="'+R+'" y2="'+((T+B)/2)+'" stroke="#334155" stroke-width="1" stroke-dasharray="4 5"/>'+
            '<line x1="'+L+'" y1="'+B+'" x2="'+R+'" y2="'+B+'" stroke="#475569" stroke-width="1"/>'+
            '<text x="'+(L-10)+'" y="'+(T+4)+'" text-anchor="end" fill="#8da0b8" font-size="12">'+topLabel+' '+unit+'</text>'+
            '<text x="'+(L-10)+'" y="'+(((T+B)/2)+4)+'" text-anchor="end" fill="#8da0b8" font-size="12">'+midLabel+' '+unit+'</text>'+
            '<text x="'+(L-10)+'" y="'+(B+4)+'" text-anchor="end" fill="#8da0b8" font-size="12">0 '+unit+'</text>'+
            '<polygon points="'+areaFor('down')+'" fill="url(#jrcWanDownArea)"/>'+
            '<polygon points="'+areaFor('up')+'" fill="url(#jrcWanUpArea)"/>'+
            '<path d="'+pathFor('down')+'" fill="none" stroke="#38bdf8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'+
            '<path d="'+pathFor('up')+'" fill="none" stroke="#34d399" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'+
            dots('down','#38bdf8')+dots('up','#34d399')+
            '<text x="'+L+'" y="211" text-anchor="start" fill="#8da0b8" font-size="11">'+first+'</text>'+
            '<text x="'+midX+'" y="211" text-anchor="middle" fill="#738399" font-size="11">Ultimos 5 minutos</text>'+
            '<text x="'+R+'" y="211" text-anchor="end" fill="#8da0b8" font-size="11">'+last+'</text>'+
          '</svg>'+
        '</div>'+
        '<div class="jr-monitor-chart-legend" style="padding-top:2px">'+
          '<span><i class="down"></i>Download</span>'+
          '<span><i class="up"></i>Upload</span>'+
          '<span>'+recent.length+' amostra(s)</span>'+
        '</div>';
    }

    function actionFeedback(message, type='info') {
        if (typeof window.showToast === 'function') window.showToast(message, type);
        else console.log('[IXC ACTION]', message);
    }
    function setIxcActionBusy(busy) {
        document.querySelectorAll('[data-ixc-session-action]').forEach(button => {
            button.disabled = busy;
            button.classList.toggle('is-loading', busy);
        });
    }
    window.jrIxcSessionAction = async function(action) {
        const loginId = Number(traffic.ixcLoginId);
        if (!Number.isInteger(loginId) || loginId < 1) {
            actionFeedback('Login IXC ainda não foi identificado para esta sessão.', 'warning');
            return;
        }
        const label = action === 'disconnect' ? 'desconectar a sessão PPPoE' : 'limpar o MAC vinculado ao login';
        if (!window.confirm('Confirma ' + label + '?')) return;
        setIxcActionBusy(true);
        try {
            const response = await fetch('/api/ixc-session-action.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': state.csrf || ''},
                body: JSON.stringify({action, login_id: loginId})
            });
            const data = await response.json();
            if (!response.ok || !data?.success) throw new Error(data?.message || 'IXC não confirmou a ação.');
            actionFeedback(data.message || 'Ação enviada ao IXC.', 'success');
            if (action === 'disconnect') {
                traffic.latestSession = null;
                traffic.liveDisabledUntil = Date.now() + 3000;
            }
            window.updateRadiusBandwidthSample(true);
            window.loadIxcReplicaReport(true);
        } catch (error) {
            actionFeedback(error?.message || 'Falha ao executar a ação no IXC.', 'danger');
        } finally {
            setIxcActionBusy(false);
        }
    };
    function renderPingInfo(label, result) {
        const available=!!result?.available;
        const avg=Number(result?.avg_ms);
        const min=Number(result?.min_ms);
        const max=Number(result?.max_ms);
        const loss=Number(result?.loss_percent ?? (available?0:100));
        const latency=available&&Number.isFinite(avg)?avg.toFixed(2):'--';
        const minLabel=available&&Number.isFinite(min)?min.toFixed(2):'--';
        const maxLabel=available&&Number.isFinite(max)?max.toFixed(2):'--';
        const lossLabel=Number.isFinite(loss)?loss.toFixed(0):'--';

        return '<div class="jr-monitor-live-head jr-ping-live-head">'+
            '<div><span>Latência atual</span><strong id="jr-ping-current">'+latency+'</strong><small>ms</small></div>'+
            '<div><span>Mínima</span><strong id="jr-ping-min">'+minLabel+'</strong><small>ms</small></div>'+
            '<div><span>Máxima</span><strong id="jr-ping-max">'+maxLabel+'</strong><small>ms</small></div>'+
            '<div><span>Perda</span><strong id="jr-ping-loss">'+lossLabel+'</strong><small>%</small></div>'+
        '</div>';
    }
    function renderPingTimeline(samples) {
        const recent=samples.filter(sample=>Date.now()-sample.at<=5*60*1000);
        const valid=recent.filter(sample=>Number.isFinite(sample.latency));
        if (valid.length < 2) {
            return '<div class="jr-monitor-chart jr-ping-chart"><div class="jr-ixc-empty">Coleta automática pelo NE8000 • aguardando amostras...</div></div>';
        }

        const rawMax=Math.max(1,...valid.map(sample=>sample.latency));
        const displayMax=Math.max(5,Math.ceil(rawMax*1.2));
        const points=valid.map((sample,index)=>{
            const x=valid.length===1?0:index/(valid.length-1)*100;
            const y=94-(sample.latency/displayMax)*86;
            return x.toFixed(2)+','+Math.max(4,Math.min(94,y)).toFixed(2);
        }).join(' ');
        const first=new Date(valid[0].at).toLocaleTimeString('pt-BR');
        const last=new Date(valid[valid.length-1].at).toLocaleTimeString('pt-BR');
        const mid=displayMax/2;

        return '<div class="jr-monitor-chart jr-ping-chart">'+
            '<div class="jr-monitor-chart-inner">'+
                '<div class="jr-monitor-scale"><span>'+displayMax.toFixed(0)+' ms</span><span>'+mid.toFixed(1)+' ms</span><span>0 ms</span></div>'+
                '<div class="jr-monitor-plot"><svg viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0 8H100M0 36H100M0 64H100M0 94H100" class="grid"/><polyline points="'+points+'" class="ping-line"/></svg><div class="jr-monitor-times"><span>'+first+'</span><span>'+last+'</span></div></div>'+
            '</div>'+
            '<div class="jr-monitor-chart-legend"><span><i class="ping"></i>Latência</span><span>Últimos 5 minutos</span><span>Atualização automática • 1 s</span></div>'+
        '</div>';
    }
    window.jrLoadSessionPing = async function(silent=false) {
        if (!monitoringActive() || traffic.pingPolling) return;
        const now=Date.now();
        if (silent && now-traffic.pingLastPoll<1000) return;
        const session = traffic.latestSession || {};
        const clientIp = String(session.ip || '').trim();
        const nasIp = String(traffic.concentrator?.nas_ip || session.bras || '').trim();
        const target = document.getElementById('jr-ping-results');
        const status = document.getElementById('jr-ping-sample-status');

        if (!clientIp) {
            if(status) status.textContent='Aguardando sessão PPPoE';
            if (!silent && target) target.innerHTML='<div class="jr-ixc-empty">Aguardando IP da sessão PPPoE...</div>';
            return;
        }
        if (!target) return;

        traffic.pingPolling=true;
        traffic.pingLastPoll=now;
        if (!silent && !traffic.pingSamples.length) {
            target.innerHTML = '<div class="jr-ixc-empty">Iniciando ping automático pelo NE8000...</div>';
        }
        if(status) status.textContent='NE8000 • coletando automaticamente';

        try {
            const query = new URLSearchParams({ip: clientIp, nas_ip: nasIp, count: '1'});
            const response = await fetch('/api/get-ne-session-ping.php?' + query, {credentials:'same-origin', cache:'no-store'});
            const data = await response.json();
            if (!response.ok || !data?.success) throw new Error(data?.message || 'Ping indisponível.');
            const result=data.result || {};
            const at=Date.now();
            const latency=result.available && result.avg_ms !== null && result.avg_ms !== undefined
                ? Number(result.avg_ms)
                : Number.NaN;
            traffic.pingSamples.push({at,latency,loss:Number(result.loss_percent ?? 100)});
            if (traffic.pingSamples.length>300) traffic.pingSamples.shift();

            target.innerHTML = renderPingInfo('NE8000 → Cliente PPPoE', result) +
                renderPingTimeline(traffic.pingSamples) +
                '<small class="jr-ping-source">Origem: ' + esc(data.source || 'Huawei NE8000 / SSH') + ' • IP ' + esc(clientIp) + ' • última amostra ' + new Date(at).toLocaleTimeString('pt-BR') + '</small>';
            if(status) status.textContent='Huawei NE8000 • '+new Date(at).toLocaleTimeString('pt-BR');
        } catch (error) {
            if(status) status.textContent='NE8000 • mantendo última amostra válida';
            if (!silent || !traffic.pingSamples.length) {
                target.innerHTML = '<div class="jr-ixc-empty">' + esc(error?.message || 'Ping indisponível.') + '</div>';
            }
        } finally {
            traffic.pingPolling=false;
        }
    };

    window.renderMonitoringTab = function(device) {
        const wan=typeof getPrimaryWAN==='function'?getPrimaryWAN(device):null;
        return '<div class="jr-monitor-v3">'+
          '<div class="jr-monitor-source-strip">'+
            '<div class="jr-monitor-source-item primary"><span>TRÁFEGO EM TEMPO REAL</span><strong id="jr-source-live-badge">TR-069</strong><small id="jr-source-live-state">Aguardando contadores WAN do CPE</small></div>'+
            '<div class="jr-monitor-source-item"><span>SESSÃO / CONTABILIZAÇÃO</span><strong>IXC / RADIUS</strong><small>PPPoE, totais e histórico</small></div>'+
            '<div class="jr-monitor-source-item"><span>CPE / WI-FI</span><strong>TR-069</strong><small>Estado e gerenciamento do equipamento</small></div>'+
          '</div>'+
          '<div class="jr-ixc-report-card">'+
            '<div class="jr-ixc-report-title"><strong>Relatório</strong><span id="jr-radius-status" class="jr-monitor-badge">AGUARDANDO</span></div>'+
            '<div id="jr-ixc-access-summary" class="jr-ixc-access-summary">'+
              '<div><span><i class="bi bi-person-check"></i>Login</span><strong>'+esc(wan?.username||'N/D')+'</strong></div>'+
              '<div><span><i class="bi bi-clock-history"></i>Conectado a</span><strong>N/D</strong></div>'+
              '<div><span><i class="bi bi-hdd-network"></i>IPv4</span><strong>'+esc(wan?.external_ip||'N/D')+'</strong></div>'+
              '<div><span><i class="bi bi-router"></i>Concentrador</span><strong>N/D</strong></div>'+
            '</div>'+
            '<div class="jr-ixc-actions">'+
              '<button type="button" onclick="window.updateRadiusBandwidthSample(true); window.updateTr069LiveTraffic(true); window.loadIxcReplicaReport(true)"><i class="bi bi-arrow-clockwise"></i> Recarregar dados</button>'+
              '<button type="button" data-ixc-session-action onclick="window.jrIxcSessionAction(\'clear_mac\')"><i class="bi bi-eraser"></i> Limpar MAC</button>'+
              '<button type="button" data-ixc-session-action onclick="window.jrIxcSessionAction(\'disconnect\')"><i class="bi bi-person-x"></i> Desconectar sessão</button>'+

              '<button type="button" onclick="document.getElementById(\'wifi-tab\')?.click()"><i class="bi bi-gear"></i> Dados Roteador</button>'+
            '</div>'+
          '</div>'+
          '<div class="jr-ixc-section">'+
            '<div class="jr-ixc-section-title"><strong>Tráfego em tempo real dos últimos 5 minutos</strong><span id="bandwidth-sample-status">Aguardando contadores WAN via TR-069...</span></div>'+
            '<div class="jr-monitor-live-head"><div><span>Download</span><strong id="live-rx-mbps">--</strong><small id="live-rx-unit">Mbps</small></div><div><span>Upload</span><strong id="live-tx-mbps">--</strong><small id="live-tx-unit">Mbps</small></div><div><span>Baixado na sessão</span><strong id="live-rx-total">--</strong><small>RADIUS</small></div><div><span>Enviado na sessão</span><strong id="live-tx-total">--</strong><small>RADIUS</small></div></div>'+
            '<div id="bandwidth-bars" class="jr-monitor-chart"></div>'+
          '</div>'+
          '<div class="jr-ixc-section">'+
            '<div class="jr-ixc-section-title"><strong>Histórico e estabilidade da conexão — 7 dias</strong><span id="jr-ixc-events-source">Carregando IXC/RADIUS...</span></div>'+
            '<div id="jr-ixc-events" class="jr-ixc-events"><div class="jr-ixc-empty">Carregando histórico...</div></div>'+
          '</div>'+
          '<div class="jr-ixc-section">'+
            '<div class="jr-ixc-section-title"><strong>Consumo dos últimos 30 dias</strong><span id="jr-ixc-consumption-source">Carregando fonte...</span></div>'+
            '<div id="jr-ixc-consumption"><div class="jr-ixc-empty">Carregando consumo...</div></div>'+
          '</div>'+
          '<div class="jr-ixc-section jr-ixc-ping-section">'+
            '<div class="jr-ixc-section-title"><strong>Ping da sessão PPPoE</strong><span id="jr-ping-sample-status">Aguardando sessão PPPoE</span></div>'+
            '<div id="jr-ping-results" class="jr-ping-results"><div class="jr-ixc-empty">A coleta inicia automaticamente quando a sessão PPPoE for identificada.</div></div>'+
          '</div>'+
          '<div class="jr-monitor-report">'+
            '<div class="jr-monitor-section-title"><strong>Detalhes da sessão atual</strong><span id="jr-monitor-source">IXC/RADIUS</span></div>'+
            '<div class="jr-monitor-report-grid">'+
              '<div><span>Usuário PPPoE</span><strong id="jr-radius-user">'+esc(wan?.username||'N/D')+'</strong></div>'+
              '<div><span>IP WAN</span><strong id="jr-radius-ip">'+esc(wan?.external_ip||'N/D')+'</strong></div>'+
              '<div><span>Interface</span><strong id="jr-radius-interface">'+esc(wan?.name||'N/D')+'</strong></div>'+
              '<div><span>Tempo de sessão</span><strong id="jr-radius-uptime">'+esc(wan?.uptime?fmtDuration(wan.uptime):'N/D')+'</strong></div>'+
              '<div><span>Plano</span><strong id="jr-radius-plan">'+esc(monitorPlanLabel())+'</strong></div>'+
              '<div><span>BRAS / Concentrador</span><strong id="jr-monitor-bras">N/D</strong></div>'+
              '<div><span>Última contabilização</span><strong id="jr-monitor-last-accounting">N/D</strong></div>'+
              '<div><span>Origem ao vivo</span><strong id="jr-monitor-live-source">TR-069 / aguardando</strong></div>'+
            '</div>'+
          '</div>'+
        '</div>';
    };

    window.updateTr069LiveTraffic = async function(force=false) {
        if(!jrcWanActive() || traffic.livePolling || !window.DEVICE_ID) return;
        if (traffic.ixcSse && traffic.ixcSse.readyState !== EventSource.CLOSED) return;

        const now=Date.now();
        // Mantém uma amostra por segundo, como o gráfico original do IXC.
        // A coleta só ocorre com a aba Monitoramento ativa.
        if(!force && now-traffic.liveLastPoll<(monitoringActive()?1000:5000)) return;
        if(!force && traffic.liveDisabledUntil>now) return;

        traffic.liveLastPoll=now;
        traffic.livePolling=true;

        try{
            setMonitorText('bandwidth-sample-status','Consultando trafego WAN...');
            const reasons = [];
            const fetchLive = async url => {
                const payload = await jrcWanJson(url);
                const label = new URL(url, window.location.href).pathname
                    .replace('/api/get-', '').replace('.php', '');
                if (!payload.success || !payload.available || !payload.live ||
                    jrcWanNumber(payload.live.download_mbps) === null ||
                    jrcWanNumber(payload.live.upload_mbps) === null) {
                    reasons.push(label + ': ' + (payload.message || 'sem amostra valida'));
                    return {...payload, available:false};
                }
                return payload;
            };

            let data=null;
            let sourceKey='';
            let sourceLabel='';

            // Fonte preferida: o mesmo stream de tráfego exibido pelo IXC.
            if(traffic.ixcLoginId){
                const ixc=await fetchLive(
                    '/api/get-ixc-live-traffic.php?login_id='+encodeURIComponent(traffic.ixcLoginId)
                );
                if(ixc?.available&&ixc?.live){
                    data=ixc;
                    sourceKey='ixc';
                    sourceLabel=ixc.source||'IXC em tempo real';
                }
            }

            // Segunda fonte: leitura direta e somente leitura no BRAS/NE8000.
            const session=traffic.latestSession;
            if(!data&&session&&(session.ip||session.username)){
                const query=new URLSearchParams({
                    ip:session.ip||'',
                    username:session.username||'',
                    nas_ip:session.bras||'',
                    session_id:session.session_id||''
                });
                const ne=await fetchLive('/api/get-ne-live-traffic.php?'+query.toString());
                if(ne?.available&&ne?.live){
                    data=ne;
                    sourceKey='ne';
                    sourceLabel=ne.source||'Huawei NE8000';
                    traffic.concentrator=ne.concentrator||traffic.concentrator;
                    refreshIxcAccessSummary();
                }
            }

            // Último fallback: TR-069 apenas para contadores PPP/IP.
            // WANCommon da FiberHome mede a interface física/gerência e não
            // representa o consumo PPPoE mostrado pelo IXC.
            let tr069=null;
            if(!data){
                tr069=await fetchLive(
                    '/api/get-tr069-live-traffic.php?device_id='+encodeURIComponent(window.DEVICE_ID)
                );
                const profile=String(tr069?.profile||'');
                const managementOnly=/WAN common/i.test(profile);
                if(tr069?.available&&tr069?.live&&!managementOnly){
                    data=tr069;
                    sourceKey='tr069';
                    sourceLabel='TR-069 • '+profile;
                }else if(managementOnly){
                    traffic.liveReason='wancommon_management_only';
                }
            }

            if(!data?.live){
                traffic.liveAvailable=false;
                traffic.liveDisabledUntil=Date.now()+4000;
                const keptLastSample = jrcWanPaintLastSample(
                    'Sem nova amostra do IXC/BRAS; mantendo a ultima leitura valida.'
                );
                if(!keptLastSample){
                    setMonitorText('live-rx-mbps','--');
                    setMonitorText('live-tx-mbps','--');
                    setMonitorText('live-rx-unit','Mbps');
                    setMonitorText('live-tx-unit','Mbps');
                }
                setMonitorText('jr-source-live-badge',keptLastSample ? 'ULTIMA AMOSTRA' : 'AGUARDANDO');
                setMonitorText('jr-source-live-state',
                    traffic.liveReason==='wancommon_management_only'
                        ? 'WANCommon ignorado: contador de gerência'
                        : 'localizando stream IXC/BRAS'
                );
                setMonitorText('jr-monitor-live-source','IXC/BRAS • aguardando');
                const status=jrcWanElement('bandwidth-sample-status');
                if(status && !keptLastSample) status.textContent=
                    traffic.liveReason==='wancommon_management_only'
                        ? 'Contador TR-069 WANCommon ignorado; aguardando trafego real do IXC/BRAS.'
                        : (reasons.join(' | ') || 'Nenhuma amostra WAN valida.');
                if(!keptLastSample){
                    jrcWanNodes('bandwidth-bars').forEach(node => { node.innerHTML=chartHtml([]); });
                }
                return;
            }

            const down=Number(data.live.download_mbps);
            const up=Number(data.live.upload_mbps);
            if(!Number.isFinite(down)||!Number.isFinite(up))return;

            const at=parseRadiusTime(data.live.sample_time)||Date.now();
            traffic.liveAvailable=true;
            traffic.liveReason=null;
            traffic.liveDisabledUntil=0;
            traffic.liveSource=sourceKey;
            traffic.samples.push({at,down:Math.max(0,down),up:Math.max(0,up),source:sourceKey});
            if(traffic.samples.length>90)traffic.samples.shift();

            setLiveRate('live-rx',down);
            setLiveRate('live-tx',up);
            setMonitorText('jr-monitor-source',sourceLabel+' + IXC/RADIUS');
            setMonitorText('jr-monitor-live-source',sourceLabel);
            setMonitorText('jr-source-live-badge',sourceKey==='ixc'?'IXC AO VIVO':sourceKey==='ne'?'NE8000':'TR-069');
            setMonitorText('jr-source-live-state',sourceLabel+' • leitura confirmada');

            const status=jrcWanElement('bandwidth-sample-status');
            if(status)status.textContent=sourceLabel.toUpperCase()+' • '+new Date(at).toLocaleTimeString('pt-BR');

            const chart=jrcWanElement('bandwidth-bars');
            if(chart)chart.innerHTML=chartHtml(traffic.samples);

        }catch(e){
            traffic.liveAvailable=false;
            traffic.liveReason='request_failed';
            traffic.liveDisabledUntil=Date.now()+5000;
            setMonitorText('jr-source-live-state','IXC/BRAS temporariamente sem atualizacao');
            setMonitorText('jr-monitor-live-source','Ultima amostra valida mantida');
            if(!jrcWanPaintLastSample('Sem nova amostra do IXC/BRAS; mantendo a ultima leitura valida.')){
                setMonitorText('bandwidth-sample-status','Falha temporaria na consulta WAN; aguardando nova amostra.');
            }
        }finally{
            traffic.livePolling=false;
        }
    };

    window.updateRadiusBandwidthSample = async function(force=false) {
        if (!jrcWanActive() || traffic.polling || !window.DEVICE_ID) return;
        const now=Date.now();
        if(!force && now-traffic.lastPoll<4500)return;
        traffic.lastPoll=now; traffic.polling=true;
        try{
            const data=await jrcWanJson('/api/get-radius-session.php?device_id='+encodeURIComponent(window.DEVICE_ID));
            const badge=document.getElementById('jr-radius-status'), status=jrcWanElement('bandwidth-sample-status');
            if(!data?.success||!data?.online||!data?.session){
                traffic.online=false; traffic.latestSession=null; traffic.ixcLoginId=null;
                setMonitorText('bandwidth-sample-status','IXC/RADIUS: '+(data?.message || 'sessao online nao localizada.'));
                setMonitorText('live-rx-mbps','--'); setMonitorText('live-tx-mbps','--');
                if(badge){badge.textContent='SEM SESSÃO';badge.classList.remove('online');}
                setMonitorText('live-rx-total','--');
                setMonitorText('live-tx-total','--');
                updateMonitoringInsights();
                return;
            }

            const s=data.session;
            traffic.online=true; traffic.latestSession=s;
            traffic.ixcLoginId=data.ixc_login_id || traffic.ixcLoginId || null;
            if (traffic.ixcLoginId) jrcStartIxcSse(traffic.ixcLoginId);
            refreshIxcAccessSummary();
            if((monitoringActive() || jrcConsumptionVisible()) && (traffic.ixcLoginId || s.username)) loadIxcReplicaReport();
            if(badge){badge.textContent='ONLINE';badge.classList.add('online');}

            // RADIUS: output = download do assinante; input = upload do assinante.
            const down=Number(s.download_bytes), up=Number(s.upload_bytes), sec=Number(s.seconds);
            const key=String(s.session_id ?? '')+'|'+String(s.started_at ?? '')+'|'+String(s.username ?? '');
            if(traffic.sessionKey!==key){
                // A sessão RADIUS não é mais a fonte do gráfico ao vivo.
                // Trocar/descobrir a sessão não deve apagar amostras TR-069.
                traffic.sessionKey=key; traffic.last=null; traffic.lastAccountingAt=null;
                traffic.pingSamples=[]; traffic.pingLastPoll=0;
            }
            if(typeof window.jrLoadSessionPing==='function') window.jrLoadSessionPing(true);

            const accountAt=parseRadiusTime(s.sample_time) || now;
            traffic.lastAccountingAt=accountAt;

            const rxTotal=document.getElementById('live-rx-total'), txTotal=document.getElementById('live-tx-total');
            if(rxTotal)rxTotal.textContent=fmtBytes(down);
            if(txTotal)txTotal.textContent=fmtBytes(up);

            setMonitorText('jr-radius-user',s.username);
            setMonitorText('jr-radius-ip',s.ip);
            setMonitorText('jr-radius-interface',s.interface);
            setMonitorText('jr-radius-uptime',fmtDuration(sec));
            setMonitorText('jr-radius-plan',monitorPlanLabel());
            setMonitorText('jr-monitor-bras',s.bras || 'N/D');

            // O RADIUS permanece apenas como fonte de totais e contabilização.
            // O tráfego instantâneo agora vem dos contadores WAN via TR-069.
            if(Number.isFinite(down)&&Number.isFinite(up)&&Number.isFinite(sec)){
                traffic.last={down,up,sec,accountAt};
            }

        }catch(e){
            console.warn('[RADIUS MONITOR] sessão indisponível',e);
        }finally{traffic.polling=false;}
    };

    const originalLoad = window.loadDeviceDetail;
    if (typeof originalLoad === 'function') {
        window.loadDeviceDetail = async function(...args) {
            const result = await originalLoad.apply(this,args);
            await enhanceControls();
            // O auto-refresh recria o card da Visao geral. Repinta imediatamente
            // a ultima amostra valida antes da proxima consulta de rede.
            jrcWanPaintLastSample('Ultima amostra valida; atualizando trafego...');
            jrcPaintConsumption();
            setTimeout(() => {
                if (typeof window.updateRadiusBandwidthSample === 'function') {
                    window.updateRadiusBandwidthSample(true);
                }
                if (typeof window.updateTr069LiveTraffic === 'function') {
                    window.updateTr069LiveTraffic(true);
                }
            }, 0);
            return result;
        };
        try { loadDeviceDetail = window.loadDeviceDetail; } catch (_) {}
    }

    window.addEventListener('pagehide', () => jrcStopIxcSse());
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            jrcStopIxcSse();
        } else if (traffic.ixcLoginId) {
            jrcStartIxcSse(traffic.ixcLoginId);
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        jrcHideWanTechnicalStatus();
        setInterval(jrcHideWanTechnicalStatus, 1000);
        ensureModal();
        setTimeout(enhanceControls, 500);
        // A amostra é atualizada pela própria consulta. Não redesenhar o
        // gráfico a cada segundo: recriar o SVG sem uma nova amostra causava
        // o efeito visual de piscada na aba de monitoramento.
        window.setInterval(() => {
            if (typeof window.updateTr069LiveTraffic === 'function') {
                window.updateTr069LiveTraffic();
            }
            if (typeof window.jrLoadSessionPing === 'function') {
                window.jrLoadSessionPing(true);
            }
            if (typeof window.loadIxcReplicaReport === 'function') {
                window.loadIxcReplicaReport();
            }
        }, 1000);
        /* JRC_WAN_TAB_USAGE_V1 */
        document.getElementById('wan-tab')?.addEventListener('shown.bs.tab', () => {
            jrcWanPaintLastSample();
            jrcPaintConsumption();
            if (typeof window.updateRadiusBandwidthSample === 'function') window.updateRadiusBandwidthSample(true);
            if (typeof window.updateTr069LiveTraffic === 'function') window.updateTr069LiveTraffic(true);
            if (typeof window.loadIxcReplicaReport === 'function') window.loadIxcReplicaReport(true);
            if (traffic.ixcLoginId) jrcStartIxcSse(traffic.ixcLoginId);
        });
        document.getElementById('monitoring-tab')?.addEventListener('shown.bs.tab', () => {
            traffic.last=null; traffic.sessionKey=null;
            traffic.ixcLoginId=null; traffic.liveAvailable=false; traffic.liveReason=null; traffic.liveDisabledUntil=0; traffic.liveSource=null; traffic.concentrator=null; traffic.report=null; traffic.reportLoadedFor=null; traffic.reportPollKey=null; traffic.reportLastPoll=0; traffic.pingSamples=[]; traffic.pingLastPoll=0;
            window.updateRadiusBandwidthSample(true);
            window.updateTr069LiveTraffic(true);
            if (typeof window.jrLoadSessionPing === 'function') {
                window.jrLoadSessionPing(true);
            }
        });
    });
})();

/* JRC_ACOES_LOADER_V1 BEGIN */
(function () {
    if (window.__jrcAcoesLoader) return;
    window.__jrcAcoesLoader = true;
    var V = '0d07030ec7';
    function go() {
        if (!document.getElementById('acs-diagnostics-content')) return;
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = '/assets/css/jrc-acoes.css?v=' + V;
        document.head.appendChild(l);
        var s = document.createElement('script');
        s.src = '/assets/js/jrc-acoes.js?v=' + V;
        document.head.appendChild(s);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();
/* JRC_ACOES_LOADER_V1 END */
/* JRC_ACOES_WIDTH_JS_V2 */
(function () {
  /* JRC_ACOES_WIDTH_JS_V2 — estica até a borda de .main-content, sem parar na margem do menu. */
  function widen() {
      var jra = document.querySelector('#acs-diagnostics-content .jra') || document.querySelector('.jra');
      if (!jra) return;
      var jb = jra.getBoundingClientRect();
      if (jb.width < 40) return;
      var edge = jra.closest('.main-content');
      if (!edge) {
          var n = jra.parentElement;
          var bestRight = jb.right;
          while (n && n !== document.body && n !== document.documentElement) {
              var r = n.getBoundingClientRect();
              if (r.width > 80 && r.right > bestRight + 30) {
                  edge = n;
                  bestRight = r.right;
              }
              n = n.parentElement;
          }
      }
      if (!edge) return;
      var nodes = [];
      var x = jra;
      while (x && x !== edge) {
          nodes.push(x);
          x = x.parentElement;
      }
      if (!nodes.length) return;
      var cs = getComputedStyle(edge);
      var right = edge.getBoundingClientRect().right - (parseFloat(cs.paddingRight) || 0) - (parseFloat(cs.borderRightWidth) || 0);
      var left = nodes[nodes.length - 1].getBoundingClientRect().left;
      var target = Math.floor(right - left - 8);
      var cap = Math.floor(window.innerWidth - left - 16);
      if (target > cap) target = cap;
      if (target < 280) return;
      var forced = jra.getAttribute('data-jra-widen') === '1';
      if (!forced && target < jb.width + 48) return;
      if (forced && Math.abs(target - jb.width) < 16) return;
      var px = target + 'px';
      var i;
      for (i = 0; i < nodes.length; i++) {
          var y = nodes[i];
          var rw = y.getBoundingClientRect().width;
          if (y.getAttribute('data-jra-widen') !== '1' && rw >= target - 12) continue;
          y.setAttribute('data-jra-widen', '1');
          y.style.setProperty('box-sizing', 'border-box', 'important');
          y.style.setProperty('width', px, 'important');
          y.style.setProperty('max-width', 'none', 'important');
          y.style.setProperty('min-width', '0', 'important');
          y.style.setProperty('flex', '1 1 ' + px, 'important');
          y.style.setProperty('grid-column', '1 / -1', 'important');
          y.style.setProperty('justify-self', 'stretch', 'important');
          y.style.setProperty('overflow', 'visible', 'important');
      }
      var grid = jra.querySelector('.jra-grid');
      if (grid) {
          grid.style.setProperty('width', '100%', 'important');
          grid.style.setProperty('max-width', 'none', 'important');
      }
  }
  var n = 0;
  var iv = setInterval(function () {
    widen();
    if (++n > 40) clearInterval(iv);
  }, 500);
  window.addEventListener('resize', widen);
  document.addEventListener('click', function () { setTimeout(widen, 30); });
  document.addEventListener('shown.bs.tab', function () { setTimeout(widen, 30); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', widen);
  else widen();
})();
