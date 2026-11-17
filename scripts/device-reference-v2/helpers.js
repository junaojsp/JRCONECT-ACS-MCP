/* Presentation helpers for the installed ACS device page. */
function jrcRefValue(value) {
    return jrcEscapeClientHtml(value === null || value === undefined || value === '' || value === 'N/A' ? '—' : value);
}
function jrcRefKpis(items) {
    return '<section class="jrc-ref-summary" aria-label="Resumo">' + items.map(([label, value, note, icon]) =>
        `<article><div>${jrcRefValue(label)}<i class="bi ${icon}"></i></div><strong>${jrcRefValue(value)}</strong><small>${jrcRefValue(note)}</small></article>`
    ).join('') + '</section>';
}
function jrcRefActive(client) {
    return [true, 1, '1', 'true', 'True', 'TRUE'].includes(client?.active);
}
function jrcRefLan(device) {
    const ports = Array.isArray(device.lan_ports) ? device.lan_ports : [];
    const up = ports.filter(p => /^(up|connected)$/i.test(String(p.status))).length;
    const hosts = Array.isArray(device.connected_devices) ? device.connected_devices : null;
    const dhcp = device.dhcp_server;
    const summary = jrcRefKpis([
        ['Configuração LAN', dhcp ? 1 : '—', 'Configuração informada pelo CPE', 'bi-diagram-3'],
        ['Portas conectadas', ports.length ? up : '—', ports.length ? `${ports.length} portas coletadas` : 'Sem leitura', 'bi-ethernet'],
        ['Servidor DHCP', !dhcp ? '—' : [true, 'true', 1, '1'].includes(dhcp.enabled) ? 'Ativo' : 'Inativo', 'Distribuição de endereços', 'bi-router'],
        ['Hosts online', hosts ? hosts.filter(jrcRefActive).length : '—', 'Estado informado pelo CPE', 'bi-people']
    ]);
    const rows = ports.map(p => `<tr><td>${jrcRefValue(p.name)}</td><td>${jrcRefValue(p.status)}</td><td>${jrcRefValue(p.max_bit_rate)}</td><td>${jrcRefValue(p.duplex_mode ?? p.duplex)}</td><td>${jrcRefValue(p.mac_address)}</td></tr>`).join('');
    return summary + '<section class="jrc-ref-lan-config">' + renderDHCPServerTab(dhcp) + '</section>' +
        '<section class="jrc-ref-ports"><h5><i class="bi bi-ethernet"></i> Painel de portas Ethernet</h5>' + renderPhysicalPorts(ports) +
        (ports.length ? '<div class="table-responsive"><table class="table"><thead><tr><th>Porta</th><th>Status</th><th>Velocidade (Mbps)</th><th>Duplex</th><th>MAC</th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '') + '</section>';
}
function jrcRefHostsSummary(hosts) {
    const list = Array.isArray(hosts) ? hosts : null;
    return jrcRefKpis([
        ['Dispositivos conectados', list ? list.filter(jrcRefActive).length : '—', 'Online no inventário do CPE', 'bi-people'],
        ['Estações Wi-Fi', list ? list.filter(c => jrcRefActive(c) && /^wifi$/i.test(c.interface_type)).length : '—', 'Conexão sem fio', 'bi-wifi'],
        ['Ethernet', list ? list.filter(c => jrcRefActive(c) && /^ethernet$/i.test(c.interface_type)).length : '—', 'Conexão por cabo', 'bi-ethernet'],
        ['Hosts identificados', list ? list.length : '—', 'Total do inventário', 'bi-hdd-network']
    ]);
}
