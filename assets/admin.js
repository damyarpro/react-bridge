/* React Bridge admin screen. Vanilla JS, no dependencies. Server-side validation stays authoritative;
   everything here is progressive enhancement (tabs, hints, previews, copy, tester, onboarding).
   Every user facing string comes from RB.i18n with an English fallback, so a missing key never breaks. */
(function () {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];
  const app = $('#rb-app');
  if (!app || typeof window.RB === 'undefined') return;
  const RB = window.RB;

  /* ---------- i18n ---------- */
  const I18N = RB.i18n && typeof RB.i18n === 'object' ? RB.i18n : {};
  const t = (key, fallback) => {
    const v = I18N[key];
    return typeof v === 'string' && v !== '' ? v : (fallback === undefined ? '' : fallback);
  };
  // sprintf-lite: supports %s (sequential) and %1$s (positional).
  function fmt(str) {
    const args = [].slice.call(arguments, 1);
    let seq = 0;
    return String(str).replace(/%(\d+\$)?s/g, (m, pos) => {
      const i = pos ? parseInt(pos, 10) - 1 : seq++;
      const v = args[i];
      return v === undefined || v === null ? '' : String(v);
    });
  }

  /* ---------- Direction and numbers ---------- */
  const docEl = document.documentElement;
  const rtl = docEl.dir === 'rtl' || app.getAttribute('dir') === 'rtl' || RB.rtl === true;
  const isFa = String(docEl.lang || '').toLowerCase().indexOf('fa') === 0;
  const num = n => (isFa ? Number(n).toLocaleString('fa-IR') : Number(n).toLocaleString());
  const listSep = t('listSep', ', ');   // translatable: RTL locales use their own comma

  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const INVIS = new RegExp('[\\u200B-\\u200F\\u202A-\\u202E\\u2060-\\u2064\\u2066-\\u2069\\uFEFF]', 'g');
  const clean = v => String(v || '').replace(INVIS, '').trim();
  const headers = () => Object.assign({ Accept: 'application/json' }, RB.require && RB.key ? { 'X-RB-Key': RB.key } : {});
  const restHeaders = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-WP-Nonce': RB.restNonce });
  const scrollTo = e => e && e.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
  const sget = k => { try { return sessionStorage.getItem(k); } catch (_) { return null; } };
  const sset = (k, v) => { try { sessionStorage.setItem(k, v); } catch (_) { /* private mode */ } };
  const sdel = k => { try { sessionStorage.removeItem(k); } catch (_) { /* private mode */ } };

  // Screen-reader announcements for transient feedback (copy, flush, regen).
  const live = document.createElement('div');
  live.className = 'screen-reader-text';
  live.setAttribute('aria-live', 'polite');
  app.appendChild(live);
  const say = text => { live.textContent = ''; setTimeout(() => { live.textContent = text; }, 60); };

  const el = (tag, cls, text) => {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  };

  // admin-ajax POST with the shared nonce; never throws on a non-JSON body.
  async function ajax(action, extra) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_ajax_nonce', RB.nonce);
    Object.keys(extra || {}).forEach(k => fd.append(k, extra[k]));
    const r = await fetch(RB.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
    const body = await r.json().catch(() => ({}));
    return { ok: r.ok && !!body.success, status: r.status, data: body.data || {} };
  }

  /* ---------- Tabs ---------- */
  const tabs = $$('.rb-tab'), panels = $$('.rb-panel'), tablist = $('.rb-tabs');
  const tabIds = tabs.map(t2 => t2.dataset.tab);
  if (tablist) tablist.setAttribute('aria-orientation', 'horizontal');
  const panelOf = id => panels.filter(p => p.id === 'rb-' + id)[0] || null;

  function activate(id, focusTab) {
    if (!tabIds.includes(id)) id = tabIds.includes('connect') ? 'connect' : (tabIds[0] || 'connect');
    tabs.forEach(tab => {
      const on = tab.dataset.tab === id;
      tab.setAttribute('aria-selected', String(on));
      tab.tabIndex = on ? 0 : -1;
      if (on && focusTab) tab.focus();
    });
    panels.forEach(p => { p.hidden = p.id !== 'rb-' + id; });
    app.dataset.tab = id;
    const active = panelOf(id);
    if (active && !reduce) {
      active.classList.remove('is-entering');
      void active.offsetWidth;            // restart the panel entry animation
      active.classList.add('is-entering');
      clearTimeout(active._rbEnter);
      active._rbEnter = setTimeout(() => active.classList.remove('is-entering'), 260);
    }
    if (location.hash !== '#' + id) history.replaceState(null, '', '#' + id);
  }
  tabs.forEach(tab => tab.addEventListener('click', () => activate(tab.dataset.tab)));
  if (tablist) tablist.addEventListener('keydown', e => {
    const i = tabs.indexOf(document.activeElement);
    if (i < 0) return;
    const nextKey = rtl ? 'ArrowLeft' : 'ArrowRight';   // the next tab sits towards the reading end
    const prevKey = rtl ? 'ArrowRight' : 'ArrowLeft';
    let n = null;
    if (e.key === nextKey) n = (i + 1) % tabs.length;
    else if (e.key === prevKey) n = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = tabs.length - 1;
    if (n === null) return;
    e.preventDefault();
    activate(tabs[n].dataset.tab, true);
  });
  window.addEventListener('hashchange', () => {
    const id = location.hash.slice(1);
    if (tabIds.includes(id)) activate(id);
  });

  // After a rejected save, open the tab holding the first invalid field and focus it.
  const firstInvalid = $('.rb-panel [aria-invalid="true"]');
  if (firstInvalid && firstInvalid.closest('.rb-panel')) {
    activate(firstInvalid.closest('.rb-panel').id.slice(3));
    firstInvalid.focus();
  } else {
    activate(location.hash.slice(1) || 'connect');
  }

  // Optional jump-to-field controls.
  $$('[data-goto]').forEach(b => b.addEventListener('click', () => {
    const field = document.getElementById(b.dataset.goto);
    if (!field || !field.closest('.rb-panel')) return;
    activate(field.closest('.rb-panel').id.slice(3));
    const group = field.closest('details.rb-group');
    if (group) group.open = true;
    field.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    field.focus({ preventScroll: true });
  }));

  /* ---------- Advanced groups: open on error, remember the rest ---------- */
  $$('details.rb-group').forEach(g => {
    const key = g.id ? 'rb-group-' + g.id : '';
    const bad = !!(g.querySelector('[aria-invalid="true"]') || g.querySelector('.rb-err'));
    if (bad) g.open = true;
    else if (key) g.open = sget(key) === '1';
    if (key) g.addEventListener('toggle', () => sset(key, g.open ? '1' : '0'));
  });

  /* ---------- Copy ---------- */
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (_) {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
      document.body.appendChild(ta);
      ta.select();
      let ok = false;
      try { ok = document.execCommand('copy'); } catch (__) { ok = false; }
      ta.remove();
      return ok;
    }
  }
  function flash(btn, ok) {
    const label = btn.querySelector('.rb-btn-label') || (btn.classList.contains('rb-copy') ? btn : null);
    const msg = ok ? t('copied', 'Copied') : t('copyFailed', 'Copy failed');
    btn.classList.toggle('is-done', ok);
    btn.classList.toggle('is-failed', !ok);
    if (label) {
      if (!label.dataset.orig) label.dataset.orig = label.textContent;
      label.textContent = msg;
    }
    say(msg);
    clearTimeout(btn._rbTimer);
    btn._rbTimer = setTimeout(() => {
      btn.classList.remove('is-done', 'is-failed');
      if (label) label.textContent = label.dataset.orig;
    }, 1600);
  }
  app.addEventListener('click', async e => {
    const b = e.target.closest('[data-copy], [data-copy-from], .rb-copy, #rb-url-copy');
    if (!b || !app.contains(b)) return;
    let text = '';
    if (b.dataset.copy !== undefined) text = b.dataset.copy;
    else if (b.dataset.copyFrom) text = (document.getElementById(b.dataset.copyFrom) || {}).value || '';
    else if (b.id === 'rb-url-copy') text = ($('#rb-url') || {}).textContent || '';
    else {
      const holder = b.closest('.rb-code'), pre = holder && holder.querySelector('pre');
      text = pre ? pre.textContent : '';
    }
    if (text) flash(b, await copyText(text));
  });

  /* ---------- Reveal masked values (API key, webhook secret) ---------- */
  $$('[data-reveal]').forEach(b => b.addEventListener('click', () => {
    const input = document.getElementById(b.dataset.reveal);
    if (!input) return;
    const shown = !input.classList.toggle('rb-masked');
    b.setAttribute('aria-pressed', String(shown));
    const label = b.querySelector('.rb-btn-label');
    if (label) label.textContent = shown ? t('hide', 'Hide') : t('show', 'Show');
    const icon = b.querySelector('.dashicons');
    if (icon) {
      icon.classList.toggle('dashicons-visibility', !shown);
      icon.classList.toggle('dashicons-hidden', shown);
    }
  }));

  /* ---------- Live status ---------- */
  const status = $('.rb-header .rb-status') || $('div#rb-status.rb-status');
  const setStatus = (state, text, icon) => {
    if (!status) return;
    status.dataset.state = state;
    const txt = $('.rb-status-text', status), i = $('.dashicons', status);
    if (txt) txt.textContent = text;
    if (i) i.className = 'dashicons ' + icon;
  };
  fetch(RB.base + '/health', { headers: headers(), credentials: 'same-origin' })
    .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
    .then(d => setStatus('ok', fmt(t('apiActive', 'API active, version %s'), d.version), 'dashicons-yes-alt'))
    .catch(e => setStatus(
      'bad',
      fmt(t('apiDown', 'API not responding (%s)'), typeof e === 'number' ? num(e) : t('networkError', 'Network error')),
      'dashicons-warning'
    ));

  /* ---------- Field hints (advisory; mirrors RB_Settings rules) ---------- */
  const feInput = $('#rb-frontend-url'), pathInput = $('#rb-blog-path'), hookInput = $('#rb-webhook');
  const PATH_RE = /^(\/[^\s/?#]+)+$/;
  const apiRoot = String(RB.base || '').replace(/\/+$/, '');
  const SAMPLE_ORIGIN = 'https://example.com';

  function hint(input, msg) {
    const h = $('[data-hint-for="' + input.id + '"]');
    if (!h) return;
    h.textContent = msg || '';
    h.hidden = !msg;
  }
  function httpProblem(v) {
    if (!/^https?:\/\//i.test(v)) return t('mustHttp', 'The address must start with http:// or https://.');
    if (/\s/.test(v)) return t('noSpaces', 'The address must not contain spaces.');
    let u;
    try { u = new URL(v); } catch (_) { return t('invalidUrl', 'This address is not valid.'); }
    if (u.username || u.password) return t('noUserinfo', 'The address must not contain a user name or password.');
    return '';
  }
  function frontendProblem(raw) {
    const v = clean(raw);
    if (!v) return '';
    const p = httpProblem(v);
    if (p) return p;
    if (/[?#]/.test(v)) return t('noQueryHash', 'The frontend address must not contain ? or #.');
    if (v.endsWith('/')) return t('noTrailingSlash', 'The frontend address must not end with a slash.');
    if (/\/wp-json(\/|$)/i.test(v) || (apiRoot && v.indexOf(apiRoot) === 0)) {
      return t('isWordpressApi', 'This is the WordPress API address. Enter your frontend address here, for example https://example.com');
    }
    return '';
  }
  const pathProblem = raw => {
    const v = clean(raw);
    return !v || PATH_RE.test(v)
      ? ''
      : t('badPath', 'The path must look like /blog: it starts with a slash and has no trailing slash, spaces, ? or #.');
  };
  const hookProblem = raw => {
    const v = clean(raw);
    return v ? httpProblem(v) : '';
  };
  const validFrontend = () => {
    if (!feInput) return '';
    const v = clean(feInput.value);
    return v && !frontendProblem(v) ? v : '';
  };
  const validPath = () => {
    const v = pathInput ? clean(pathInput.value) : '';
    return PATH_RE.test(v) ? v : '/blog';
  };
  const originOf = v => { try { const u = new URL(v); return /^https?:$/.test(u.protocol) ? u.origin : ''; } catch (_) { return ''; } };
  const httpUrl = v => {
    const s = clean(v);
    if (!s) return '';
    try { return /^https?:$/.test(new URL(s).protocol) ? s : ''; } catch (_) { return ''; }
  };

  function watch(input, check, onChange) {
    if (!input) return null;
    const run = () => hint(input, check(input.value));
    input.addEventListener('blur', run);
    input.addEventListener('input', () => {
      const h = $('[data-hint-for="' + input.id + '"]');
      if (h && !h.hidden) run();
      if (onChange) onChange();
    });
    return run;
  }

  /* ---------- Blog path preview ---------- */
  const preview = $('#rb-path-preview');
  const updatePreview = () => {
    if (preview) preview.textContent = (validFrontend() || SAMPLE_ORIGIN) + validPath() + '/hello';
  };

  /* ---------- Auto line + optional admin panel links ---------- */
  const autoline = $('#rb-autoline');
  const panelHref = httpUrl(RB.panelUrl);      // configured in the settings, no longer derived from the frontend URL
  // The first two ids are the v1.2.0 markup ids kept for compatibility; the last two are the generic names.
  const PANEL_LINKS = ['#rb-open-panel', '#rb-ob-panel'];
  function applyPanelLinks() {
    PANEL_LINKS.forEach(sel => {
      const a = $(sel);
      if (!a) return;
      if (panelHref) {
        a.href = panelHref;
        a.hidden = false;
        a.removeAttribute('aria-disabled');
      } else {
        a.removeAttribute('href');
        a.hidden = true;
        a.setAttribute('aria-disabled', 'true');
      }
    });
  }
  function updateAuto() {
    const fe = validFrontend(), origin = fe ? originOf(fe) : '';
    if (autoline) {
      autoline.textContent = origin ? fmt(t('originAuto', 'Origin %s is allowed automatically'), origin) : '';
      autoline.hidden = !origin;
    }
  }

  /* ---------- CORS origin chips ---------- */
  const cors = $('#rb-cors'), chips = $('#rb-origin-chips');
  function originProblem(o) {
    if (o === '*' || o.includes('*')) return t('starNotAllowed', '* is not allowed');
    if (!/^https?:\/\//.test(o)) return t('onlyHttp', 'Only http or https');
    if (/^https?:\/\/[^/]*\/./.test(o) || o.endsWith('/')) return t('noPathInOrigin', 'A path or trailing slash is not allowed');
    if (!/^https?:\/\/(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)(:\d{1,5})?$/.test(o)) return t('badOrigin', 'Invalid format');
    return '';
  }
  function chip(text, cls, note, tag) {
    const li = document.createElement('li');
    li.className = 'rb-chip' + (cls ? ' ' + cls : '');
    li.appendChild(document.createTextNode(text));
    if (tag) {
      const tagEl = document.createElement('span');
      tagEl.className = 'rb-chip-tag';
      tagEl.textContent = tag;
      li.appendChild(tagEl);
    }
    if (note) {
      li.title = note;
      const sr = document.createElement('span');
      sr.className = 'screen-reader-text';
      sr.textContent = listSep + note;
      li.appendChild(sr);
    }
    chips.appendChild(li);
  }
  function renderChips() {
    if (!cors || !chips) return;
    chips.textContent = '';
    const seen = new Set();
    const fe = validFrontend();
    const feOrigin = fe ? originOf(fe) : '';
    if (feOrigin) {
      seen.add(feOrigin);
      chip(feOrigin, 'is-auto', t('autoFromFrontend', 'Added automatically from the frontend address'), t('auto', 'automatic'));
    }
    const items = cors.value.split(/[\r\n,]+/).map(clean).filter(Boolean);
    items.forEach(raw => {
      const o = raw.toLowerCase();
      const p = originProblem(o);
      if (p) { chip(raw, 'is-bad', p, p); return; }
      const norm = originOf(o);
      if (seen.has(norm)) { chip(norm, 'is-dup', t('duplicate', 'Duplicate'), t('duplicate', 'Duplicate')); return; }
      seen.add(norm);
      chip(norm, '', '');
    });
    if (items.length > 50) {
      const tooMany = t('tooManyOrigins', 'At most 50 origins are allowed');
      chip(tooMany, 'is-bad', tooMany);
    }
    chips.hidden = !chips.children.length;
  }

  /* ---------- TTL presets ---------- */
  const ttl = $('#rb-ttl'), segs = $$('.rb-seg-btn');
  const syncTtl = () => {
    const v = String(parseInt(ttl.value, 10));
    segs.forEach(b => b.setAttribute('aria-pressed', String(b.dataset.ttl === v)));
  };
  if (ttl) {
    segs.forEach(b => b.addEventListener('click', () => {
      ttl.value = b.dataset.ttl;
      ttl.dispatchEvent(new Event('input', { bubbles: true }));
    }));
    ttl.addEventListener('input', syncTtl);
    syncTtl();
  }

  /* ---------- SERP preview + counters ---------- */
  const siteName = $('#rb-site-name'), tpl = $('#rb-title-tpl'), desc = $('#rb-desc'), serp = $('.rb-serp');
  function counter(key, n, max) {
    const c = $('[data-counter="' + key + '"]');
    if (!c) return;
    c.removeAttribute('aria-live');
    c.textContent = fmt(t('ofMax', '%1$s of %2$s'), num(n), num(max));
    c.classList.toggle('is-over', n > max);
  }
  function renderSerp() {
    if (!serp || !siteName || !tpl || !desc) return;
    const site = (siteName.value || '').trim() || t('siteName', 'Site name');
    const title = (tpl.value || '%title% | %site%')
      .replace(/%title%/g, t('sampleTitle', 'Sample post title'))
      .replace(/%site%/g, site);
    const d = (desc.value || '').trim();
    const base = (validFrontend() || serp.dataset.home || '').replace(/^https?:\/\//, '');
    const u = $('#rb-serp-url'), ttlEl = $('#rb-serp-title'), dd = $('#rb-serp-desc');
    if (u) u.textContent = base + validPath() + '/sample-post';
    if (ttlEl) ttlEl.textContent = title.length > 60 ? title.slice(0, 59) + '…' : title;
    if (dd) dd.textContent = d
      ? (d.length > 160 ? d.slice(0, 159) + '…' : d)
      : t('sampleDesc', 'The default description is shown here.');
    counter('title', title.length, 60);
    counter('desc', d.length, 160);
  }
  [siteName, tpl, desc].forEach(e => e && e.addEventListener('input', renderSerp));

  const refreshAll = () => { updatePreview(); updateAuto(); renderChips(); renderSerp(); };
  const checkFrontend = watch(feInput, frontendProblem, refreshAll);
  watch(pathInput, pathProblem, refreshAll);
  watch(hookInput, hookProblem);
  if (cors) cors.addEventListener('input', renderChips);
  applyPanelLinks();
  refreshAll();
  if (checkFrontend && feInput && clean(feInput.value)) checkFrontend(); // surface a misconfigured saved value right away

  /* ---------- Dirty state + save ---------- */
  const form = $('#rb-form'), saveState = $('#rb-save-state'), submit = $('#rb-submit');
  // api_key is written by the regenerate action, not by this form's Save, so it is not part of "dirty".
  const snapshot = () => {
    if (!form) return '';
    const fd = new FormData(form);
    fd.delete('rb_settings[api_key]');
    return new URLSearchParams(fd).toString();
  };
  let baseline = snapshot();
  let dirty = false, submitting = false;
  const markDirty = () => {
    const d = snapshot() !== baseline;
    if (d === dirty) return;
    dirty = d;
    if (!saveState) return;
    saveState.dataset.dirty = String(d);
    saveState.textContent = d
      ? t('unsavedChanges', 'You have unsaved changes.')
      : t('allSaved', 'All changes are saved.');
  };
  // The onboarding saves through the REST route, so the form is clean again afterwards.
  const resetBaseline = () => { baseline = snapshot(); dirty = true; markDirty(); };
  if (form) {
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', e => {
      if (submitting) { e.preventDefault(); return; }
      submitting = true;
      if (submit) {
        submit.value = t('saving', 'Saving…');
        submit.setAttribute('aria-disabled', 'true');
      }
    });
  }
  window.addEventListener('beforeunload', e => {
    if (!dirty || submitting) return;
    e.preventDefault();
    e.returnValue = '';
  });

  /* ---------- Flush cache ---------- */
  const flushBtn = $('#rb-flush');
  if (flushBtn) flushBtn.addEventListener('click', async () => {
    const label = $('.rb-btn-label', flushBtn) || flushBtn;
    const orig = label.textContent || t('flushCache', 'Clear cache');
    flushBtn.disabled = true;
    label.textContent = t('flushing', 'Clearing…');
    let ok = false;
    try { ok = (await ajax('rb_flush')).ok; } catch (_) { ok = false; }
    label.textContent = ok ? t('cacheCleared', 'Cache cleared') : t('flushFailed', 'Could not clear, try again');
    flushBtn.dataset.result = ok ? 'ok' : 'bad';
    say(label.textContent);
    setTimeout(() => {
      label.textContent = orig;
      flushBtn.disabled = false;
      delete flushBtn.dataset.result;
    }, 2200);
  });

  /* ---------- Regenerate API key ---------- */
  const regen = $('#rb-regen'), keyInput = $('#rb-key');
  if (regen) regen.addEventListener('click', async () => {
    if (!window.confirm(t('confirmRegen', 'The current key stops working and the frontend needs the new one. Continue?'))) return;
    const label = $('.rb-btn-label', regen) || regen, orig = label.textContent;
    regen.disabled = true;
    label.textContent = t('generating', 'Generating…');
    try {
      const r = await ajax('rb_regen_key');
      if (!r.ok || !r.data.key) throw new Error('failed');
      if (keyInput) keyInput.value = r.data.key;
      RB.key = r.data.key;
      regen.dataset.result = 'ok';
      say(t('keyCreated', 'A new key was created.'));
    } catch (_) {
      regen.dataset.result = 'bad';
      say(t('keyFailed', 'Could not create a new key.'));
    }
    label.textContent = orig;
    regen.disabled = false;
    setTimeout(() => { delete regen.dataset.result; }, 2200);
  });

  /* ---------- Live tester ---------- */
  const run = $('#rb-run'), ep = $('#rb-ep'), q = $('#rb-q'), out = $('#rb-out'), urlEl = $('#rb-url'), result = $('#rb-result');
  const pill = $('#rb-res-status'), tEl = $('#rb-res-time'), sEl = $('#rb-res-size'), cEl = $('#rb-res-type');
  const fmtSize = b => (b < 1024
    ? num(b) + ' ' + t('unitBytes', 'B')
    : num(Number((b / 1024).toFixed(1))) + ' ' + t('unitKb', 'KB'));
  async function runTest() {
    const path = ep.value, params = q.value.trim().replace(/^\?/, '');
    const url = RB.base + path + (params ? (path.includes('?') ? '&' : '?') + params : '');
    const label = $('.rb-btn-label', run) || run;
    urlEl.textContent = url;
    result.dataset.state = 'loading';
    run.disabled = true;
    label.textContent = t('running', 'Running…');
    pill.textContent = tEl.textContent = sEl.textContent = cEl.textContent = '';
    out.textContent = '';
    const t0 = performance.now();
    try {
      const r = await fetch(url, { headers: headers(), credentials: 'same-origin' });
      const text = await r.text();
      const ms = Math.round(performance.now() - t0);
      const ct = (r.headers.get('content-type') || '').split(';')[0].trim();
      let body = text;
      if (ct.includes('json')) { try { body = JSON.stringify(JSON.parse(text), null, 2); } catch (_) { body = text; } }
      pill.dataset.kind = r.status >= 500 ? 'bad' : r.status >= 400 ? 'warn' : r.status >= 300 ? 'neutral' : 'ok';
      pill.textContent = 'HTTP ' + r.status;
      tEl.textContent = num(ms) + ' ' + t('unitMs', 'ms');
      sEl.textContent = fmtSize(new TextEncoder().encode(text).length);
      cEl.textContent = ct;
      out.textContent = body || t('noBody', 'The response has no body.');
    } catch (e) {
      pill.dataset.kind = 'bad';
      pill.textContent = t('networkError', 'Network error');
      out.textContent = e && e.message ? e.message : String(e);
    }
    result.dataset.state = 'done';
    run.disabled = false;
    label.textContent = t('run', 'Run');
  }
  if (run && ep && q && out && urlEl && result) {
    run.addEventListener('click', runTest);
    q.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); runTest(); } });
    $$('[data-try]').forEach(b => b.addEventListener('click', () => {
      const v = b.dataset.try;
      if ([...ep.options].some(o => o.value === v)) ep.value = v;
      scrollTo($('#rb-tester'));
      run.focus({ preventScroll: true });
      runTest();
    }));
  }

  /* ---------- Docs: live values + table of contents ---------- */
  $$('[data-fill]').forEach(e => {
    e.textContent = e.textContent.replace(/__BASE__/g, RB.base).replace(/__KEY__/g, RB.key || '');
  });
  const tocLinks = $$('.rb-toc a');
  tocLinks.forEach(a => a.addEventListener('click', e => {
    const target = document.getElementById(a.getAttribute('href').slice(1));
    if (!target) return;
    e.preventDefault();
    target.setAttribute('tabindex', '-1');
    scrollTo(target);
    target.focus({ preventScroll: true });
  }));
  if ('IntersectionObserver' in window && tocLinks.length) {
    const byId = new Map(tocLinks.map(a => [a.getAttribute('href').slice(1), a]));
    const io = new IntersectionObserver(entries => {
      entries.forEach(en => {
        if (!en.isIntersecting) return;
        tocLinks.forEach(a => a.removeAttribute('aria-current'));
        const a = byId.get(en.target.id);
        if (a) a.setAttribute('aria-current', 'true');
      });
    }, { rootMargin: '-80px 0px -70% 0px' });
    byId.forEach((a, id) => { const h = document.getElementById(id); if (h) io.observe(h); });
  }

  /* ---------- Transfer settings to / from an external admin panel ---------- */
  const DEFAULT_LABELS = {
    frontend_url: 'Frontend URL',
    blog_path: 'Blog path',
    redirect_to_frontend: '301 redirect to the frontend',
    cors_origins: 'Allowed origins (CORS)',
    cache_ttl_seconds: 'Cache time (seconds)',
    posts_per_page: 'Posts per page',
    revalidate_webhook_url: 'Revalidation webhook URL',
    panel_name: 'Panel name',
    panel_url: 'Panel URL',
  };
  const I18N_LABELS = I18N.labels && typeof I18N.labels === 'object' ? I18N.labels : {};
  const labelOf = k => {
    const v = I18N_LABELS[k];
    return typeof v === 'string' && v !== '' ? v : (DEFAULT_LABELS[k] || k);
  };
  // The REST contract stays the seven generic keys; panel_name / panel_url are local settings only.
  const CONTRACT = [
    'frontend_url',
    'blog_path',
    'redirect_to_frontend',
    'cors_origins',
    'cache_ttl_seconds',
    'posts_per_page',
    'revalidate_webhook_url',
  ];
  const exportEl = $('#rb-export-data');
  let exported = null;
  if (exportEl) { try { exported = JSON.parse(exportEl.textContent); } catch (_) { exported = null; } }
  const note = $('#rb-transfer-note');
  const showNote = msg => { if (note) { note.textContent = msg || ''; note.hidden = !msg; } };

  // The payload an external panel expects: the contract keys plus the WordPress connection block.
  function buildExport(contract) {
    const o = {};
    CONTRACT.forEach(k => {
      if (contract && contract[k] !== undefined) o[k] = contract[k];
      else if (exported && exported[k] !== undefined) o[k] = exported[k];
      else o[k] = '';
    });
    o.wordpress_enabled = true;
    o.wordpress_api_url = RB.base;
    o.wordpress_api_key = RB.require ? (RB.key || '') : '';
    return o;
  }
  async function fetchSettings() {
    const r = await fetch(RB.settingsUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-WP-Nonce': RB.restNonce },
    });
    if (!r.ok) throw new Error('http ' + r.status);
    const d = await r.json();
    return d && typeof d === 'object' && d.settings && typeof d.settings === 'object' ? d.settings : d;
  }
  // After a successful PATCH the rendered export is stale; rebuild it from the saved settings.
  async function refreshExport() {
    try {
      exported = buildExport(await fetchSettings());
      const pre = $('#rb-export-view');
      if (pre) pre.textContent = JSON.stringify(exported, null, 2);
      if (exportEl) exportEl.textContent = JSON.stringify(exported);
      return true;
    } catch (_) {
      return false;
    }
  }
  async function copyExport(btn) {
    const ok = await copyText(JSON.stringify(exported || buildExport(null), null, 2));
    flash(btn, ok);
    return ok;
  }

  // Copy: always the saved settings, pretty-printed JSON.
  const exportBtn = $('#rb-export-copy');
  if (exportBtn) exportBtn.addEventListener('click', async () => {
    const ok = await copyExport(exportBtn);
    if (!ok) {
      // Clipboard blocked: open the text and select it so Ctrl+C works.
      const details = $('.rb-details'), pre = $('#rb-export-view');
      if (details) details.open = true;
      if (pre) {
        const range = document.createRange();
        range.selectNodeContents(pre);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      }
      showNote(t('clipboardBlocked', 'The browser blocked automatic copying. The text is selected, press Ctrl+C.'));
    } else {
      showNote(dirty ? t('unsavedNotInCopy', 'Unsaved changes on this page are not in the copied text. Save first, then copy again.') : '');
    }
  });

  // Paste: read the clipboard when the browser allows it, otherwise let the user paste into the box.
  const importBox = $('#rb-import'), importText = $('#rb-import-text'), importPreview = $('#rb-import-preview');
  const openBtn = $('#rb-import-open'), applyBtn = $('#rb-import-apply'), cancelBtn = $('#rb-import-cancel');
  let pending = null;

  const fmtVal = v => {
    if (Array.isArray(v)) return v.length ? v.join(listSep) : t('empty', 'empty');
    if (typeof v === 'boolean') return v ? t('on', 'on') : t('off', 'off');
    if (v === '' || v === null || v === undefined) return t('empty', 'empty');
    return String(v);
  };
  // Friendly normalisation before the strict server check: strip invisible marks, "300" to 300, "true" to true.
  const normalise = (key, v) => {
    if (typeof v === 'string') v = clean(v);
    if (Array.isArray(v)) return v.map(x => (typeof x === 'string' ? clean(x) : x)).filter(x => x !== '');
    if ((key === 'cache_ttl_seconds' || key === 'posts_per_page') && typeof v === 'string' && /^\d+$/.test(v)) return parseInt(v, 10);
    if (key === 'redirect_to_frontend' && (v === 'true' || v === 'false')) return v === 'true';
    if (key === 'cors_origins' && typeof v === 'string') return v.split(/[\r\n,]+/).map(clean).filter(Boolean);
    return v;
  };
  function parseImport(text) {
    let obj;
    try { obj = JSON.parse(clean(text)); } catch (_) {
      return { error: t('invalidJson', 'This text is not valid JSON. Paste the full text copied from your panel.') };
    }
    if (obj && typeof obj === 'object' && !Array.isArray(obj) && obj.settings && typeof obj.settings === 'object') obj = obj.settings;
    if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return { error: t('notObject', 'The text must be a JSON object.') };
    const current = exported || buildExport(null);
    const changes = {}, ignored = [];
    Object.keys(obj).forEach(k => {
      if (!CONTRACT.includes(k)) { if (!/^wordpress_/.test(k)) ignored.push(k); return; }
      const v = normalise(k, obj[k]);
      if (JSON.stringify(v) !== JSON.stringify(current[k])) changes[k] = v;
    });
    return { changes, ignored };
  }
  function renderPreview(res) {
    importPreview.textContent = '';
    pending = null;
    applyBtn.disabled = true;
    if (res.error) { importPreview.append(el('p', 'rb-err', res.error)); return; }
    const current = exported || buildExport(null);
    const keys = Object.keys(res.changes);
    if (!keys.length) {
      importPreview.append(el('p', 'rb-hint', t('nothingToChange', 'Every value already matches the current settings. Nothing to change.')));
    } else {
      importPreview.append(el('p', 'rb-diff-title', fmt(t('changesCount', '%s settings will change:'), num(keys.length))));
      const ul = el('ul', 'rb-diff');
      keys.forEach(k => {
        const li = el('li');
        li.append(el('span', 'rb-diff-key', labelOf(k)));
        const vals = el('span', 'rb-diff-vals');
        vals.append(el('code', 'rb-diff-old', fmtVal(current[k])));
        const arrow = el('span', 'dashicons ' + (rtl ? 'dashicons-arrow-left-alt' : 'dashicons-arrow-right-alt'));
        arrow.setAttribute('aria-label', t('arrowTo', 'to'));
        vals.append(arrow, el('code', 'rb-diff-new', fmtVal(res.changes[k])));
        li.append(vals);
        ul.append(li);
      });
      importPreview.append(ul);
      pending = res.changes;
      applyBtn.disabled = false;
    }
    if (res.ignored.length) {
      importPreview.append(el('p', 'rb-hint', fmt(t('ignored', 'Ignored: %s'), res.ignored.join(listSep))));
    }
  }
  const closeImport = () => {
    importBox.hidden = true;
    openBtn.setAttribute('aria-expanded', 'false');
    importText.value = '';
    importPreview.textContent = '';
    pending = null;
    applyBtn.disabled = true;
    openBtn.focus();
  };
  async function openImport(focusIt) {
    if (!importBox || !importText || !importPreview || !openBtn) return;
    importBox.hidden = false;
    openBtn.setAttribute('aria-expanded', 'true');
    let text = '';
    try { if (navigator.clipboard && navigator.clipboard.readText) text = await navigator.clipboard.readText(); } catch (_) { text = ''; }
    if (text && clean(text).startsWith('{')) {
      importText.value = text;
      renderPreview(parseImport(text));
    } else {
      importPreview.textContent = '';
      importPreview.append(el('p', 'rb-hint', t('pasteHere', 'Paste the text copied from your panel into the box above with Ctrl+V.')));
    }
    if (focusIt !== false) importText.focus();
  }
  if (openBtn && importBox && importText && importPreview && applyBtn && cancelBtn) {
    openBtn.addEventListener('click', () => openImport(true));
    importText.addEventListener('input', () => renderPreview(parseImport(importText.value)));
    cancelBtn.addEventListener('click', closeImport);
    applyBtn.addEventListener('click', async () => {
      if (!pending) return;
      if (dirty && !window.confirm(t('confirmDiscard', 'Unsaved changes on this page will be lost. Continue?'))) return;
      const label = $('.rb-btn-label', applyBtn) || applyBtn;
      applyBtn.disabled = true;
      label.textContent = t('applying', 'Applying…');
      try {
        const r = await fetch(RB.settingsUrl, {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: restHeaders(),
          body: JSON.stringify(pending),
        });
        const data = await r.json().catch(() => ({}));
        if (r.ok) {
          submitting = true; // no "unsaved changes" prompt on the reload
          sset('rb-imported', String(Object.keys(pending).length));
          location.reload();
          return;
        }
        importPreview.textContent = '';
        importPreview.append(el('p', 'rb-err',
          (data && data.message) || fmt(t('applyFailed', 'Could not apply settings (code %s).'), num(r.status))));
        const errs = (data && data.data && data.data.errors) || {};
        const ul = el('ul', 'rb-diff');
        Object.keys(errs).forEach(k => ul.append(el('li', 'rb-err-item', labelOf(k) + ': ' + errs[k])));
        if (ul.children.length) importPreview.append(ul);
      } catch (e) {
        importPreview.textContent = '';
        importPreview.append(el('p', 'rb-err', t('connectionFailed', 'Could not connect. Try again.')));
      }
      applyBtn.disabled = false;
      label.textContent = t('apply', 'Apply settings');
    });
  }

  // After a successful paste the page reloads; confirm it here.
  const importedCount = sget('rb-imported');
  if (importedCount) {
    sdel('rb-imported');
    const msg = fmt(t('importedCount', '%s settings imported and saved.'), num(importedCount));
    const box = el('div', 'notice notice-success is-dismissible');
    box.append(el('p', '', msg));
    const holder = $('.rb-notices');
    if (holder) holder.prepend(box);
    say(msg);
  }

  /* ---------- Quick start onboarding ---------- */
  const ob = $('#rb-onboarding');
  if (ob) {
    const obInput = $('#rb-ob-frontend'), obHint = $('#rb-ob-hint-1'), obAuto = $('#rb-ob-auto');
    const obNext1 = $('#rb-ob-next-1'), obPaste = $('#rb-ob-paste'), obCopy = $('#rb-ob-copy');
    const obBack2 = $('#rb-ob-back-2'), obNext2 = $('#rb-ob-next-2');
    const obBack3 = $('#rb-ob-back-3'), obRerun = $('#rb-ob-rerun'), obDone = $('#rb-ob-done');
    const obChecks = $('#rb-ob-checks'), obSteps = $$('.rb-step', ob), obPanels = $$('.rb-step-panel', ob);
    const STEP_KEY = 'rb-ob-step';
    const failedWith = status2 => fmt('%1$s (%2$s)', t('obFailed', 'Failed'), num(status2));

    const obSay = msg => {
      if (!obHint) return;
      obHint.textContent = msg || '';
      obHint.hidden = !msg;
    };
    function autoItem(text, icon) {
      if (!obAuto) return;
      const li = el('li');
      const i = el('span', 'dashicons ' + (icon || 'dashicons-yes'));
      i.setAttribute('aria-hidden', 'true');
      li.append(i, el('span', '', text));
      obAuto.append(li);
    }
    function goStep(n, focusIt) {
      const step = Math.min(3, Math.max(1, parseInt(n, 10) || 1));
      ob.dataset.step = String(step);
      obSteps.forEach(s => {
        if (String(s.dataset.step) === String(step)) s.setAttribute('aria-current', 'step');
        else s.removeAttribute('aria-current');
      });
      obPanels.forEach(p => { p.hidden = String(p.dataset.step) !== String(step); });
      sset(STEP_KEY, String(step));
      if (step === 3) runChecks();
      if (focusIt) {
        const panel = obPanels.filter(p => String(p.dataset.step) === String(step))[0];
        const target = panel && panel.querySelector('input, button, a[href]');
        if (target) target.focus({ preventScroll: true });
      }
    }

    /* Step 1: save the frontend address, then probe it. */
    async function saveFrontend() {
      if (!obInput) return;
      const raw = clean(obInput.value).replace(/\/+$/, '');
      if (!raw) { obSay(t('enterFrontend', 'Enter your frontend address.')); obInput.focus(); return; }
      const problem = frontendProblem(raw);
      if (problem) { obSay(problem); obInput.focus(); return; }
      obInput.value = raw;
      obSay('');
      const label = obNext1 ? ($('.rb-btn-label', obNext1) || obNext1) : null;
      const orig = label ? label.textContent : '';
      if (obNext1) obNext1.disabled = true;
      if (label) label.textContent = t('saving', 'Saving…');
      const path = validPath();
      let saved = false;
      try {
        const r = await fetch(RB.settingsUrl, {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: restHeaders(),
          body: JSON.stringify({ frontend_url: raw, blog_path: path }),
        });
        const data = await r.json().catch(() => ({}));
        if (r.ok) {
          saved = true;
        } else {
          const errs = (data && data.data && data.data.errors) || {};
          obSay(errs.frontend_url || errs.blog_path || (data && data.message)
            || fmt(t('applyFailed', 'Could not apply settings (code %s).'), num(r.status)));
        }
      } catch (_) {
        obSay(t('connectionFailed', 'Could not connect. Try again.'));
      }
      if (obNext1) obNext1.disabled = false;
      if (label) label.textContent = orig;
      if (!saved) return;

      if (feInput) feInput.value = raw;
      if (pathInput) pathInput.value = path;
      refreshAll();
      resetBaseline();
      if (obAuto) obAuto.textContent = '';
      const origin = originOf(raw);
      if (origin) autoItem(fmt(t('obOriginAllowed', 'Origin %s allowed'), origin));
      autoItem(fmt(t('obPathSet', 'Blog path %s set'), path));
      await refreshExport();
      goStep(2, true);
      probeFrontend(raw); // advisory only, never blocks the wizard
    }
    async function probeFrontend(url) {
      try {
        const r = await ajax('rb_probe_frontend', { url: url });
        if (r.ok && r.data.reachable) autoItem(fmt(t('obReachable', 'Site reachable (%s)'), num(r.data.status || 200)));
        else autoItem(t('obUnreachable', 'Site did not respond'), 'dashicons-warning');
      } catch (_) {
        autoItem(t('obUnreachable', 'Site did not respond'), 'dashicons-warning');
      }
    }

    /* Step 3: verify the connection end to end. */
    async function checkHealth() {
      const r = await fetch(RB.base + '/health', { headers: headers(), credentials: 'same-origin' });
      return r.ok
        ? { ok: true, text: t('obApiOk', 'API active') }
        : { ok: false, text: fmt(t('apiDown', 'API not responding (%s)'), num(r.status)) };
    }
    async function checkManifest() {
      const r = await fetch(RB.base + '/manifest', { headers: headers(), credentials: 'same-origin' });
      if (!r.ok) return { ok: false, text: failedWith(r.status) };
      const d = await r.json().catch(() => null);
      const got = d && d.site && d.site.frontend_url ? String(d.site.frontend_url).replace(/\/+$/, '') : '';
      const want = validFrontend().replace(/\/+$/, '');
      if (want && got !== want) return { ok: false, text: t('obManifestBad', 'Frontend URL differs in manifest') };
      return { ok: true, text: t('obManifestOk', 'Settings match') };
    }
    async function checkPosts() {
      const r = await fetch(RB.base + '/posts?per_page=1', { headers: headers(), credentials: 'same-origin' });
      if (!r.ok) return { ok: false, text: failedWith(r.status) };
      const total = r.headers.get('X-RB-Total');
      let n = total === null ? NaN : parseInt(total, 10);
      if (isNaN(n)) {
        const d = await r.json().catch(() => null);
        n = Array.isArray(d) ? d.length : (d && Array.isArray(d.items) ? d.items.length : 0);
      }
      return { ok: true, text: fmt(t('obPosts', '%s posts'), num(n)) };
    }
    async function checkSitemap() {
      const r = await fetch(RB.base + '/sitemap.xml', { credentials: 'same-origin' });
      return r.ok ? { ok: true, text: t('obSitemapOk', 'Sitemap ready') } : { ok: false, text: failedWith(r.status) };
    }
    const CHECKS = { health: checkHealth, manifest: checkManifest, posts: checkPosts, sitemap: checkSitemap };

    function setCheck(li, state, text) {
      li.dataset.state = state;
      let label = li.querySelector('.rb-check-text');
      if (!label) { label = el('span', 'rb-check-text'); li.append(label); }
      label.textContent = text;
      const icon = li.querySelector('.dashicons');
      if (icon) {
        icon.setAttribute('aria-hidden', 'true');
        icon.className = 'dashicons ' + (state === 'ok' ? 'dashicons-yes-alt' : state === 'bad' ? 'dashicons-warning' : 'dashicons-update');
      }
    }
    let checksRunning = false;
    async function runChecks() {
      if (!obChecks || checksRunning) return;
      checksRunning = true;
      const items = $$('li', obChecks);
      items.forEach(li => setCheck(li, 'idle', t('waiting', 'Waiting')));
      for (let i = 0; i < items.length; i++) {
        const li = items[i], fn = CHECKS[li.dataset.check];
        if (!fn) continue;
        setCheck(li, 'running', t('checking', 'Checking…'));
        let res;
        try { res = await fn(); } catch (_) { res = { ok: false, text: t('networkError', 'Network error') }; }
        setCheck(li, res.ok ? 'ok' : 'bad', res.text);
        if (i < items.length - 1 && !reduce) await new Promise(done => setTimeout(done, 150));
      }
      checksRunning = false;
    }

    /* Finish / restart. */
    function hideOnboarding() {
      const finish = () => {
        ob.hidden = true;
        ob.classList.remove('is-leaving');
        app.dataset.setup = 'done';
        activate('connect');
        const connectTab = tabs.filter(tab => tab.dataset.tab === 'connect')[0];
        if (connectTab) connectTab.focus();
      };
      ob.classList.add('is-leaving');
      if (reduce) finish(); else setTimeout(finish, 240);
    }
    function showOnboarding(step) {
      app.dataset.setup = 'pending';
      ob.hidden = false;
      ob.classList.remove('is-leaving');
      if (obInput) obInput.value = validFrontend() || (feInput ? clean(feInput.value) : '');
      if (obAuto) obAuto.textContent = '';
      obSay('');
      goStep(step || 1, true);
      scrollTo(ob);
    }

    if (obNext1) obNext1.addEventListener('click', saveFrontend);
    if (obInput) obInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); saveFrontend(); }
    });
    if (obPaste) obPaste.addEventListener('click', async () => {
      activate('connect');
      scrollTo($('#rb-card-transfer') || importBox);
      await openImport(true);
    });
    if (obCopy) obCopy.addEventListener('click', async () => {
      await refreshExport();
      await copyExport(obCopy);
    });
    if (obBack2) obBack2.addEventListener('click', () => goStep(1, true));
    if (obNext2) obNext2.addEventListener('click', () => goStep(3, true));
    if (obBack3) obBack3.addEventListener('click', () => goStep(2, true));
    if (obRerun) obRerun.addEventListener('click', runChecks);
    if (obDone) obDone.addEventListener('click', async () => {
      obDone.disabled = true;
      try { await ajax('rb_onboarding', { done: '1' }); } catch (_) { /* the wizard still closes */ }
      obDone.disabled = false;
      sdel(STEP_KEY);
      hideOnboarding();
      say(t('setupDone', 'Setup complete.'));
    });

    if (!ob.hidden) {
      if (obInput && !clean(obInput.value)) obInput.value = validFrontend();
      goStep(sget(STEP_KEY) || ob.dataset.step || 1, false);
    }

    const restart = $('#rb-restart-onboarding');
    if (restart) restart.addEventListener('click', async () => {
      restart.disabled = true;
      try { await ajax('rb_onboarding', { done: '0' }); } catch (_) { /* show it anyway */ }
      restart.disabled = false;
      sdel(STEP_KEY);
      showOnboarding(1);
    });
  }
})();
