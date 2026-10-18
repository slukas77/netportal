/* Statische Demo des Network Portals: Seiten sind echte Ausgaben des Portals mit
   Test-Einträgen. Was den Server braucht (Speichern, Ping, SSH …), meldet hier nur einen Hinweis. */
(() => {
  'use strict';
  const en = (document.documentElement.lang || document.querySelector('.lang-switch b')?.textContent || '').toLowerCase().startsWith('en');
  const MSG = en ? 'Demo: this needs the portal server – nothing is saved or executed here.' : 'Demo: Dafür braucht es den Portal-Server – hier wird nichts gespeichert oder ausgeführt.';
  const realFetch = window.fetch.bind(window);
  window.fetch = (url, opts) => {
    if (String(url).includes('?a=')) {
      return Promise.resolve(new Response(JSON.stringify({ error: MSG, demo: true }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    }
    return realFetch(url, opts);
  };
  let tip = null, timer = null;
  const say = () => {
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'demo-tip';
      tip.setAttribute('role', 'status');
      document.body.appendChild(tip);
    }
    tip.textContent = MSG;
    tip.classList.add('show');
    clearTimeout(timer);
    timer = setTimeout(() => tip.classList.remove('show'), 3200);
  };
  document.addEventListener('submit', (e) => { e.preventDefault(); say(); }, true);
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href^="#demo"]');
    if (a) { e.preventDefault(); say(); }
  }, true);
  const css = document.createElement('style');
  css.textContent = '.demo-badge{display:inline-flex;align-items:center;height:32px;padding:0 10px;border-radius:6px;font:650 11.5px/1 system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase;color:#06302b;background:#2fd0bd;white-space:nowrap}'
    + '.demo-tip{position:fixed;left:50%;bottom:24px;transform:translate(-50%,12px);z-index:70;max-width:min(92vw,520px);background:#102a43;color:#f2f6fb;border-left:4px solid #2fd0bd;border-radius:8px;padding:11px 16px;font:13.5px/1.45 system-ui,sans-serif;box-shadow:0 12px 32px rgba(16,32,52,.3);opacity:0;transition:opacity .2s,transform .2s;pointer-events:none}'
    + '.demo-tip.show{opacity:1;transform:translate(-50%,0)}'
    + '.login-demo{position:fixed;top:12px;right:12px}'
    + '@media(max-width:900px){.demo-badge{padding:0 8px;font-size:10.5px}.demo-badge .long{display:none}}';
  document.head.appendChild(css);
  document.addEventListener('DOMContentLoaded', () => {
    const badge = document.createElement('span');
    badge.className = 'demo-badge';
    badge.innerHTML = 'Demo<span class="long">&nbsp;· ' + (en ? 'view only' : 'nur Ansicht') + '</span>';
    const tools = document.querySelector('.subbar-tools');
    if (tools) tools.insertBefore(badge, tools.firstChild);
    else { badge.classList.add('login-demo'); document.body.appendChild(badge); }
  });
})();
