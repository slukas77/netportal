/* Network Portal – Frontend (ohne Abhängigkeiten). Alle Seiten funktionieren
   grundsätzlich ohne JavaScript; hier kommen Ping, Dienst-Prüfung, iperf3, SSH,
   MAC-Erkennung, Icon-Auswahl, Paket-Installation, Tester, Tabellenfilter,
   Mehrfach-Formular und YubiKey (WebAuthn) dazu.
   Texte: T('Deutscher Text', {platzhalter}) – Übersetzung aus <script id="i18n">. */
(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const csrf = () => (document.querySelector('meta[name="csrf"]') || {}).content || '';
  let STR = {};
  try { STR = JSON.parse(document.getElementById('i18n')?.textContent || '{}'); } catch (e) { STR = {}; }
  const T = (s, p = {}) => Object.entries(p).reduce((acc, [k, v]) => acc.replaceAll('{' + k + '}', String(v)), STR[s] ?? s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function post(url, data = {}, opts = {}) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: !!opts.keepalive,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF': csrf() },
      body: JSON.stringify(data),
    });
    let json = {};
    try { json = await res.json(); } catch (e) { json = { error: T('Unerwartete Antwort ({s})', { s: res.status }) }; }
    if (!res.ok && !json.error) json.error = T('Fehler {s}', { s: res.status });
    if (res.status === 401 && json.restart) location.href = './';
    return json;
  }

  let toastTimer = null;
  function toast(msg, kind = '') {
    const t = $('#toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show ' + kind;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.className = 'toast'; }, 3800);
  }

  function busy(el, on) {
    if (!el) return;
    el.classList.toggle('busy', on);
    if (el.tagName === 'BUTTON') el.disabled = on;
  }

  /* ---------- Navigation (Kopfleiste, auf schmalen Bildschirmen ausklappbar) ---------- */
  // Debug-Modus: JavaScript-Fehler an das Portal melden
  const dbgUrl = document.querySelector('meta[name="np-debug"]')?.content;
  if (dbgUrl) {
    let sent = 0;
    const report = (msg, src, line, col) => {
      if (++sent > 20) return;
      fetch(dbgUrl, { method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF': csrf() },
        body: JSON.stringify({ msg: String(msg).slice(0, 500), src: String(src || '').slice(0, 300), line: line || 0, col: col || 0, page: location.search.slice(0, 300) }) }).catch(() => {});
    };
    window.addEventListener('error', (e) => report(e.message, e.filename, e.lineno, e.colno));
    window.addEventListener('unhandledrejection', (e) => report('Promise: ' + (e.reason?.message || e.reason), '', 0, 0));
  }

  const app = $('#app');
  const setNav = (open) => {
    app.classList.toggle('nav-open', open);
    $('#sidebarToggle')?.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  $('#sidebarToggle')?.addEventListener('click', () => setNav(!app.classList.contains('nav-open')));
  $('#sidebarScrim')?.addEventListener('click', () => setNav(false));
  $('#mobileSearch')?.addEventListener('click', () => { setNav(true); setTimeout(() => $('#searchInput')?.focus(), 260); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && app.classList.contains('nav-open')) setNav(false); });

  /* ---------- Menügruppen der Kopfleiste: nur eine offen, Klick daneben oder Esc schließt ---------- */
  const menus = () => document.querySelectorAll('details.menu[open]');
  document.addEventListener('toggle', (e) => {
    const d = e.target;
    if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('menu') || !d.open) return;
    menus().forEach((m) => { if (m !== d) m.open = false; });
  }, true);
  document.addEventListener('click', (e) => {
    if (!e.target.closest('details.menu')) menus().forEach((m) => { m.open = false; });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = menus();
    if (open.length) { open.forEach((m) => { m.open = false; m.querySelector('summary')?.focus(); }); }
  });

  /* ---------- Klickbare Zeilen, Bestätigungen, Kopieren ---------- */
  document.addEventListener('click', (e) => {
    const row = e.target.closest('tr[data-href]');
    if (row && !e.target.closest('a, button, input, select, form, label')) {
      if (e.ctrlKey || e.metaKey) window.open(row.dataset.href, '_blank');
      else location.href = row.dataset.href;
    }
    const cp = e.target.closest('[data-copy]');
    if (cp) {
      e.preventDefault();
      navigator.clipboard?.writeText(cp.dataset.copy).then(() => toast(T('Kopiert: {v}', { v: cp.dataset.copy }), 'ok'), () => toast(T('Kopieren nicht möglich'), 'bad'));
    }
  });
  document.addEventListener('submit', (e) => {
    const f = e.target;
    const msg = f.dataset.confirm || (e.submitter && e.submitter.dataset.confirm);
    if (msg && !confirm(msg)) e.preventDefault();
  });

  /* ---------- Tabelle: Filtern und Sortieren ---------- */
  const table = $('#hostTable');
  function applyFilter() {
    if (!table) return;
    const q = ($('#tableFilter')?.value || '').trim().toLowerCase();
    const kind = $('#kindFilter')?.value || '';
    const state = $('#stateFilter')?.value || '';
    let n = 0;
    $$('tbody tr', table).forEach((tr) => {
      const ok = (!q || q.split(/\s+/).every((w) => tr.dataset.search.includes(w)))
        && (!kind || tr.dataset.kind === kind) && (!state || tr.dataset.state === state);
      tr.hidden = !ok;
      if (ok) n++;
    });
    const c = $('#filterCount');
    if (c) c.textContent = T('{n} Einträge', { n });
  }
  ['#tableFilter', '#kindFilter', '#stateFilter'].forEach((s) => $(s)?.addEventListener('input', applyFilter));
  $$('th[data-sort]').forEach((th) => {
    th.addEventListener('click', () => {
      const tbl = th.closest('table');
      const idx = Array.from(th.parentNode.children).indexOf(th);
      const dir = th.classList.contains('sorted-asc') ? -1 : 1;
      $$('th', tbl).forEach((x) => x.classList.remove('sorted-asc', 'sorted-desc'));
      th.classList.add(dir === 1 ? 'sorted-asc' : 'sorted-desc');
      const type = th.dataset.sort;
      const rows = $$('tbody tr', tbl);
      const val = (tr) => {
        const td = tr.children[idx];
        const v = td?.dataset.v ?? td?.textContent.trim().toLowerCase() ?? '';
        return type === 'num' ? parseFloat(v) || 0 : v;
      };
      rows.sort((a, b) => (val(a) > val(b) ? 1 : val(a) < val(b) ? -1 : 0) * dir);
      rows.forEach((r) => r.parentNode.appendChild(r));
    });
  });

  /* ---------- Ping ---------- */
  function setRowState(tr, r) {
    if (!tr) return;
    const dot = $('.dot', tr);
    if (dot) {
      dot.className = 'dot ' + (r.ok ? 'up' : 'down');
      dot.title = T(r.ok ? 'online' : 'offline') + ' · ' + T('gerade eben') + (r.avg != null ? ' · ' + r.avg + ' ms' : '');
    }
    tr.dataset.state = r.ok ? 'up' : 'down';
    const cell = $('.ping-cell', tr);
    if (cell) {
      cell.dataset.v = r.ok && r.avg != null ? r.avg : 99999;
      cell.innerHTML = (r.ok ? esc((r.avg ?? '?') + ' ms') : '<span class="txt-bad">' + esc(T('keine')) + '</span>') + '<div class="cell-sub">' + esc(T('gerade eben')) + '</div>';
    }
    tr.classList.remove('flash-row');
    void tr.offsetWidth;
    tr.classList.add('flash-row');
  }

  $$('.row-ping').forEach((b) => b.addEventListener('click', async () => {
    const tr = b.closest('tr');
    busy(b, true);
    $('.dot', tr)?.classList.add('busy');
    const r = await post('./?a=ping_host', { n: table.dataset.net, id: b.dataset.id });
    busy(b, false);
    $('.dot', tr)?.classList.remove('busy');
    if (r.error) return toast(r.error, 'bad');
    setRowState(tr, r);
    toast((r.ok ? T('✓ {t} antwortet · {ms} ms', { t: r.target, ms: r.avg }) : T('✗ {t} antwortet nicht', { t: r.target })) + (r.method === 'tcp' ? ' ' + T('(TCP-Ping)') : ''), r.ok ? 'ok' : 'bad');
  }));

  $('#pingAll')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    busy(b, true);
    $$('#hostTable tbody tr').forEach((tr) => { if (tr.dataset.state !== 'nocheck') $('.dot', tr)?.classList.add('busy'); });
    const r = await post(b.dataset.url, { n: b.dataset.net });
    busy(b, false);
    $$('#hostTable .dot.busy').forEach((d) => d.classList.remove('busy'));
    if (r.error) return toast(r.error, 'bad');
    Object.entries(r.results || {}).forEach(([id, res]) => setRowState($('#hostTable tr[data-id="' + id + '"]'), res));
    toast(T('{a} von {b} Hosts online', { a: r.up, b: r.total }), r.up === r.total ? 'ok' : 'bad');
    applyFilter();
  });

  $('#hostPing')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    const stage = b.closest('.stage');
    const out = $('#pingOut');
    busy(b, true);
    if (out) out.textContent = T('Ping läuft …');
    const r = await post(b.dataset.url, { n: stage.dataset.net, id: stage.dataset.host });
    busy(b, false);
    if (r.error) { if (out) out.textContent = r.error; return toast(r.error, 'bad'); }
    if (out) out.innerHTML = '<span class="' + (r.ok ? 'ok' : 'bad') + '">' + esc(T(r.ok ? '✓ erreichbar' : '✗ nicht erreichbar')) + (r.method === 'tcp' ? ' ' + esc(T('(TCP-Ping)')) : '') + '</span>\n' + esc(r.output);
    const st = $('#hostState');
    if (st) { st.className = 'badge state-' + (r.ok ? 'up' : 'down'); st.textContent = r.ok ? T('online') + ' · ' + r.avg + ' ms' : T('offline'); }
  });

  // Schnell-Ping unten
  const pop = $('#pingPop');
  $('#pingPopClose')?.addEventListener('click', () => { pop.hidden = true; });
  $('#quickPing')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const inp = $('#quickPingInput');
    const target = inp.value.trim();
    if (!target) return inp.focus();
    const btn = e.target.querySelector('button');
    busy(btn, true);
    pop.hidden = false;
    $('#pingPopTitle').textContent = 'Ping ' + target;
    $('#pingPopOut').textContent = T('läuft …');
    const r = await post(e.target.action, { target });
    busy(btn, false);
    if (r.error) { $('#pingPopOut').textContent = r.error; return; }
    $('#pingPopOut').innerHTML = '<span class="' + (r.ok ? 'ok' : 'bad') + '">' + esc(r.ok ? T('✓ erreichbar') + ' · ' + r.avg + ' ms' : T('✗ nicht erreichbar')) + '</span>'
      + (r.host ? ' · <a href="' + esc(r.host) + '">' + esc(T('zum Host')) + '</a>' : '') + '\n' + esc(r.output);
  });

  /* ---------- Dienste ---------- */
  const hostStage = $('.stage[data-host]');
  const hostData = () => ({ n: hostStage.dataset.net, id: hostStage.dataset.host });

  $('#svcCheck')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    busy(b, true);
    const dots = $$('#svcTable tr[data-check="1"] .dot');
    dots.forEach((d) => d.classList.add('busy'));
    const r = await post(b.dataset.url, hostData());
    busy(b, false);
    dots.forEach((d) => d.classList.remove('busy'));
    if (r.error) return toast(r.error, 'bad');
    let bad = 0;
    Object.entries(r.results || {}).forEach(([id, s]) => {
      const tr = $('#svcTable tr[data-svc="' + id + '"]');
      if (!tr) return;
      const cls = s.cls || (s.ok === null ? 'unknown' : s.ok ? 'up' : 'down');
      const dot = $('.dot', tr);
      dot.className = 'dot ' + cls;
      dot.title = s.state;
      const chip = $('.svc-chip', tr);
      if (chip) chip.className = 'svc-chip ' + cls;
      $('.svc-state', tr).innerHTML = esc(s.state + (s.ms != null ? ' · ' + s.ms + ' ms' : '')) + (s.info ? '<div class="cell-sub">' + esc(s.info) + '</div>' : '');
      $('.svc-ts', tr).textContent = s.ago || T('gerade eben');
      if (s.ok === false) bad++;
    });
    toast(bad ? T('{n} Dienst(e) nicht erreichbar', { n: bad }) : T('Alle prüfbaren Dienste erreichbar'), bad ? 'bad' : 'ok');
  });

  $('#svcScan')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    const box = $('#scanResult');
    busy(b, true);
    box.innerHTML = '<div class="scan-box">' + esc(T('Prüfe Ports …')) + '</div>';
    const r = await post(b.dataset.url, hostData());
    busy(b, false);
    if (r.error) { box.innerHTML = ''; return toast(r.error, 'bad'); }
    const items = (r.open || []).map((p) => '<label class="scan-item"><input type="checkbox" value="' + p.port + '"' + (p.known ? ' disabled' : ' checked') + '><span><b>' + esc(p.name) + '</b> · tcp/' + p.port
      + (p.known ? ' <span class="badge dim">' + esc(T('bereits erfasst')) + '</span>' : '') + '<small>' + esc(p.banner || (p.ms + ' ms')) + '</small></span></label>').join('');
    box.innerHTML = '<div class="scan-box"><strong>' + esc(T('{n} offene Ports', { n: (r.open || []).length })) + '</strong> · <span class="mono">' + esc(r.ip) + '</span>'
      + ' <span class="dim small">· ' + r.checked + ' ' + esc(T('geprüft')) + ', ' + r.closed + ' ' + esc(T('geschlossen')) + ', ' + r.filtered + ' ' + esc(T('gefiltert')) + '</span>'
      + (items ? '<div class="scan-list">' + items + '</div>' : '<p class="dim">' + esc(T('Keine offenen Ports gefunden.')) + '</p>')
      + (items && b.dataset.adopt ? '<button type="button" class="pill-btn primary small" id="scanAdopt">' + esc(T('Ausgewählte als Dienste übernehmen')) + '</button> ' : '')
      + '<button type="button" class="pill-btn ghost small" id="scanClose">' + esc(T('Schließen')) + '</button></div>';
    $('#scanClose').onclick = () => { box.innerHTML = ''; };
    const ad = $('#scanAdopt');
    if (ad) ad.onclick = async () => {
      const ports = $$('.scan-item input:checked:not(:disabled)', box).map((i) => i.value);
      if (!ports.length) return toast(T('Nichts ausgewählt'), 'bad');
      busy(ad, true);
      const res = await post(b.dataset.adopt, { ...hostData(), ports });
      busy(ad, false);
      if (res.error) return toast(res.error, 'bad');
      toast(T('{n} Dienst(e) übernommen', { n: res.added }), 'ok');
      setTimeout(() => location.reload(), 600);
    };
  });

  $$('.svc-edit').forEach((b) => b.addEventListener('click', () => {
    const d = JSON.parse(b.dataset.svc);
    const f = $('#svcForm');
    if (!f) return;
    f.sid.value = d.id; f.name.value = d.name || ''; f.proto.value = d.proto || 'tcp';
    f.port.value = d.port || ''; f.url.value = d.url || ''; f.description.value = d.description || '';
    if (f.status_check) f.status_check.checked = d.status_check === null || d.status_check === undefined || Number(d.status_check) === 1;
    const box = $('#svcFormBox');
    box.open = true;
    $('summary span', box).textContent = T('Dienst bearbeiten');
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    f.name.focus();
  }));
  $('#svcReset')?.addEventListener('click', () => {
    const f = $('#svcForm');
    f.sid.value = '';
    if (f.status_check) f.status_check.checked = true;
    $('#svcFormBox summary span').textContent = T('Dienst hinzufügen');
  });

  /* ---------- SSH ---------- */
  const sshCard = $('.ssh-card');
  $('#sshProbe')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    const out = $('#sshProbeOut');
    busy(b, true);
    out.innerHTML = '<p class="dim">' + esc(T('Verbinde …')) + '</p>';
    const r = await post(sshCard.dataset.probe, hostData());
    busy(b, false);
    if (r.error) { out.innerHTML = ''; return toast(r.error, 'bad'); }
    let html = '<div class="probe-grid"><div class="fp-row"><span class="badge ' + (r.state === 'open' ? 'ok-soft' : 'bad') + '">' + esc(T('Port {p}: {s}', { p: r.port, s: r.state })) + '</span>'
      + (r.ms != null ? '<span class="dim">' + r.ms + ' ms</span>' : '') + (r.banner ? '<span class="mono">' + esc(r.banner) + '</span>' : '') + '</div>';
    (r.keys || []).forEach((k) => {
      const known = r.known && r.known[k.type];
      const st = known ? (known === k.fp ? '<span class="badge ok-soft">' + esc(T('bekannt')) + '</span>' : '<span class="badge bad">' + esc(T('ABWEICHEND von known_hosts')) + '</span>') : '<span class="badge dim">' + esc(T('noch nicht bekannt')) + '</span>';
      html += '<div class="fp-row"><span class="mono dim">' + esc(k.type) + '</span><span class="mono">' + esc(k.fp) + '</span>' + st + '</div>';
    });
    if (r.state === 'open' && !(r.keys || []).length) html += '<p class="hint">' + esc(T('Host-Keys konnten nicht gelesen werden (ssh-keyscan fehlt?).')) + '</p>';
    out.innerHTML = html + '</div>';
  });

  $('#sshExec')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const out = $('#sshOut');
    const btn = f.querySelector('button[type=submit]');
    busy(btn, true);
    out.hidden = false;
    out.textContent = '$ ' + f.command.value + '\n…';
    const r = await post(sshCard.dataset.exec, { ...hostData(), command: f.command.value, ssh_user: f.ssh_user.value });
    busy(btn, false);
    if (r.error) { out.innerHTML = '<span class="bad">' + esc(r.error) + '</span>'; return; }
    out.innerHTML = '<span class="dim">' + esc(r.target) + ' $ ' + esc(f.command.value) + '</span>\n' + esc(r.stdout)
      + (r.stderr ? '\n<span class="bad">' + esc(r.stderr) + '</span>' : '')
      + '\n<span class="' + (r.exit === 0 ? 'ok' : 'bad') + '">[Exit ' + r.exit + ' · ' + r.ms + ' ms]</span>';
  });


  /* ---------- Neue Netzwerke (mehrere) ---------- */
  const multi = $('#multiList');
  if (multi) {
    let next = $$('.multi-row', multi).length;
    const addRow = (vals = {}) => {
      const html = $('#multiTpl').innerHTML.replaceAll('__I__', String(next++));
      const wrap = document.createElement('div');
      wrap.innerHTML = html;
      const row = wrap.firstElementChild;
      Object.entries(vals).forEach(([k, v]) => { const el = row.querySelector('[name$="[' + k + ']"]'); if (el) el.value = v; });
      multi.appendChild(row);
      return row;
    };
    $('#multiAdd').addEventListener('click', () => addRow().querySelector('input')?.focus());
    multi.addEventListener('click', (e) => {
      const del = e.target.closest('.multi-del');
      if (!del) return;
      if ($$('.multi-row', multi).length <= 1) return toast(T('Mindestens eine Zeile'), 'bad');
      del.closest('.multi-row').remove();
    });
    const dlg = $('#seriesDlg');
    $('#multiSeries').addEventListener('click', () => dlg.showModal());
    dlg.addEventListener('close', () => {
      if (dlg.returnValue !== 'ok') return;
      const g = (n) => dlg.querySelector('[name="' + n + '"]').value.trim();
      const start = parseInt(g('s_start'), 10), count = Math.min(64, parseInt(g('s_count'), 10) || 0), step = parseInt(g('s_step'), 10) || 1;
      if (!(start >= 1)) return toast(T('Erste VLAN-ID fehlt'), 'bad');
      // leere Startzeile ersetzen
      $$('.multi-row', multi).forEach((r) => { if (!r.querySelector('[name$="[name]"]').value) r.remove(); });
      for (let i = 0; i < count; i++) {
        const id = start + i * step;
        if (id > 4094) break;
        const fill = (s) => s.replaceAll('{id}', id).replaceAll('{n}', i + 1);
        addRow({ name: fill(g('s_prefix')), kind: 'VLAN', vlan_id: id, cidr: g('s_cidr') ? fill(g('s_cidr')) : '', gateway: g('s_gw') && g('s_cidr') ? fill(g('s_gw')) : '' });
      }
      toast(T('{n} Zeilen erzeugt – prüfen und „Alle anlegen“', { n: count }), 'ok');
    });
  }

  /* ---------- Formular → Objekt ---------- */
  const formData = (f) => {
    const o = {};
    new FormData(f).forEach((v, k) => { if (typeof v === 'string') o[k] = v; });
    return o;
  };
  const consoleOut = (el, lines) => {
    el.hidden = false;
    el.innerHTML = lines.map((l) => '<span class="' + (String(l).startsWith('✗') ? 'bad' : String(l).startsWith('✓') ? 'ok' : '') + '">' + esc(l) + '</span>').join('\n');
  };

  /* ---------- Dienste eines ganzen Netzwerks prüfen ---------- */
  $('#svcCheckNet')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    busy(b, true);
    const chips = $$('.svc-chip[data-svc]:not(.nocheck)');
    chips.forEach((c) => c.classList.add('busy'));
    toast(T('Dienste werden geprüft …'));
    const r = await post(b.dataset.url, { n: b.dataset.net });
    busy(b, false);
    chips.forEach((c) => c.classList.remove('busy'));
    if (r.error) return toast(r.error, 'bad');
    Object.entries(r.results || {}).forEach(([id, s]) => {
      $$('.svc-chip[data-svc="' + id + '"]').forEach((c) => { c.className = 'svc-chip ' + s.state; c.title = s.title; });
    });
    const c = r.counts || { up: 0, down: 0, unknown: 0 };
    toast(T('{ok} verfügbar · {bad} nicht verfügbar · {unk} unbekannt', { ok: c.up, bad: c.down, unk: c.unknown }), c.down ? 'bad' : 'ok');
  });

  /* ---------- iperf3 ---------- */
  const iperfForm = $('#iperfForm');
  if (iperfForm) {
    const proto = $('#iperfProto');
    const syncUdp = () => $$('.iperf-udp', iperfForm).forEach((el) => { el.hidden = proto.value !== 'udp'; });
    proto?.addEventListener('change', syncUdp);
    syncUdp();
    const portIn = iperfForm.querySelector('[name="port"]');
    portIn?.addEventListener('focus', () => portIn.select());
    iperfForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = iperfForm.querySelector('button[type=submit]');
      const out = $('#iperfOut');
      const data = formData(iperfForm);
      busy(btn, true);
      out.innerHTML = '<p class="dim iperf-running"><span class="spinner"></span>' + esc(T('iperf3 läuft ({s} s) …', { s: data.duration || '?' })) + '</p>';
      const r = await post(iperfForm.closest('.iperf-card').dataset.url, { ...hostData(), ...data });
      busy(btn, false);
      if (r.error && !r.html) { out.innerHTML = '<p class="note warn">' + esc(r.error) + '</p>'; return toast(r.error, 'bad'); }
      out.innerHTML = r.html || '';
      if (!r.ok) toast(r.error || T('Fehler'), 'bad');
    });
  }

  /* ---------- MAC & Hardware ermitteln ---------- */
  $('#hostDetect')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    const out = $('#detectOut');
    busy(b, true);
    out.innerHTML = '<pre class="console">' + esc(T('Ermittle MAC und Hardware …')) + '</pre>';
    const r = await post(b.dataset.url, hostData());
    busy(b, false);
    if (r.error) { out.innerHTML = ''; return toast(r.error, 'bad'); }
    const found = r.mac && r.mac !== 'not found';
    out.innerHTML = '<div class="detect-box ' + (found ? 'ok' : 'warn') + '"><div class="detect-head"><b>' + esc(T('Ermittelt')) + ':</b> MAC <span class="mono">'
      + esc(found ? r.mac : T('nicht gefunden')) + '</span>' + (r.hw_id && r.hw_id !== 'not found' ? ' · HW <span class="mono">' + esc(r.hw_id) + '</span>' : '')
      + (r.vendor ? ' · ' + esc(r.vendor) : '') + '</div><pre class="console small">' + esc((r.log || []).join('\n')) + '</pre></div>';
    toast(found ? T('Ermittelt') + ': ' + r.mac : 'MAC: ' + T('nicht gefunden'), found ? 'ok' : 'bad');
    if ((r.changed || []).length) setTimeout(() => location.reload(), 1600);
  });

  /* ---------- Icon-Auswahl ---------- */
  const picker = $('#iconPicker');
  if (picker) {
    const cur = $('#iconCurrent');
    const filter = $('#iconFilter');
    const label = (opt) => opt?.getAttribute('title') || '';
    filter?.addEventListener('input', () => {
      const q = filter.value.trim().toLowerCase();
      $$('.icon-opt', picker).forEach((o) => { o.hidden = !!q && !(o.dataset.label || '').includes(q) && !o.querySelector('input').value.toLowerCase().includes(q); });
      $$('.icon-group', picker).forEach((g) => { const opts = $$('.icon-opt', g); g.hidden = !!q && opts.length > 0 && opts.every((o) => o.hidden); });
    });
    filter?.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    picker.addEventListener('change', (e) => {
      if (e.target.name === 'icon') cur.textContent = label(e.target.closest('.icon-opt'));
      if (e.target.name === 'icon_upload' && e.target.files[0]) {
        cur.textContent = e.target.files[0].name;
        $$('input[name="icon"]', picker).forEach((i) => { i.checked = false; });
      }
    });
    const checked = $('input[name="icon"]:checked', picker);
    if (checked) cur.textContent = label(checked.closest('.icon-opt'));
  }
  $('#brandAll')?.addEventListener('change', (e) => {
    $$('.brand-opt input').forEach((i) => { i.checked = e.target.checked; });
  });

  /* ---------- System: Pakete & PAM-Helfer installieren ---------- */
  const sysLog = $('#sysLog');
  const sysForm = $('#sysInstall');
  let polling = null;
  const pollJob = () => {
    if (polling || !sysForm) return;
    sysLog.hidden = false;
    polling = setInterval(async () => {
      const r = await post(sysForm.dataset.status);
      if (r.error) return;
      sysLog.textContent = r.log || T('Installation läuft …');
      sysLog.scrollTop = sysLog.scrollHeight;
      if (!r.running) {
        clearInterval(polling);
        polling = null;
        $$('button', sysForm).forEach((b) => busy(b, false));
        const ok = r.rc === 0;
        toast(T(ok ? 'Installation abgeschlossen' : 'Installation fehlgeschlagen'), ok ? 'ok' : 'bad');
        if (ok) setTimeout(() => location.reload(), 2500);
      }
    }, 1500);
  };
  const startJob = async (btn, url, data) => {
    busy(btn, true);
    sysLog.hidden = false;
    sysLog.textContent = T('Installation läuft …');
    const r = await post(url, data);
    if (!r.ok) { busy(btn, false); sysLog.textContent = r.error || T('Installation fehlgeschlagen'); return toast(r.error || T('Installation fehlgeschlagen'), 'bad'); }
    pollJob();
  };
  sysForm?.addEventListener('submit', (e) => {
    e.preventDefault();
    const pkgs = $$('input[name="pkg"]:checked', sysForm).map((i) => i.value);
    if (!pkgs.length) return toast(T('Bitte Pakete auswählen'), 'bad');
    startJob(e.submitter || sysForm.querySelector('button[type=submit]'), sysForm.dataset.url, { pkgs });
  });
  $('#pamHelperInstall')?.addEventListener('click', (e) => startJob(e.currentTarget, e.currentTarget.dataset.url, {}));
  if (sysLog && sysLog.dataset.running === '1') pollJob();
  // Manuelle apt-Zeile aus der Auswahl
  if (sysForm && $('#sysManual')) {
    const manual = $('#sysManual .mono');
    const sync = () => {
      const pkgs = $$('input[name="pkg"]:checked', sysForm).map((i) => i.value);
      manual.textContent = 'sudo apt install ' + (pkgs.length ? pkgs.join(' ') : '…');
    };
    sysForm.addEventListener('change', sync);
    sync();
  }

  /* ---------- Tester: PAM, LDAP, YubiKey-OTP ---------- */
  const tester = (formSel, outSel, render) => {
    const f = $(formSel);
    f?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = f.querySelector('button[type=submit]');
      const out = $(outSel);
      busy(btn, true);
      out.hidden = false;
      out.textContent = T('Prüfe …');
      const r = await post(f.dataset.url, formData(f));
      busy(btn, false);
      if (r.error) return consoleOut(out, ['✗ ' + r.error]);
      render(out, r);
      f.querySelectorAll('input[type=password]').forEach((i) => { i.value = ''; });
    });
  };
  tester('#pamTest', '#pamTestOut', (out, r) => consoleOut(out, [(r.ok ? '✓ ' : '✗ ') + r.text, 'rc=' + r.code + ' · ' + r.ms + ' ms']));
  tester('#ldapTestForm', '#ldapTestOut', (out, r) => consoleOut(out, r.log || []));
  tester('#otpTest', '#otpTestOut', (out, r) => consoleOut(out, [(r.ok ? '✓ ' : '✗ ') + r.text]));

  /* ---------- Diagnose: Filter ---------- */
  $('#diagOnlyProblems')?.addEventListener('change', (e) => {
    $$('.diag-item.ok').forEach((li) => { li.hidden = e.target.checked; });
    $$('.diag-card.ok').forEach((c) => { c.hidden = e.target.checked; });
  });
  $('#debugType')?.addEventListener('change', (e) => {
    const v = e.target.value;
    $$('#debugTable tbody tr').forEach((tr) => {
      tr.hidden = v !== '' && !(v === 'slow' ? tr.dataset.slow === '1' : v === 'err' ? tr.dataset.err === '1' : tr.dataset.type === v);
    });
  });

  /* ---------- Wiederholbare Zeilen (Master-Dienste) ---------- */
  let repeatSeq = 1000;
  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-repeat-add]');
    if (add) {
      const list = document.getElementById(add.dataset.repeatAdd);
      const tpl = document.getElementById(add.dataset.tpl);
      if (!list || !tpl) return;
      const wrap = document.createElement('div');
      wrap.innerHTML = tpl.innerHTML.replaceAll('__I__', String(repeatSeq++));
      const row = wrap.firstElementChild;
      list.appendChild(row);
      row.querySelector('input:not([type=hidden])')?.focus();
      return;
    }
    const del = e.target.closest('[data-repeat-del]');
    if (del) del.closest('[data-repeat-row]')?.remove();
  });

  /* ---------- Master: Netzwerke abhaken, Ziel-IP live ---------- */
  const mForm = $('#masterForm');
  if (mForm) {
    const rule = $('#masterRule');
    const off = $('#masterOffset');
    const ip2n = (ip) => ip.split('.').reduce((a, b) => a * 256 + (+b), 0);
    const n2ip = (n) => [24, 16, 8, 0].map((s) => Math.floor(n / 2 ** s) % 256).join('.');
    const calc = () => {
      off.closest('.field').hidden = rule.value !== 'offset';
      $$('.master-nets tbody tr').forEach((tr) => {
        const d = tr.dataset;
        const on = tr.querySelector('input[type=checkbox]').checked;
        let ip = '';
        let why = '';
        if (!d.first) why = T('kein CIDR hinterlegt');
        else if (rule.value === 'first') ip = d.first;
        else if (rule.value === 'last') ip = d.last;
        else if (rule.value === 'gateway') { ip = d.gw; if (!ip) why = T('kein Gateway hinterlegt'); }
        else if (rule.value === 'offset') {
          if (d.v6 === '1') why = '–';
          else {
            const n = ip2n(d.network) + Math.max(1, parseInt(off.value, 10) || 1);
            if (n > ip2n(d.last)) why = T('Versatz liegt außerhalb des Netzes'); else ip = n2ip(n);
          }
        }
        let used = {};
        try { used = JSON.parse(d.used || '{}'); } catch (x) { used = {}; }
        let st = '–';
        let cls = 'dim';
        if (!on) { if (d.linked === '1') { st = T('wird entfernt'); cls = 'bad'; } }
        else if (!ip) { st = T('nicht möglich') + (why ? ' · ' + why : ''); cls = 'bad'; }
        else if (used[ip]) { st = T('IP belegt') + ' · ' + used[ip]; cls = 'bad'; }
        else if (d.linked === '1') { st = T('vorhanden – wird abgeglichen'); cls = 'ok-soft'; }
        else if (!(ip in used)) { st = T('wird beim Speichern geprüft'); cls = 'state-unknown'; }
        else { st = T('wird angelegt'); cls = 'state-unknown'; }
        $('.master-ip', tr).textContent = on ? ip : '';
        $('.master-st', tr).innerHTML = '<span class="badge ' + cls + '">' + esc(st) + '</span>';
      });
    };
    rule.addEventListener('change', calc);
    off.addEventListener('input', calc);
    $$('.master-nets input[type=checkbox]').forEach((c) => c.addEventListener('change', calc));
    $('#masterAll')?.addEventListener('change', (e) => { $$('.master-nets input[type=checkbox]').forEach((c) => { c.checked = e.target.checked; }); calc(); });
    calc();
  }

  /* ---------- Caddyfile-Generator ---------- */
  const cForm = $('#caddyForm');
  if (cForm) {
    const out = $('#caddyOut');
    const state = $('#caddyState');
    const sites = $('#caddySites');
    let seq = 100;
    let timer = null;
    const syncModes = (site) => {
      const mode = $('.caddy-mode select', site)?.value || 'proxy';
      $$('.caddy-sec[data-mode]', site).forEach((sec) => {
        const modes = sec.dataset.mode.split(' ');
        sec.hidden = !(modes.includes(mode) || (mode === 'proxy' && modes.includes('proxy-spa')));
      });
    };
    const preview = async () => {
      state.textContent = T('Prüfe …');
      const body = new URLSearchParams(new FormData(cForm));
      try {
        const res = await fetch(cForm.dataset.preview, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF': csrf() }, body });
        const r = await res.json();
        if (r.ok) {
          out.textContent = r.text;
          out.classList.remove('has-error');
          const cp = cForm.querySelector('.caddy-dl [data-copy]');
          if (cp) cp.dataset.copy = r.text;
          state.textContent = (r.warnings || []).length ? '⚠ ' + r.warnings.join(' · ') : '✓ ' + T('gültig');
        } else {
          out.classList.add('has-error');
          out.textContent = (r.errors || [r.error || T('Fehler')]).map((x) => '✗ ' + x).join('\n');
          state.textContent = T('{n} Fehler', { n: (r.errors || []).length || 1 });
        }
      } catch (e) { state.textContent = T('Fehler'); }
    };
    const later = () => { clearTimeout(timer); timer = setTimeout(preview, 350); };
    cForm.addEventListener('input', (e) => {
      const site = e.target.closest('[data-site]');
      if (site && e.target.name && e.target.name.endsWith('[addresses]')) $('[data-site-label]', site).textContent = e.target.value.trim() || T('Neue Site');
      later();
    });
    cForm.addEventListener('change', (e) => {
      const site = e.target.closest('[data-site]');
      if (site && e.target.closest('.caddy-mode')) syncModes(site);
      if (e.target.classList.contains('caddy-up-pick') && e.target.value) {
        const ta = cForm.querySelector('[name="' + CSS.escape(e.target.dataset.target) + '"]');
        if (ta) ta.value = (ta.value.trim() ? ta.value.trim() + ' ' : '') + e.target.value;
        e.target.value = '';
      }
      later();
    });
    cForm.addEventListener('click', (e) => {
      const del = e.target.closest('[data-site-del]');
      if (del) {
        e.preventDefault();
        if ($$('[data-site]', sites).length <= 1) return toast(T('Mindestens eine Zeile'), 'bad');
        del.closest('[data-site]').remove();
        later();
        return;
      }
      if (e.target.closest('#caddyAddSite')) {
        e.preventDefault();
        const wrap = document.createElement('div');
        wrap.innerHTML = $('#caddySiteTpl').innerHTML.replaceAll('__I__', String(seq++));
        const site = wrap.firstElementChild;
        sites.appendChild(site);
        syncModes(site);
        site.scrollIntoView({ behavior: 'smooth', block: 'start' });
        later();
      }
    });
    $$('[data-site]', sites).forEach(syncModes);
  }

  /* ---------- WebAuthn (YubiKey) ---------- */
  const b64urlToBuf = (s) => {
    const b = atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4));
    return Uint8Array.from(b, (c) => c.charCodeAt(0)).buffer;
  };
  const bufToB64url = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const waSupported = () => !!(window.PublicKeyCredential && navigator.credentials);

  const waLogin = $('#waLogin');
  if (waLogin && $('#waStart')) {
    const err = $('#waError');
    const showErr = (m) => { err.textContent = m; err.hidden = false; };
    const run = async () => {
      if (!waSupported()) return showErr(T('Dieser Browser unterstützt keine Sicherheitsschlüssel (oder die Seite läuft nicht über HTTPS).'));
      const btn = $('#waStart');
      busy(btn, true);
      err.hidden = true;
      try {
        const opt = await post(waLogin.dataset.options);
        if (opt.error) throw new Error(opt.error);
        opt.challenge = b64urlToBuf(opt.challenge);
        opt.allowCredentials = (opt.allowCredentials || []).map((c) => ({ ...c, id: b64urlToBuf(c.id) }));
        const cred = await navigator.credentials.get({ publicKey: opt });
        const res = await post(waLogin.dataset.finish, {
          id: cred.id,
          clientDataJSON: bufToB64url(cred.response.clientDataJSON),
          authenticatorData: bufToB64url(cred.response.authenticatorData),
          signature: bufToB64url(cred.response.signature),
        });
        if (res.error) throw new Error(res.error);
        location.href = res.redirect || './';
      } catch (e) {
        busy(btn, false);
        showErr(e.name === 'NotAllowedError' ? T('Abgebrochen oder Zeit abgelaufen – bitte erneut versuchen.') : (e.message || String(e)));
      }
    };
    $('#waStart').addEventListener('click', run);
    if (waLogin.dataset.auto && !err.textContent) setTimeout(run, 250);
  }

  const waReg = $('#waRegister');
  if (waReg) {
    const err = $('#waRegError');
    $('#waRegBtn').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      err.hidden = true;
      if (!waSupported()) { err.textContent = T('Dieser Browser unterstützt keine Sicherheitsschlüssel (oder die Seite läuft nicht über HTTPS).'); err.hidden = false; return; }
      busy(btn, true);
      try {
        const opt = await post(waReg.dataset.options);
        if (opt.error) throw new Error(opt.error);
        opt.challenge = b64urlToBuf(opt.challenge);
        opt.user.id = b64urlToBuf(opt.user.id);
        opt.excludeCredentials = (opt.excludeCredentials || []).map((c) => ({ ...c, id: b64urlToBuf(c.id) }));
        const cred = await navigator.credentials.create({ publicKey: opt });
        const res = await post(waReg.dataset.finish, {
          name: $('#waName').value,
          clientDataJSON: bufToB64url(cred.response.clientDataJSON),
          attestationObject: bufToB64url(cred.response.attestationObject),
        });
        if (res.error) throw new Error(res.error);
        toast(T('YubiKey „{n}“ registriert', { n: res.name }), 'ok');
        setTimeout(() => location.reload(), 700);
      } catch (e) {
        busy(btn, false);
        err.textContent = e.name === 'InvalidStateError' ? T('Dieser Key ist bereits registriert.') : e.name === 'NotAllowedError' ? T('Abgebrochen oder Zeit abgelaufen.') : (e.message || String(e));
        err.hidden = false;
      }
    });
  }


  /* ---------- WAN: Prüfung, Passwort anzeigen, Vorlage übernehmen ---------- */
  function wanApply(id, r) {
    const tr = document.querySelector('tr[data-wan="' + id + '"]');
    if (tr) {
      setRowState(tr, { ok: r.ok === true, avg: r.avg });
      if (r.ok === null) { const d = $('.dot', tr); if (d) { d.className = 'dot unknown'; d.title = r.error || ''; } }
    }
    const st = $('#wanStatus');
    if (st) st.textContent = r.text || r.error || '';
    const path = $('.wan-path');
    if (path) path.className = 'wan-path st-' + (r.ok === null ? 'unknown' : (r.ok ? 'up' : 'down'));
  }
  async function wanCheck(btn, id) {
    busy(btn, true);
    const res = await post('?a=wan_check', id ? { id } : {});
    busy(btn, false);
    if (res.error) { toast(res.error, 'bad'); return; }
    let up = 0, n = 0;
    Object.entries(res.results || {}).forEach(([k, r]) => { wanApply(k, r); n++; if (r.ok) up++; });
    const on = $('#wanOnline');
    if (on && !id) on.innerHTML = up + '<small> / ' + n + '</small>';
    if (id) {
      const r = (res.results || {})[id] || {};
      toast(r.ok === null ? (r.error || T('Fehler')) : (r.ok ? T('✓ {t} antwortet · {ms} ms', { t: r.target, ms: r.avg ?? '?' }) : T('✗ {t} antwortet nicht', { t: r.target })), r.ok ? 'ok' : 'bad');
    } else {
      toast(T('{a} von {b} Anschlüssen erreichbar', { a: up, b: n }), up === n ? 'ok' : 'bad');
    }
  }
  $$('[data-wan-check]').forEach((b) => b.addEventListener('click', () => wanCheck(b, b.dataset.wanCheck)));
  $('#wanCheckAll')?.addEventListener('click', (e) => wanCheck(e.currentTarget, 0));
  $$('[data-wan-secret]').forEach((b) => b.addEventListener('click', async () => {
    const out = $('#wanSecret');
    if (out && out.dataset.shown) { out.textContent = '••••••••'; delete out.dataset.shown; b.lastChild.textContent = T('Anzeigen'); return; }
    busy(b, true);
    const res = await post('?a=wan_secret', { id: b.dataset.wanSecret });
    busy(b, false);
    if (res.error) { toast(res.error, 'bad'); return; }
    if (out) { out.textContent = res.secret; out.dataset.shown = '1'; b.lastChild.textContent = T('Verbergen'); }
    setTimeout(() => { if (out?.dataset.shown) { out.textContent = '••••••••'; delete out.dataset.shown; b.lastChild.textContent = T('Anzeigen'); } }, 30000);
  }));
  const wanSel = $('#wanTemplate');
  if (wanSel) {
    let tpls = {};
    try { tpls = JSON.parse($('#wanTpls')?.textContent || '{}'); } catch (e) { tpls = {}; }
    wanSel.addEventListener('change', () => {
      const t = tpls[wanSel.value];
      if (!t) return;
      const f = $('#wanForm');
      Object.entries(t).forEach(([k, v]) => {
        if (k === 'user_hint') { const h = $('#wanUserHint'); if (h) h.textContent = v || ''; return; }
        const el = f.elements[k];
        if (el && v !== null && v !== '') el.value = String(v);
        else if (el && (k === 'wan_vlan' || k === 'ipv6_len')) el.value = '';
      });
      toast(T('Werte der Vorlage übernommen'), 'ok');
    });
  }

  /* ---------- NetMap: zum gewählten Gerät bzw. Netzwerk scrollen ---------- */
  const nmBox = $('.nm-scroll');
  if (nmBox) {
    const target = $('.nm-node.focus') || ($('.nm-svg .nm-node.dim') ? $$('.nm-svg .nm-node:not(.dim)').filter((n) => !n.classList.contains('nm-inet')).pop() : $('.nm-svg .nm-inet'));
    if (target) {
      const r = target.getBoundingClientRect();
      const c = nmBox.getBoundingClientRect();
      nmBox.scrollLeft += r.left - c.left - (c.width - r.width) / 2;
      nmBox.scrollTop += r.top - c.top - (c.height - r.height) / 2;
    }
  }

  // Tastenkürzel: "/" springt in die Suche
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.target.closest('input, textarea, select')) {
      e.preventDefault();
      ($('#tableFilter') || $('#searchInput'))?.focus();
    }
  });
})();
