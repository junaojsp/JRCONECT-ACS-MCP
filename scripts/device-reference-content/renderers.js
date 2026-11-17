/* Reference content, using only fields already supplied by the ACS. */
function jrcHostVisual(client) {
    const vendor = String(client.vendor || client.manufacturer || '').toLowerCase();
    const brands = [
        [/apple/, 'apple'], [/hewlett|\bhp\b/, 'hp'], [/samsung/, 'samsung'],
        [/motorola/, 'motorola'], [/amazon/, 'amazon'], [/google/, 'google'],
        [/xiaomi/, 'xiaomi'], [/huawei/, 'huawei'], [/lenovo/, 'lenovo'],
        [/sony/, 'sony'], [/dell/, 'dell'], [/tp.link/, 'tplink'], [/\blg\b/, 'lg']
    ];
    const match = brands.find(([test]) => test.test(vendor));
    if (match && JRC_AVAILABLE_BRANDS.includes(match[1])) {
        return `<span class="jrc-host-avatar brand-${match[1]}"><img src="/assets/img/device-brands/${match[1]}.svg" alt="${jrcRefValue(client.vendor || client.manufacturer)}" width="24" height="24"></span>`;
    }
    const name = String(client.hostname || '').toLowerCase();
    const icon = /printer|impressora/.test(name) ? 'bi-printer' : /iphone|phone|android|galaxy/.test(name) ? 'bi-phone' : /tv|chromecast/.test(name) ? 'bi-tv' : /ipad|tablet/.test(name) ? 'bi-tablet' : 'bi-pc-display';
    return `<span class="jrc-host-avatar"><i class="bi ${icon}" aria-hidden="true"></i></span>`;
}
function renderConnectedDevicesTab(connectedDevices) {
    const list = Array.isArray(connectedDevices) ? connectedDevices : [];
    const rows = list.map((client, index) => {
        const mac = jrcRefValue(client.mac_address);
        const title = client.hostname || client.vendor || 'Dispositivo sem nome';
        const vendor = client.vendor || client.manufacturer;
        const stateKnown = client.active !== undefined && client.active !== null && client.active !== '';
        const online = jrcRefActive(client);
        return `<tr data-mac="${mac}">
            <td>${index + 1}</td>
            <td><div class="jrc-host-identity">${jrcHostVisual(client)}<div><strong>${jrcRefValue(title)}</strong><small>${vendor ? jrcRefValue(vendor) : 'Fabricante não identificado'}</small></div></div></td>
            <td><strong>${jrcRefValue(client.ip_address)}</strong></td>
            <td><code>${mac}</code></td>
            <td><span class="jrc-host-link">${jrcRefValue(jrcConnectionText(client))}</span></td>
            <td><span class="badge ${stateKnown ? online ? 'online' : 'offline' : 'unknown'}">${stateKnown ? online ? 'Online' : 'Offline' : 'Sem estado'}</span></td>
            <td class="hotspot-user" data-mac="${mac}"><span class="text-muted">—</span></td>
            <td class="hotspot-traffic" data-mac="${mac}"><span class="text-muted">—</span></td>
        </tr>`;
    }).join('');
    return `<header class="jrc-content-heading"><span class="jrc-heading-icon"><i class="bi bi-diagram-3"></i></span><div><h3>Dispositivos conectados</h3><small>${list.length} identificados pelo CPE</small></div></header>
        <div class="table-responsive"><table id="connected-devices-table" class="table jrc-host-cards"><thead><tr><th>#</th><th>Dispositivo</th><th>IP</th><th>MAC</th><th>Conexão</th><th>Status</th><th>Hotspot</th><th>Tráfego</th></tr></thead><tbody>${rows}</tbody></table></div>
        ${list.length ? '' : '<p class="jrc-empty">Nenhum aparelho informado pelo equipamento.</p>'}
        <details class="jrc-hotspot-details"><summary>Monitoramento Hotspot</summary><small id="hotspot-status">Aguardando leitura da integração.</small></details>`;
}
function jrcRefLan(device) {
    const ports = Array.isArray(device.lan_ports) ? device.lan_ports : [];
    const hosts = Array.isArray(device.connected_devices) ? device.connected_devices : null;
    const dhcp = device.dhcp_server;
    const connected = ports.filter(p => /^(up|connected)$/i.test(String(p.status))).length;
    const summary = jrcRefKpis([
        ['Conexões LAN', dhcp ? 1 : '—', 'Configuração disponível', 'bi-diagram-3'],
        ['Portas conectadas', ports.length ? connected : '—', ports.length ? `de ${ports.length} coletadas` : 'Sem leitura', 'bi-ethernet'],
        ['Servidor DHCP', !dhcp ? '—' : [true, 1, 'true', '1'].includes(dhcp.enabled) ? 'Habilitado' : 'Desabilitado', 'Estado informado pelo CPE', 'bi-router'],
        ['Hosts identificados', hosts ? hosts.length : '—', 'Inventário do equipamento', 'bi-people']
    ]);
    const field = (icon, label, value) => `<div class="jrc-lan-field"><small><i class="bi ${icon}"></i> ${label}</small><strong>${jrcRefValue(value)}</strong></div>`;
    const edit = dhcp ? `<button class="btn btn-sm btn-outline-secondary" onclick='openEditDHCPModal(${jrcEscapeClientHtml(JSON.stringify(dhcp))})'><i class="bi bi-gear"></i> Editar</button>` : '';
    const config = dhcp ? `<section class="jrc-lan-configuration"><header class="jrc-content-heading"><span class="jrc-heading-icon"><i class="bi bi-diagram-3"></i></span><div><h3>Configuração LAN</h3><small>Rede local</small></div>${edit}</header><div class="jrc-lan-fields">
        ${field('bi-router', 'Gateway da rede local', dhcp.gateway)}${field('bi-diagram-2', 'Máscara', dhcp.subnet_mask)}${field('bi-hdd-network', 'Servidores DNS', dhcp.dns_servers)}${field('bi-lightning', 'DHCP · Início', dhcp.min_address)}${field('bi-lightning', 'DHCP · Fim', dhcp.max_address)}
        </div></section>` : '<p class="jrc-empty">Configuração LAN não informada pelo CPE.</p>';
    const drawings = ports.map(p => {
        const up = /^(up|connected)$/i.test(String(p.status));
        return `<div class="jrc-port-item"><div class="jrc-port-socket ${up ? 'is-up' : ''}"><i class="bi bi-ethernet"></i><span class="jrc-port-leds"><b></b><b></b></span></div><strong>${jrcRefValue(p.name)}</strong></div>`;
    }).join('');
    const rows = ports.map(p => {
        const up = /^(up|connected)$/i.test(String(p.status));
        return `<tr><td><i class="bi bi-ethernet"></i> ${jrcRefValue(p.name)}</td><td><span class="badge ${up ? 'online' : 'offline'}">${jrcRefValue(p.status)}</span></td><td>${jrcRefValue(p.max_bit_rate)}</td><td>${jrcRefValue(p.duplex_mode ?? p.duplex)}</td><td>${jrcRefValue(p.mac_address)}</td></tr>`;
    }).join('');
    return summary + config + `<section class="jrc-lan-ethernet"><header class="jrc-content-heading"><span class="jrc-heading-icon"><i class="bi bi-ethernet"></i></span><div><h3>Painel de portas Ethernet</h3><small>${ports.length ? `${connected} de ${ports.length} conectadas` : 'Sem leitura das portas'}</small></div></header><div class="jrc-port-drawings">${drawings || '<span>Dados ainda não coletados.</span>'}</div><h4 class="jrc-table-caption">Detalhes das configurações</h4><div class="table-responsive"><table class="table jrc-port-table"><thead><tr><th>Porta</th><th>Status</th><th>Velocidade (Mbps)</th><th>Duplex</th><th>MAC</th></tr></thead><tbody>${rows}</tbody></table></div></section>`;
}
function renderWANDetailsTab(wanDetails) {
    const list = Array.isArray(wanDetails) ? wanDetails : [];
    const field = (label, value, full = false) => `<div class="jrc-wan-field ${full ? 'is-wide' : ''}"><small>${label}</small><strong>${jrcRefValue(value)}</strong></div>`;
    const interfaces = list.map((wan, index) => {
        const online = /^(connected|up)$/i.test(String(wan.status));
        const editData = jrcEscapeClientHtml(JSON.stringify(wan));
        const vlan = String(wan.name || '').match(/VID[_-]?(\d+)/i)?.[1];
        return `<section class="jrc-wan-interface"><header class="jrc-wan-identity"><div><h3><b class="jrc-state-dot ${online ? 'online' : ''}"></b>${jrcRefValue(wan.name || `WAN ${index + 1}`)}</h3><div class="jrc-wan-meta"><span>${jrcRefValue(wan.type)}</span><span>${jrcRefValue(wan.connection_type)}</span><span>${jrcRefValue(wan.username)}</span><span>${wan.uptime ? jrcRefValue(formatUptime(wan.uptime)) : '—'}</span></div></div><div class="jrc-wan-buttons"><button type="button" class="btn btn-sm btn-outline-secondary" onclick='openEditWANModal(${editData})'><i class="bi bi-gear"></i> Editar</button><button type="button" class="btn btn-sm btn-danger" onclick='openDeleteWANModal(${editData})' aria-label="Excluir WAN"><i class="bi bi-trash"></i></button></div></header>
        <div class="jrc-wan-fields">${field('Endereço IP', wan.external_ip)}${field('Gateway padrão', wan.gateway)}${wan.username ? field('Usuário PPPoE', wan.username, true) : ''}${field('DNS IPv4', wan.dns_servers)}${field('Endereço IPv6', wan.ipv6_address ?? wan.external_ipv6 ?? wan.ipv6)}</div>
        <details class="jrc-wan-advanced"><summary>Detalhes técnicos</summary><div class="jrc-wan-fields">${field('Status', wan.status)}${field('MAC', wan.mac_address)}${field('VLAN', wan.vlan_id ?? vlan)}${field('Binding', wan.binding)}${field('MRU', wan.mru_size)}${field('Último erro', wan.last_error)}</div></details></section>`;
    }).join('');
    return `<div class="jrc-wan-reference"><header class="jrc-content-heading"><span class="jrc-heading-icon"><i class="bi bi-globe"></i></span><div><h3>Conexões WAN</h3><small>${list.filter(w => /^(connected|up)$/i.test(String(w.status))).length} de ${list.length} conexões ativas</small></div><button type="button" class="btn btn-sm btn-outline-secondary" onclick="openAddWANModal()"><i class="bi bi-plus"></i> Adicionar WAN</button></header>${interfaces || '<p class="jrc-empty">Nenhuma interface informada pelo equipamento.</p>'}<details class="jrc-wan-telemetry"><summary><i class="bi bi-activity"></i> Tráfego e histórico de consumo</summary>${renderWanUsageCards(list)}</details></div>`;
}
