(() => {
'use strict';
const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let devices=[],filtered=[],page=1;const size=20;
function statusOf(d){return String(d.status||'');}
function render(){
 const st=$('onu-status').value,serial=$('onu-serial').value.toLowerCase().trim(),model=$('onu-model').value.toLowerCase().trim(),client=$('onu-client').value.toLowerCase().trim();
 filtered=devices.filter(d=>(st==='ALL'||statusOf(d)===st)&&String(d.serial||'').toLowerCase().includes(serial)&&String(d.acs?.product_class||d.ixc?.onu_tipo||'').toLowerCase().includes(model)&&String(d.ixc?.nome||'').toLowerCase().includes(client));
 const pages=Math.max(1,Math.ceil(filtered.length/size));page=Math.min(page,pages);
 const list=filtered.slice((page-1)*size,page*size);
 $('onu-rows').innerHTML=list.length?list.map(d=>{const acs=d.acs||{},ixc=d.ixc||{},st=statusOf(d),label=st==='TR069_ACTIVE'?'TR-069 ativo':st==='TR069_STALE'?'Sem Inform':'Não no ACS',cls=st==='TR069_ACTIVE'?'ok':st==='TR069_STALE'?'warn':'',url=acs.registered&&acs.device_id?'/device-detail.php?id='+encodeURIComponent(acs.device_id):null;return '<tr><td><span class="onu-badge '+cls+'">'+label+'</span></td><td>'+esc(d.serial)+'</td><td>'+esc(acs.product_class||ixc.onu_tipo||'—')+'</td><td>'+esc(ixc.nome||'—')+'</td><td>'+esc(ixc.pon_id||'—')+'</td><td>'+esc(ixc.onu_numero||'—')+'</td><td>'+esc(acs.last_inform||'—')+'</td><td>'+(url?'<a class="onu-open" href="'+esc(url)+'">Abrir dispositivo</a>':'<span title="Autorização na OLT ainda não integrada">Indisponível</span>')+'</td></tr>'}).join(''):'<tr><td colspan="8" class="onu-empty">Nenhuma ONU encontrada para estes filtros.</td></tr>';
 $('onu-count').textContent=filtered.length+' registro(s)';$('onu-page-label').textContent='Página '+page+' de '+pages;$('onu-prev').disabled=page<=1;$('onu-next').disabled=page>=pages;
 document.querySelectorAll('.onu-tabs button').forEach(b=>b.classList.toggle('selected',b.dataset.status===st));
}

/* JRC_SECONDARY_PERF_V1 */
function jrcProvisionNormalizeLocal(d) {
    return {
        acs: {
            device_id: d.device_id || '',
            serial_number: d.serial_number || '',
            product_class: d.product_class || '',
            last_inform: d.last_inform || '',
            status: d.status || ''
        },
        ixc: {
            nome: '',
            pon_id: '',
            onu_numero: '',
            onu_tipo: ''
        }
    };
}

async function jrcProvisionLoadLocalInventory() {
    const out = [];
    let skip = 0;
    const limit = 500;

    while (true) {
        const response = await fetch(
            '/api/get-devices.php?limit=' + limit + '&skip=' + skip,
            {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }
        );

        const data = await response.json();

        if (!response.ok || !data || !data.success) {
            throw new Error((data && data.message) || 'Inventario local indisponivel');
        }

        const chunk = Array.isArray(data.devices) ? data.devices : [];
        out.push(...chunk.map(jrcProvisionNormalizeLocal));

        if (!data.hasMore || chunk.length === 0) break;

        skip += chunk.length;

        if (skip > 50000) {
            throw new Error('Limite de seguranca do inventario atingido');
        }
    }

    return out;
}

function jrcProvisionApplyDiscovery(data) {
    if (!data || !data.success) return false;

    const list = Array.isArray(data.devices) ? data.devices : [];
    if (!list.length) return false;

    devices = list;
    page = 1;
    render();
    return true;
}

function jrcProvisionReadDiscoveryCache() {
    try {
        const raw = sessionStorage.getItem('jrc.provision.discovery.v1');
        if (!raw) return null;

        const cached = JSON.parse(raw);
        if (!cached || !cached.savedAt || !cached.data) return null;

        if ((Date.now() - Number(cached.savedAt)) > (5 * 60 * 1000)) {
            sessionStorage.removeItem('jrc.provision.discovery.v1');
            return null;
        }

        return cached.data;
    } catch (e) {
        return null;
    }
}

function jrcProvisionSaveDiscoveryCache(data) {
    try {
        sessionStorage.setItem(
            'jrc.provision.discovery.v1',
            JSON.stringify({
                savedAt: Date.now(),
                data: data
            })
        );
    } catch (e) {
        console.debug('[PROVISIONAMENTO] Cache IXC indisponivel:', e);
    }
}

async function jrcProvisionLoadDiscovery(force) {
    if (!force) {
        const cached = jrcProvisionReadDiscoveryCache();
        if (cached && jrcProvisionApplyDiscovery(cached)) {
            return;
        }
    }

    const response = await fetch(
        '/api/discovery-ixc-tr069.php',
        {
            cache: force ? 'no-store' : 'default',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }
    );

    const data = await response.json();

    if (!response.ok || !data || !data.success) {
        throw new Error((data && data.message) || 'Falha na consulta IXC/ACS');
    }

    jrcProvisionSaveDiscoveryCache(data);
    jrcProvisionApplyDiscovery(data);
}

function jrcProvisionScheduleDiscovery() {
    const run = () => {
        jrcProvisionLoadDiscovery(false).catch(error => {
            console.warn('[PROVISIONAMENTO] Complemento IXC em segundo plano:', error);
        });
    };

    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(run, { timeout: 1500 });
    } else {
        window.setTimeout(run, 700);
    }
}


/* JRC_PROVISION_V2 */
function jrcProvNormSerial(value) {
    return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

function jrcProvNormalizeLocal(d) {
    var serial = String(d.serial_number || '');
    return {
        serial: serial,
        acs: {
            device_id: d.device_id || '',
            serial_number: serial,
            product_class: d.product_class || '',
            last_inform: d.last_inform || '',
            status: d.status || ''
        },
        ixc: {}
    };
}

async function jrcProvLoadLocalInventory() {
    var out = [];
    var skip = 0;
    var limit = 500;

    while (true) {
        var response = await fetch(
            '/api/get-devices.php?limit=' + limit + '&skip=' + skip,
            {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }
        );

        var data = await response.json();

        if (!response.ok || !data || !data.success) {
            throw new Error((data && data.message) || 'Inventario local indisponivel');
        }

        var chunk = Array.isArray(data.devices) ? data.devices : [];
        out = out.concat(chunk.map(jrcProvNormalizeLocal));

        if (!data.hasMore || chunk.length === 0) break;

        skip += chunk.length;
        if (skip > 50000) {
            throw new Error('Limite de seguranca do inventario atingido');
        }
    }

    return out;
}

function jrcProvApplyDiscovery(data) {
    if (!data || !data.success) return false;

    var list = Array.isArray(data.devices) ? data.devices : [];
    if (!list.length) return false;

    devices = list;
    page = 1;
    render();
    return true;
}

function jrcProvReadDiscoveryCache() {
    try {
        var raw = sessionStorage.getItem('jrc.provision.discovery.v2');
        if (!raw) return null;

        var cached = JSON.parse(raw);
        if (!cached || !cached.savedAt || !cached.data) return null;

        if ((Date.now() - Number(cached.savedAt)) > (5 * 60 * 1000)) {
            sessionStorage.removeItem('jrc.provision.discovery.v2');
            return null;
        }

        return cached.data;
    } catch (e) {
        return null;
    }
}

function jrcProvSaveDiscoveryCache(data) {
    try {
        sessionStorage.setItem(
            'jrc.provision.discovery.v2',
            JSON.stringify({
                savedAt: Date.now(),
                data: data
            })
        );
    } catch (e) {
        console.debug('[PROVISIONAMENTO] Cache discovery indisponivel:', e);
    }
}

async function jrcProvLoadDiscovery(force) {
    if (!force) {
        var cached = jrcProvReadDiscoveryCache();
        if (cached && jrcProvApplyDiscovery(cached)) {
            return;
        }
    }

    var response = await fetch(
        '/api/discovery-ixc-tr069.php',
        {
            cache: force ? 'no-store' : 'default',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }
    );

    var data = await response.json();

    if (!response.ok || !data || !data.success) {
        throw new Error((data && data.message) || 'Falha na consulta IXC/ACS');
    }

    jrcProvSaveDiscoveryCache(data);
    jrcProvApplyDiscovery(data);
}

function jrcProvScheduleDiscovery() {
    var run = function () {
        jrcProvLoadDiscovery(false).catch(function (error) {
            console.warn('[PROVISIONAMENTO] Complemento IXC/ACS em background:', error);
        });
    };

    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(run, { timeout: 1800 });
    } else {
        window.setTimeout(run, 900);
    }
}

async function load(forceDiscovery) {
    var force = forceDiscovery === true;
    var refresh = $('onu-refresh');
    var rows = $('onu-rows');

    if (refresh) refresh.disabled = true;

    if (rows) {
        rows.innerHTML =
            '<tr><td colspan="8" class="onu-empty">Carregando inventario local...</td></tr>';
    }

    var localLoaded = false;

    try {
        var localDevices = await jrcProvLoadLocalInventory();
        devices = localDevices;
        page = 1;
        render();
        localLoaded = true;
    } catch (error) {
        console.warn('[PROVISIONAMENTO] Inventario local indisponivel:', error);
    }

    try {
        if (force) {
            await jrcProvLoadDiscovery(true);
        } else if (localLoaded) {
            jrcProvScheduleDiscovery();
        } else {
            await jrcProvLoadDiscovery(false);
        }
    } catch (error) {
        if (!localLoaded && rows) {
            rows.innerHTML =
                '<tr><td colspan="8" class="onu-empty">Falha ao carregar inventario.</td></tr>';
        }
        console.error('[PROVISIONAMENTO] Falha IXC/ACS:', error);
    } finally {
        if (refresh) refresh.disabled = false;
    }
}
$('onu-refresh').addEventListener('click', () => load(true));$('onu-search').addEventListener('click',()=>{page=1;render()});$('onu-clear').addEventListener('click',()=>{$('onu-status').value='ALL';for(const id of ['onu-serial','onu-model','onu-client'])$(id).value='';page=1;render()});$('onu-prev').addEventListener('click',()=>{page--;render()});$('onu-next').addEventListener('click',()=>{page++;render()});document.querySelectorAll('.onu-tabs button').forEach(b=>b.addEventListener('click',()=>{$('onu-status').value=b.dataset.status;page=1;render()}));load();
})();