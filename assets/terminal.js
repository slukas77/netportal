/* Network Portal – SSH-Terminal im Browser.
   Eigener, kleiner VT100/xterm-Emulator (ohne fremde Bibliotheken) plus
   Sitzungsverwaltung (Tabs, Long-Polling über fetch, Eingabe, Größe). */
(() => {
  'use strict';

  const box = document.getElementById('termConnect');
  if (!box) return;

  let STR = {};
  try { STR = JSON.parse(document.getElementById('i18n')?.textContent || '{}'); } catch (e) { STR = {}; }
  const T = (s, p = {}) => Object.entries(p).reduce((a, [k, v]) => a.replaceAll('{' + k + '}', String(v)), STR[s] ?? s);
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const csrf = () => (document.querySelector('meta[name="csrf"]') || {}).content || '';
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* privat/gesperrt */ } },
  };

  async function post(url, data = {}, keepalive = false) {
    const res = await fetch(url, {
      method: 'POST', credentials: 'same-origin', keepalive,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF': csrf() },
      body: JSON.stringify(data),
    });
    let j = {};
    try { j = await res.json(); } catch (e) { j = { error: T('Unerwartete Antwort ({s})', { s: res.status }) }; }
    if (!res.ok && !j.error) j.error = T('Fehler {s}', { s: res.status });
    j.status = res.status;
    return j;
  }

  /* ======================= Terminal-Emulator ======================= */

  const BASE16 = ['#1a2533', '#ef6b6b', '#3ecf8e', '#f2c14e', '#4a9eff', '#b88cf5', '#2fd0bd', '#d6e0eb',
    '#5b6b7e', '#ff8f8f', '#7ff0b6', '#ffd97a', '#8cc2ff', '#d2b5ff', '#7cefe0', '#ffffff'];
  const DEF_FG = '#d6e0eb';
  const DEF_BG = '#0f1823';
  const DEC = { '`': '◆', a: '▒', b: '␉', c: '␌', d: '␍', e: '␊', f: '°', g: '±', h: '␤', i: '␋', j: '┘', k: '┐', l: '┌', m: '└', n: '┼', o: '⎺', p: '⎻', q: '─', r: '⎼', s: '⎽', t: '├', u: '┤', v: '┴', w: '┬', x: '│', y: '≤', z: '≥', '{': 'π', '|': '≠', '}': '£', '~': '·' };

  function color256(n) {
    if (n < 16) return BASE16[n];
    if (n < 232) {
      n -= 16;
      const v = [0, 95, 135, 175, 215, 255];
      return 'rgb(' + v[Math.floor(n / 36)] + ',' + v[Math.floor(n / 6) % 6] + ',' + v[n % 6] + ')';
    }
    const g = 8 + (n - 232) * 10;
    return 'rgb(' + g + ',' + g + ',' + g + ')';
  }
  const esc = (s) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));

  class VT {
    constructor(host, onData, onTitle) {
      this.onData = onData;
      this.onTitle = onTitle || (() => {});
      this.cols = 80;
      this.rows = 24;
      this.view = document.createElement('div');
      this.view.className = 'term-view';
      this.sbEl = document.createElement('div');
      this.sbEl.className = 'term-sb';
      this.scrEl = document.createElement('div');
      this.scrEl.className = 'term-scr';
      this.view.append(this.sbEl, this.scrEl);
      this.input = document.createElement('textarea');
      this.input.className = 'term-input';
      this.input.setAttribute('autocapitalize', 'off');
      this.input.setAttribute('autocomplete', 'off');
      this.input.setAttribute('autocorrect', 'off');
      this.input.setAttribute('spellcheck', 'false');
      this.input.setAttribute('aria-label', 'Terminal');
      host.append(this.view, this.input);
      this.styleCache = new Map();
      this.sbCount = 0;
      this.follow = true;
      this.reset();
      this.bindInput();
      this.view.addEventListener('mouseup', () => { if (!String(window.getSelection() || '')) this.focus(); });
      this.view.addEventListener('scroll', () => { this.follow = this.view.scrollTop + this.view.clientHeight >= this.view.scrollHeight - 4; });
    }

    reset() {
      this.attr = { fg: null, bg: null, b: 0, d: 0, i: 0, u: 0, v: 0, h: 0, s: 0 };
      this.akey = this.attrKey(this.attr);
      this.normal = this.blankScreen();
      this.alt = null;
      this.buf = this.normal;
      this.x = 0; this.y = 0; this.wrap = false;
      this.top = 0; this.bottom = this.rows - 1;
      this.appCursor = false; this.autowrap = true; this.curVis = true; this.bracket = false; this.insert = false; this.originMode = false;
      this.g = ['B', 'B']; this.gl = 0;
      this.saved = null;
      this.state = 0; this.seq = '';
      this.dirty = new Set();
      for (let r = 0; r < this.rows; r++) this.dirty.add(r);
      this.rowEls = [];
      this.scrEl.textContent = '';
      for (let r = 0; r < this.rows; r++) { const d = document.createElement('div'); d.className = 'term-row'; this.scrEl.append(d); this.rowEls.push(d); }
      this.schedule();
    }

    attrKey(a) { return [a.fg ?? '', a.bg ?? '', a.b, a.d, a.i, a.u, a.v, a.h, a.s].join('|'); }
    eraseKey() { return this.attrKey({ fg: null, bg: this.attr.bg, b: 0, d: 0, i: 0, u: 0, v: 0, h: 0, s: 0 }); }
    blankLine(k) { const key = k ?? this.akeyBlank ?? '||0|0|0|0|0|0|0'; const l = new Array(this.cols); for (let i = 0; i < this.cols; i++) l[i] = [' ', key]; return l; }
    blankScreen() { const s = []; for (let r = 0; r < this.rows; r++) s.push(this.blankLine('||0|0|0|0|0|0|0')); return s; }
    mark(r) { this.dirty.add(r); this.schedule(); }
    markAll() { for (let r = 0; r < this.rows; r++) this.dirty.add(r); this.schedule(); }

    schedule() {
      if (this.raf) return;
      this.raf = requestAnimationFrame(() => { this.raf = 0; this.render(); });
    }

    styleOf(key) {
      let s = this.styleCache.get(key);
      if (s !== undefined) return s;
      const [fg0, bg0, b, d, it, u, v, hid, st] = key.split('|');
      let fg = fg0 === '' ? null : (fg0[0] === '#' || fg0[0] === 'r' ? fg0 : color256(+fg0 + (b === '1' && +fg0 < 8 ? 8 : 0)));
      let bg = bg0 === '' ? null : (bg0[0] === '#' || bg0[0] === 'r' ? bg0 : color256(+bg0));
      if (v === '1') { const f = fg; fg = bg || DEF_BG; bg = f || DEF_FG; }
      const css = [];
      if (fg) css.push('color:' + fg);
      if (bg) css.push('background:' + bg);
      if (b === '1') css.push('font-weight:700');
      if (d === '1') css.push('opacity:.62');
      if (it === '1') css.push('font-style:italic');
      const deco = (u === '1' ? 'underline ' : '') + (st === '1' ? 'line-through' : '');
      if (deco) css.push('text-decoration:' + deco.trim());
      if (hid === '1') css.push('color:transparent');
      s = css.join(';');
      this.styleCache.set(key, s);
      return s;
    }

    lineHtml(line, curX) {
      let html = '';
      let i = 0;
      while (i < line.length) {
        const k = line[i][1];
        let txt = '';
        let j = i;
        while (j < line.length && line[j][1] === k && j !== curX) { txt += line[j][0]; j++; }
        if (j > i) {
          const st = this.styleOf(k);
          html += st ? '<span style="' + st + '">' + esc(txt) + '</span>' : esc(txt);
          i = j;
        }
        if (i === curX && i < line.length) {
          const st = this.styleOf(line[i][1]);
          html += '<span class="term-cur"' + (st ? ' style="' + st + '"' : '') + '>' + esc(line[i][0]) + '</span>';
          i++;
          curX = -1;
        }
      }
      return html;
    }

    render() {
      for (const r of this.dirty) {
        const el = this.rowEls[r];
        const line = this.buf[r];
        if (!el || !line) continue;
        el.innerHTML = this.lineHtml(line, this.curVis && r === this.y ? Math.min(this.x, this.cols - 1) : -1);
      }
      this.dirty.clear();
      if (this.follow) this.view.scrollTop = this.view.scrollHeight;
    }

    pushScrollback(line) {
      if (this.buf !== this.normal) return;
      const d = document.createElement('div');
      d.className = 'term-row';
      d.innerHTML = this.lineHtml(line, -1);
      this.sbEl.append(d);
      if (++this.sbCount > 3000) { this.sbEl.firstChild?.remove(); this.sbCount--; }
    }

    scrollUp(n = 1) {
      for (let k = 0; k < n; k++) {
        const old = this.buf.splice(this.top, 1)[0];
        if (this.top === 0) this.pushScrollback(old);
        this.buf.splice(this.bottom, 0, this.blankLine(this.eraseKey()));
      }
      for (let r = this.top; r <= this.bottom; r++) this.dirty.add(r);
      this.schedule();
    }

    scrollDown(n = 1) {
      for (let k = 0; k < n; k++) {
        this.buf.splice(this.bottom, 1);
        this.buf.splice(this.top, 0, this.blankLine(this.eraseKey()));
      }
      for (let r = this.top; r <= this.bottom; r++) this.dirty.add(r);
      this.schedule();
    }

    lineFeed() {
      this.mark(this.y);
      if (this.y === this.bottom) this.scrollUp(1);
      else if (this.y < this.rows - 1) this.y++;
      this.mark(this.y);
    }

    moveTo(x, y) {
      this.mark(this.y);
      this.x = Math.max(0, Math.min(this.cols - 1, x));
      const minY = this.originMode ? this.top : 0;
      const maxY = this.originMode ? this.bottom : this.rows - 1;
      this.y = Math.max(minY, Math.min(maxY, y));
      this.wrap = false;
      this.mark(this.y);
    }

    print(ch) {
      if (this.g[this.gl] === '0' && DEC[ch]) ch = DEC[ch];
      if (this.wrap && this.autowrap) { this.x = 0; this.wrap = false; this.lineFeed(); }
      const line = this.buf[this.y];
      if (this.insert) { line.splice(this.x, 0, [ch, this.akey]); line.length = this.cols; } else line[this.x] = [ch, this.akey];
      this.lastChar = ch;
      if (this.x >= this.cols - 1) this.wrap = true; else this.x++;
      this.mark(this.y);
    }

    write(s) {
      for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        const c = ch.charCodeAt(0);
        switch (this.state) {
          case 0:
            if (c === 0x1b) { this.state = 1; this.seq = ''; } else if (c < 0x20 || c === 0x7f) this.control(c); else this.print(ch);
            break;
          case 1: // ESC
            this.escape(ch);
            break;
          case 2: // CSI
            if (c >= 0x40 && c <= 0x7e) { this.csi(this.seq, ch); this.state = 0; } else if (c === 0x1b) { this.state = 1; this.seq = ''; } else if (this.seq.length < 64) this.seq += ch;
            break;
          case 3: // OSC
            if (c === 0x07) { this.osc(this.seq); this.state = 0; } else if (c === 0x1b) this.state = 4; else if (this.seq.length < 512) this.seq += ch;
            break;
          case 4: // OSC/DCS-Ende (ESC \)
            if (ch === '\\') this.osc(this.seq);
            this.state = 0;
            break;
          case 5: // Zeichensatz wählen
            this.g[this.seq === ')' ? 1 : 0] = ch;
            this.state = 0;
            break;
          case 6: // DCS/PM/APC überspringen
            if (c === 0x1b) this.state = 7; else if (c === 0x07) this.state = 0;
            break;
          case 7:
            this.state = ch === '\\' ? 0 : 6;
            break;
          default:
            this.state = 0;
        }
      }
    }

    control(c) {
      switch (c) {
        case 0x07: this.view.classList.add('bell'); setTimeout(() => this.view.classList.remove('bell'), 120); break;
        case 0x08: if (this.x > 0) { this.x--; this.wrap = false; this.mark(this.y); } break;
        case 0x09: { const nx = Math.min(this.cols - 1, (Math.floor(this.x / 8) + 1) * 8); this.x = nx; this.mark(this.y); break; }
        case 0x0a: case 0x0b: case 0x0c: this.lineFeed(); break;
        case 0x0d: this.x = 0; this.wrap = false; this.mark(this.y); break;
        case 0x0e: this.gl = 1; break;
        case 0x0f: this.gl = 0; break;
        default: break;
      }
    }

    escape(ch) {
      this.state = 0;
      switch (ch) {
        case '[': this.state = 2; this.seq = ''; break;
        case ']': this.state = 3; this.seq = ''; break;
        case 'P': case '^': case '_': case 'X': this.state = 6; break;
        case '(': case ')': this.state = 5; this.seq = ch; break;
        case '7': this.saveCursor(); break;
        case '8': this.restoreCursor(); break;
        case 'D': this.lineFeed(); break;
        case 'E': this.x = 0; this.lineFeed(); break;
        case 'M': this.mark(this.y); if (this.y === this.top) this.scrollDown(1); else if (this.y > 0) this.y--; this.mark(this.y); break;
        case 'c': this.reset(); break;
        case '=': case '>': break;
        default: break;
      }
    }

    saveCursor() { this.saved = { x: this.x, y: this.y, attr: { ...this.attr }, g: [...this.g], gl: this.gl, wrap: this.wrap }; }
    restoreCursor() {
      const s = this.saved;
      if (!s) { this.moveTo(0, 0); return; }
      this.attr = { ...s.attr }; this.akey = this.attrKey(this.attr); this.g = [...s.g]; this.gl = s.gl;
      this.moveTo(s.x, s.y); this.wrap = s.wrap;
    }

    osc(s) {
      const m = /^(\d+);(.*)$/s.exec(s);
      if (m && (m[1] === '0' || m[1] === '2')) this.onTitle(m[2].slice(0, 120));
    }

    erase(line, a, b) {
      const k = this.eraseKey();
      for (let i = Math.max(0, a); i < Math.min(this.cols, b); i++) line[i] = [' ', k];
    }

    csi(raw, f) {
      let pre = '';
      if (raw && '?><='.includes(raw[0])) { pre = raw[0]; raw = raw.slice(1); }
      const inter = raw.replace(/[0-9;:]/g, '');
      raw = raw.replace(/[^0-9;:]/g, '');
      const ps = raw === '' ? [] : raw.split(';').map((v) => (v === '' ? 0 : parseInt(v.split(':')[0], 10) || 0));
      const sub = raw === '' ? [] : raw.split(';').map((v) => v.split(':').map((x) => parseInt(x, 10) || 0));
      const p = (i, d) => (ps[i] === undefined || ps[i] === 0 ? d : ps[i]);
      if (inter !== '') { if (inter === ' ' && f === 'q') return; return; }
      const line = this.buf[this.y];
      switch (f) {
        case 'A': this.moveTo(this.x, this.y - p(0, 1)); break;
        case 'B': case 'e': this.moveTo(this.x, this.y + p(0, 1)); break;
        case 'C': case 'a': this.moveTo(this.x + p(0, 1), this.y); break;
        case 'D': this.moveTo(this.x - p(0, 1), this.y); break;
        case 'E': this.moveTo(0, this.y + p(0, 1)); break;
        case 'F': this.moveTo(0, this.y - p(0, 1)); break;
        case 'G': case '`': this.moveTo(p(0, 1) - 1, this.y); break;
        case 'd': this.moveTo(this.x, (this.originMode ? this.top : 0) + p(0, 1) - 1); break;
        case 'H': case 'f': this.moveTo(p(1, 1) - 1, (this.originMode ? this.top : 0) + p(0, 1) - 1); break;
        case 'J': {
          const m = ps[0] || 0;
          if (m === 0) { this.erase(line, this.x, this.cols); for (let r = this.y + 1; r < this.rows; r++) { this.erase(this.buf[r], 0, this.cols); } }
          else if (m === 1) { this.erase(line, 0, this.x + 1); for (let r = 0; r < this.y; r++) this.erase(this.buf[r], 0, this.cols); }
          else if (m === 2) { for (let r = 0; r < this.rows; r++) this.erase(this.buf[r], 0, this.cols); }
          else if (m === 3) { this.sbEl.textContent = ''; this.sbCount = 0; }
          this.markAll();
          break;
        }
        case 'K': {
          const m = ps[0] || 0;
          if (m === 0) this.erase(line, this.x, this.cols); else if (m === 1) this.erase(line, 0, this.x + 1); else this.erase(line, 0, this.cols);
          this.mark(this.y);
          break;
        }
        case 'L': if (this.y >= this.top && this.y <= this.bottom) { const n = Math.min(p(0, 1), this.bottom - this.y + 1); for (let k = 0; k < n; k++) { this.buf.splice(this.bottom, 1); this.buf.splice(this.y, 0, this.blankLine(this.eraseKey())); } this.markAll(); } break;
        case 'M': if (this.y >= this.top && this.y <= this.bottom) { const n = Math.min(p(0, 1), this.bottom - this.y + 1); for (let k = 0; k < n; k++) { this.buf.splice(this.y, 1); this.buf.splice(this.bottom, 0, this.blankLine(this.eraseKey())); } this.markAll(); } break;
        case 'P': { const n = Math.min(p(0, 1), this.cols - this.x); line.splice(this.x, n); while (line.length < this.cols) line.push([' ', this.eraseKey()]); this.mark(this.y); break; }
        case '@': { const n = Math.min(p(0, 1), this.cols - this.x); for (let k = 0; k < n; k++) line.splice(this.x, 0, [' ', this.eraseKey()]); line.length = this.cols; this.mark(this.y); break; }
        case 'X': this.erase(line, this.x, this.x + p(0, 1)); this.mark(this.y); break;
        case 'S': this.scrollUp(p(0, 1)); break;
        case 'T': if (pre === '') this.scrollDown(p(0, 1)); break;
        case 'b': if (this.lastChar) { for (let k = 0; k < Math.min(p(0, 1), 2000); k++) this.print(this.lastChar); } break;
        case 'r':
          if (pre === '') {
            const t = p(0, 1) - 1;
            const b = p(1, this.rows) - 1;
            if (t < b && b < this.rows) { this.top = t; this.bottom = b; }
            this.moveTo(0, this.originMode ? this.top : 0);
          }
          break;
        case 's': if (pre === '') this.saveCursor(); break;
        case 'u': if (pre === '') this.restoreCursor(); break;
        case 'm': this.sgr(sub.length ? sub : [[0]]); break;
        case 'h': case 'l': this.modes(pre, ps, f === 'h'); break;
        case 'n':
          if (pre === '' && ps[0] === 6) this.onData('\x1b[' + (this.y + 1) + ';' + (this.x + 1) + 'R');
          else if (pre === '' && ps[0] === 5) this.onData('\x1b[0n');
          break;
        case 'c':
          if (pre === '') this.onData('\x1b[?1;2c'); else if (pre === '>') this.onData('\x1b[>0;276;0c');
          break;
        default: break;
      }
    }

    modes(pre, ps, on) {
      for (const m of ps) {
        if (pre === '?') {
          switch (m) {
            case 1: this.appCursor = on; break;
            case 6: this.originMode = on; this.moveTo(0, on ? this.top : 0); break;
            case 7: this.autowrap = on; break;
            case 25: this.curVis = on; this.mark(this.y); break;
            case 47: case 1047: case 1049:
              if (on && this.buf !== this.alt) {
                if (m === 1049) this.saveCursor();
                this.alt = this.blankScreen();
                this.buf = this.alt;
                this.view.classList.add('alt');
                this.markAll();
              } else if (!on && this.buf === this.alt) {
                this.buf = this.normal;
                this.alt = null;
                this.view.classList.remove('alt');
                if (m === 1049) this.restoreCursor();
                this.markAll();
              }
              break;
            case 2004: this.bracket = on; break;
            default: break;
          }
        } else if (m === 4) this.insert = on;
      }
    }

    sgr(params) {
      const a = this.attr;
      for (let i = 0; i < params.length; i++) {
        const sp = params[i];
        const n = sp[0];
        const ext = (base) => {
          // 38;5;n | 38;2;r;g;b | 38:5:n | 38:2::r:g:b
          let mode; let vals;
          if (sp.length > 1) { mode = sp[1]; vals = sp.slice(2); if (mode === 2 && vals.length === 4) vals = vals.slice(1); }
          else { mode = params[i + 1]?.[0]; vals = params.slice(i + 2, i + (mode === 2 ? 5 : 3)).map((x) => x[0]); i += mode === 2 ? 4 : 2; }
          if (mode === 5) a[base] = String(vals[0] ?? 0);
          else if (mode === 2) a[base] = 'rgb(' + (vals[0] ?? 0) + ',' + (vals[1] ?? 0) + ',' + (vals[2] ?? 0) + ')';
        };
        if (n === 0) { a.fg = null; a.bg = null; a.b = a.d = a.i = a.u = a.v = a.h = a.s = 0; }
        else if (n === 1) a.b = 1;
        else if (n === 2) a.d = 1;
        else if (n === 3) a.i = 1;
        else if (n === 4) a.u = 1;
        else if (n === 7) a.v = 1;
        else if (n === 8) a.h = 1;
        else if (n === 9) a.s = 1;
        else if (n === 21 || n === 22) { a.b = 0; a.d = 0; }
        else if (n === 23) a.i = 0;
        else if (n === 24) a.u = 0;
        else if (n === 27) a.v = 0;
        else if (n === 28) a.h = 0;
        else if (n === 29) a.s = 0;
        else if (n >= 30 && n <= 37) a.fg = String(n - 30);
        else if (n === 38) ext('fg');
        else if (n === 39) a.fg = null;
        else if (n >= 40 && n <= 47) a.bg = String(n - 40);
        else if (n === 48) ext('bg');
        else if (n === 49) a.bg = null;
        else if (n >= 90 && n <= 97) a.fg = String(n - 90 + 8);
        else if (n >= 100 && n <= 107) a.bg = String(n - 100 + 8);
      }
      this.akey = this.attrKey(a);
    }

    resize(cols, rows) {
      cols = Math.max(20, Math.min(400, cols));
      rows = Math.max(5, Math.min(200, rows));
      if (cols === this.cols && rows === this.rows) return false;
      const fix = (scr, isNormal) => {
        for (const l of scr) {
          if (l.length > cols) l.length = cols;
          while (l.length < cols) l.push([' ', '||0|0|0|0|0|0|0']);
        }
        while (scr.length > rows) {
          if (isNormal && this.y > 0 && this.y >= rows - 0 ) { const old = scr.shift(); if (this.buf === this.normal) this.pushScrollback(old); this.y--; } else scr.pop();
        }
        while (scr.length < rows) { const l = []; for (let i = 0; i < cols; i++) l.push([' ', '||0|0|0|0|0|0|0']); scr.push(l); }
      };
      this.cols = cols;
      fix(this.normal, true);
      if (this.alt) fix(this.alt, false);
      this.rows = rows;
      this.top = 0; this.bottom = rows - 1;
      this.x = Math.min(this.x, cols - 1);
      this.y = Math.min(this.y, rows - 1);
      this.wrap = false;
      this.scrEl.textContent = '';
      this.rowEls = [];
      for (let r = 0; r < rows; r++) { const d = document.createElement('div'); d.className = 'term-row'; this.scrEl.append(d); this.rowEls.push(d); }
      this.markAll();
      return true;
    }

    /* ---------- Tastatur ---------- */
    keySeq(e) {
      const mod = 1 + (e.shiftKey ? 1 : 0) + (e.altKey ? 2 : 0) + (e.ctrlKey ? 4 : 0);
      const csiKey = (c, app) => (mod > 1 ? '\x1b[1;' + mod + c : (app ? '\x1bO' : '\x1b[') + c);
      const tilde = (n) => '\x1b[' + n + (mod > 1 ? ';' + mod : '') + '~';
      switch (e.key) {
        case 'Enter': return e.altKey ? '\x1b\r' : '\r';
        case 'Backspace': return e.ctrlKey ? '\x08' : (e.altKey ? '\x1b\x7f' : '\x7f');
        case 'Tab': return e.shiftKey ? '\x1b[Z' : '\t';
        case 'Escape': return '\x1b';
        case 'ArrowUp': return csiKey('A', this.appCursor);
        case 'ArrowDown': return csiKey('B', this.appCursor);
        case 'ArrowRight': return csiKey('C', this.appCursor);
        case 'ArrowLeft': return csiKey('D', this.appCursor);
        case 'Home': return csiKey('H', this.appCursor);
        case 'End': return csiKey('F', this.appCursor);
        case 'PageUp': return e.shiftKey ? null : tilde(5);
        case 'PageDown': return e.shiftKey ? null : tilde(6);
        case 'Insert': return tilde(2);
        case 'Delete': return tilde(3);
        case 'F1': return csiKey('P', true);
        case 'F2': return csiKey('Q', true);
        case 'F3': return csiKey('R', true);
        case 'F4': return csiKey('S', true);
        case 'F5': return tilde(15);
        case 'F6': return tilde(17);
        case 'F7': return tilde(18);
        case 'F8': return tilde(19);
        case 'F9': return tilde(20);
        case 'F10': return tilde(21);
        case 'F11': return tilde(23);
        case 'F12': return tilde(24);
        default: break;
      }
      if (e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
        if (e.shiftKey && (e.key === 'C' || e.key === 'V')) return null; // Kopieren/Einfügen dem Browser überlassen
        const k = e.key.toLowerCase();
        if (k >= 'a' && k <= 'z') return String.fromCharCode(k.charCodeAt(0) - 96);
        const map = { '[': '\x1b', '\\': '\x1c', ']': '\x1d', '^': '\x1e', '6': '\x1e', '_': '\x1f', '-': '\x1f', '@': '\x00', '2': '\x00', ' ': '\x00', '/': '\x1f' };
        if (map[e.key] !== undefined) return map[e.key];
      }
      if (e.altKey && !e.ctrlKey && !e.metaKey && e.key.length === 1) return '\x1b' + e.key;
      return null;
    }

    bindInput() {
      const inp = this.input;
      let composing = false;
      inp.addEventListener('keydown', (e) => {
        if (composing) return;
        if (e.key === 'PageUp' && e.shiftKey) { this.view.scrollTop -= this.view.clientHeight * 0.9; e.preventDefault(); return; }
        if (e.key === 'PageDown' && e.shiftKey) { this.view.scrollTop += this.view.clientHeight * 0.9; e.preventDefault(); return; }
        const s = this.keySeq(e);
        if (s !== null) { e.preventDefault(); this.follow = true; this.onData(s); }
      });
      inp.addEventListener('compositionstart', () => { composing = true; });
      inp.addEventListener('compositionend', () => { composing = false; if (inp.value) { this.onData(inp.value); inp.value = ''; } });
      inp.addEventListener('input', () => { if (composing) return; if (inp.value) { this.follow = true; this.onData(inp.value); inp.value = ''; } });
      inp.addEventListener('paste', (e) => { e.preventDefault(); this.paste(e.clipboardData?.getData('text') || ''); });
      inp.addEventListener('focus', () => this.view.classList.add('focus'));
      inp.addEventListener('blur', () => this.view.classList.remove('focus'));
    }

    paste(text) {
      if (!text) return;
      text = text.replace(/\r?\n/g, '\r');
      this.follow = true;
      this.onData(this.bracket ? '\x1b[200~' + text + '\x1b[201~' : text);
    }

    focus() { this.input.focus({ preventScroll: true }); }

    /** Zeichengröße messen und passende Spalten/Zeilen berechnen. */
    fit(area) {
      const probe = document.createElement('span');
      probe.className = 'term-probe';
      probe.textContent = 'WWWWWWWWWW';
      this.scrEl.append(probe);
      const r = probe.getBoundingClientRect();
      probe.remove();
      const cw = r.width / 10 || 8;
      const ch = r.height || 16;
      const w = area.clientWidth - 16;
      const h = area.clientHeight - 12;
      return [Math.floor(w / cw), Math.floor(h / ch)];
    }
  }

  /* ======================= Sitzungen ======================= */

  const urls = { open: box.dataset.open, read: box.dataset.read, write: box.dataset.write, resize: box.dataset.resize, close: box.dataset.close };
  const panel = $('#termPanel');
  const tabs = $('#termTabs');
  const screens = $('#termScreens');
  const stateEl = $('#termState');
  const form = $('#termForm');
  const hostSel = $('#termHost');
  const userIn = $('#termUser');
  const portIn = $('#termPort');
  const freeBox = $('.term-free', form);
  const sessions = new Map();
  let active = null;
  let fontSize = store.get('np_term_font', 14);
  panel.style.setProperty('--term-font', fontSize + 'px');

  const reasonText = (r) => ({
    exit: T('Verbindung beendet'), idle: T('wegen Inaktivität beendet'), max: T('Höchstdauer erreicht'), size: T('zu viel Ausgabe – Sitzung beendet'), closed: T('getrennt'),
  }[r] || T('Verbindung beendet'));

  function setState() {
    const s = active ? sessions.get(active) : null;
    if (!s) { stateEl.textContent = ''; return; }
    stateEl.textContent = (s.open ? '● ' + T('verbunden') : '○ ' + s.endText) + ' · ' + s.title + ' · ' + s.vt.cols + '×' + s.vt.rows + (s.osc ? ' · ' + s.osc : '');
    stateEl.className = 'term-state ' + (s.open ? 'on' : 'off');
  }

  function activate(sid) {
    active = sid;
    for (const [id, s] of sessions) {
      s.pane.hidden = id !== sid;
      s.tab.classList.toggle('active', id === sid);
      s.tab.setAttribute('aria-selected', id === sid ? 'true' : 'false');
    }
    const s = sessions.get(sid);
    if (s) { refit(s); s.vt.focus(); }
    setState();
  }

  function refit(s) {
    if (!s || s.pane.hidden) return;
    const [c, r] = s.vt.fit(screens);
    if (s.vt.resize(c, r) && s.open && s.sid) {
      clearTimeout(s.rt);
      s.rt = setTimeout(() => post(urls.resize, { sid: s.sid, cols: s.vt.cols, rows: s.vt.rows }).catch(() => {}), 150);
    }
    setState();
  }

  function send(s, data) {
    if (!s.open || !s.sid) return;
    s.q += data;
    if (s.sending) return;
    const flush = async () => {
      s.sending = true;
      while (s.q && s.open) {
        const chunk = s.q.slice(0, 60000);
        s.q = s.q.slice(chunk.length);
        try {
          const r = await post(urls.write, { sid: s.sid, data: chunk });
          if (r.closed) break;
        } catch (e) { s.q = chunk + s.q; await new Promise((ok) => setTimeout(ok, 500)); }
      }
      s.sending = false;
    };
    flush();
  }

  async function readLoop(s) {
    const dec = new TextDecoder('utf-8');
    let fails = 0;
    while (s.open) {
      let r;
      try { r = await post(urls.read, { sid: s.sid, off: s.off }); fails = 0; } catch (e) {
        if (++fails > 20) { end(s, T('Verbindung zum Portal verloren')); return; }
        await new Promise((ok) => setTimeout(ok, 1000));
        continue;
      }
      if (r.status === 401 || r.status === 403 || r.status === 404) { end(s, r.error || T('Sitzung nicht gefunden.')); return; }
      if (r.error && r.status >= 500) { await new Promise((ok) => setTimeout(ok, 1000)); continue; }
      if (r.data) {
        const bin = atob(r.data);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        s.vt.write(dec.decode(bytes, { stream: true }));
      }
      if (typeof r.off === 'number') s.off = r.off;
      if (r.closed) { end(s, reasonText(r.reason) + (r.rc !== null && r.rc !== undefined ? ' (exit ' + r.rc + ')' : '')); return; }
    }
  }

  function end(s, text) {
    if (!s.open) return;
    s.open = false;
    s.endText = text;
    s.vt.write('\r\n\x1b[0;33m[' + text + ']\x1b[0m\r\n');
    s.tab.classList.add('ended');
    setState();
  }

  function closeSession(s, removeTab) {
    if (s.open && s.sid) post(urls.close, { sid: s.sid }, true).catch(() => {});
    if (removeTab) {
      s.open = false;
      s.tab.remove();
      s.pane.remove();
      sessions.delete(s.key);
      if (active === s.key) {
        const next = [...sessions.keys()].pop();
        if (next) activate(next); else { active = null; panel.hidden = true; }
      }
    } else end(s, reasonText('closed'));
  }

  function remember(entry) {
    let list = store.get('np_term_recent', []);
    if (!Array.isArray(list)) list = [];
    list = list.filter((x) => !(x.host === entry.host && x.user === entry.user && x.target === entry.target));
    list.unshift(entry);
    store.set('np_term_recent', list.slice(0, 8));
    renderRecent();
  }

  function renderRecent() {
    const el = $('#termRecent');
    const list = store.get('np_term_recent', []);
    el.textContent = '';
    if (!Array.isArray(list) || !list.length) return;
    const lab = document.createElement('span');
    lab.className = 'dim small';
    lab.textContent = T('Zuletzt:');
    el.append(lab);
    list.forEach((x) => {
      const opt = x.host && x.host !== '@free' ? hostSel.querySelector('option[value="' + CSS.escape(x.host) + '"]') : null;
      if (x.host !== '@free' && !opt) return;
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'svc-chip term-chip';
      b.textContent = x.user + '@' + (opt ? opt.dataset.label : x.target);
      b.title = T('Verbinden');
      b.addEventListener('click', () => {
        hostSel.value = x.host;
        syncFree();
        if (x.target) form.target.value = x.target;
        userIn.value = x.user;
        portIn.value = x.port;
        if (form.auth.querySelector('option[value="' + x.auth + '"]')) form.auth.value = x.auth;
        connect();
      });
      el.append(b);
    });
  }

  async function connect() {
    const hv = hostSel.value;
    if (!hv) { hostSel.focus(); return; }
    const data = { user: userIn.value.trim(), port: parseInt(portIn.value, 10) || 22, auth: form.auth.value };
    let label;
    if (hv === '@free') { data.target = form.target.value.trim(); label = data.target; if (!label) { form.target.focus(); return; } }
    else { const [n, id] = hv.split(':'); data.n = n; data.id = parseInt(id, 10); label = hostSel.selectedOptions[0]?.dataset.label || hv; }
    const key = 'k' + Date.now() + Math.random().toString(16).slice(2);
    const tab = document.createElement('button');
    tab.type = 'button';
    tab.className = 'term-tab';
    tab.setAttribute('role', 'tab');
    tab.innerHTML = '<span class="term-tab-dot"></span><span class="term-tab-title"></span><span class="term-tab-x" aria-label="' + T('Schließen') + '">×</span>';
    tab.querySelector('.term-tab-title').textContent = data.user + '@' + label;
    const pane = document.createElement('div');
    pane.className = 'term-pane';
    tabs.append(tab);
    screens.append(pane);
    panel.hidden = false;
    const s = { key, sid: null, title: data.user + '@' + label, tab, pane, off: 0, open: false, q: '', sending: false, endText: '' };
    s.vt = new VT(pane, (d) => send(s, d), (t) => { s.osc = t; if (active === key) setState(); });
    sessions.set(key, s);
    tab.addEventListener('click', (e) => {
      if (e.target.closest('.term-tab-x')) { closeSession(s, true); return; }
      activate(key);
    });
    activate(key);
    const [c, r] = s.vt.fit(screens);
    s.vt.resize(c, r);
    s.vt.write('\x1b[2m' + T('Verbinde mit {t} …', { t: s.title }) + '\x1b[0m\r\n');
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    const res = await post(urls.open, { ...data, cols: s.vt.cols, rows: s.vt.rows }).catch((e) => ({ error: String(e) }));
    btn.disabled = false;
    if (res.error || !res.sid) {
      s.vt.write('\x1b[31m' + (res.error || T('Fehler')) + '\x1b[0m\r\n');
      s.endText = res.error || T('Fehler');
      tab.classList.add('ended');
      setState();
      return;
    }
    s.sid = res.sid;
    s.open = true;
    remember({ host: hv, target: data.target || '', user: data.user, port: data.port, auth: data.auth });
    setState();
    s.vt.focus();
    readLoop(s);
  }

  function syncFree() {
    const free = hostSel.value === '@free';
    freeBox.hidden = !free;
  }

  // Host-Auswahl: Benutzer/Port des Eintrags übernehmen
  let autoUser = userIn.value;
  hostSel.addEventListener('change', () => {
    syncFree();
    const o = hostSel.selectedOptions[0];
    if (!o || !o.dataset.port) return;
    if (userIn.value === autoUser || userIn.value === '') { userIn.value = o.dataset.user || box.dataset.defaultUser || 'root'; autoUser = userIn.value; }
    portIn.value = o.dataset.port || 22;
  });
  // Host suchen
  $('#termHostFilter')?.addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    let first = null;
    $$('option', hostSel).forEach((o) => {
      if (!o.value || o.value === '@free') return;
      const hit = !q || o.textContent.toLowerCase().includes(q) || (o.parentElement?.label || '').toLowerCase().includes(q);
      o.hidden = !hit;
      o.disabled = !hit;
      if (hit && !first) first = o;
    });
    $$('optgroup', hostSel).forEach((g) => { g.hidden = !$$('option', g).some((o) => !o.hidden); });
    if (first && (hostSel.selectedOptions[0]?.hidden || !hostSel.value)) { hostSel.value = first.value; hostSel.dispatchEvent(new Event('change')); }
  });
  $('#termHostFilter')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); connect(); } });
  // Benutzerliste um zuletzt benutzte ergänzen
  const dl = $('#termUsers');
  const known = new Set($$('option', dl).map((o) => o.value));
  (store.get('np_term_recent', []) || []).forEach((x) => { if (x.user && !known.has(x.user)) { const o = document.createElement('option'); o.value = x.user; dl.append(o); known.add(x.user); } });

  form.addEventListener('submit', (e) => { e.preventDefault(); connect(); });
  $('#termDisconnect').addEventListener('click', () => { const s = sessions.get(active); if (s) closeSession(s, false); });
  $('#termFull').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else if (panel.requestFullscreen) panel.requestFullscreen().catch(() => panel.classList.toggle('term-max'));
    else panel.classList.toggle('term-max');
  });
  $('#termPopout')?.addEventListener('click', () => {
    const u = new URL(location.href);
    u.searchParams.set('p', 'terminal');
    u.searchParams.set('popup', '1');
    const hv = hostSel.value;
    if (hv && hv !== '@free') { const [n, id] = hv.split(':'); u.searchParams.set('n', n); u.searchParams.set('id', id); u.searchParams.set('auto', '1'); }
    window.open(u.toString(), 'np-term-' + Date.now(), 'width=1100,height=720');
  });
  const font = (d) => {
    fontSize = Math.max(10, Math.min(24, fontSize + d));
    store.set('np_term_font', fontSize);
    panel.style.setProperty('--term-font', fontSize + 'px');
    for (const s of sessions.values()) s.vt.styleCache.clear();
    refit(sessions.get(active));
  };
  $('#termFontUp').addEventListener('click', () => font(1));
  $('#termFontDown').addEventListener('click', () => font(-1));
  $('#termPaste').addEventListener('click', async () => {
    const s = sessions.get(active);
    if (!s) return;
    try { s.vt.paste(await navigator.clipboard.readText()); } catch (e) { s.vt.focus(); }
    s.vt.focus();
  });
  if (window.ResizeObserver) new ResizeObserver(() => refit(sessions.get(active))).observe(screens);
  document.addEventListener('fullscreenchange', () => setTimeout(() => refit(sessions.get(active)), 50));
  window.addEventListener('pagehide', () => { for (const s of sessions.values()) if (s.open && s.sid) post(urls.close, { sid: s.sid }, true).catch(() => {}); });

  syncFree();
  renderRecent();
  if (box.dataset.auto === '1' && hostSel.value) connect();
})();
