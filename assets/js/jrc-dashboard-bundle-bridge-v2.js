/* JR ACS Dashboard bundle bridge v1.
 * Compatibility shim: only the two verified production GET requests.
 * Never touches ONU commands, WAN, credentials, device-detail routes, or IXC APIs.
 * A failed or stale bundle falls back to the original endpoints.
 */
(() => {
  'use strict';
  if (!/\/dashboard\.php$/.test(location.pathname)) return;
  if (window.__jrcOverviewBundleBridge) return;
  window.__jrcOverviewBundleBridge = true;

  const originalFetch = window.fetch.bind(window);
  const paths = new Map([
    ['/api/uplink-stats.php', 'uplink'],
    ['/api/recent-devices.php', 'recent']
  ]);
  let inFlight = null;
  let cached = null;
  let cachedUntil = 0;
  let retryAfter = 0;
  const now = () => Date.now();

  function target(input, init) {
    const method = String(init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
    if (method !== 'GET') return null;
    let url;
    try {
      url = new URL(input instanceof Request ? input.url : String(input), location.href);
    } catch (_) {
      return null;
    }
    if (url.origin !== location.origin || url.search || url.hash) return null;
    return paths.get(url.pathname) ?? null;
  }

  async function bundle() {
    if (retryAfter > now()) throw new Error('bundle temporarily disabled');
    if (cached && cachedUntil > now()) return cached;
    if (inFlight) return inFlight;
    inFlight = (async () => {
      const response = await originalFetch('/api/jrc-dashboard-bundle-v215.php', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {Accept: 'application/json'}
      });
      if (!response.ok) throw new Error('bundle http');
      const data = await response.json();
      if (!data?.success || !data.snapshot || data.cache_stale ||
          !Number.isInteger(Number(data.cache_age_seconds)) ||
          Number(data.cache_age_seconds) > 900 ||
          !data.stats || !data.uplink || !Array.isArray(data.recent)) {
        throw new Error('bundle invalid or stale');
      }
      const stats = data.stats, uplink = data.uplink;
      if (![stats.total, stats.online, stats.offline, uplink.total,
            uplink.excellent, uplink.good, uplink.fair, uplink.poor, uplink.no_signal]
          .every(x => Number.isSafeInteger(Number(x)) && Number(x) >= 0)) {
        throw new Error('bundle counts invalid');
      }
      if (Number(stats.total) !== Number(uplink.total) ||
          Number(stats.online) + Number(stats.offline) !== Number(stats.total) ||
          ['excellent','good','fair','poor','no_signal'].reduce((n,k) => n + Number(uplink[k]),0) !== Number(uplink.total)) {
        throw new Error('bundle populations disagree');
      }
      cached = data;
      cachedUntil = now() + 8000;
      return data;
    })();
    try { return await inFlight; }
    catch (error) { retryAfter = now() + 60000; throw error; }
    finally { inFlight = null; }
  }

  window.fetch = async function(input, init) {
    const kind = target(input, init);
    if (!kind) return originalFetch(input, init);
    try {
      const data = await bundle();
      const payload = kind === 'stats'
        ? {success:true, stats:data.stats, source:data.source,
           snapshot:data.snapshot, cached_at:data.cached_at, cache_age_seconds:data.cache_age_seconds}
        : kind === 'uplink'
          ? {success:true, data:data.uplink, source:data.source,
             snapshot:data.snapshot, cached_at:data.cached_at, cache_age_seconds:data.cache_age_seconds}
          : {success:true, devices:data.recent, source:data.source,
             snapshot:data.snapshot, cached_at:data.cached_at, cache_age_seconds:data.cache_age_seconds};
      return new Response(JSON.stringify(payload), {
        status:200,
        headers:{'Content-Type':'application/json; charset=utf-8', 'Cache-Control':'no-store'}
      });
    } catch (_) {
      // Graceful fallback: do not print customer data, auth, or response bodies.
      return originalFetch(input, init);
    }
  };
})();
