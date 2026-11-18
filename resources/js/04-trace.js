// ---------- traces ----------
// A trace is a flat list of spans {id, p (parent id, -1 for roots), type, detail, s (start ms), d (duration ms), err, ev}.
// The API gives the stage timings of a request and the events of its execution; spans are nested under their stage.
const SEGS = new Set(['REQUEST', 'BOOTSTRAP', 'MIDDLEWARE', 'CONTROLLER', 'RENDER', 'SENDING', 'TERMINATING', 'JOB', 'ATTEMPT', 'COMMAND', 'SCHEDULED TASK']);
const STAGE_LABEL = { bootstrap: 'BOOTSTRAP', before_middleware: 'MIDDLEWARE', action: 'CONTROLLER', render: 'RENDER', after_middleware: 'MIDDLEWARE', sending: 'SENDING', terminating: 'TERMINATING' };
const EV_LABEL = { query: 'Query', cache: 'Cache', outgoing_request: 'Outgoing Request', log: 'Log', exception: 'Exception', job: 'Job', mail: 'Mail', notification: 'Notification' };
function evDetail(e) {
  switch (e.type) {
    case 'query': return e.sql || '';
    case 'cache': return `${e.event || ''} ${e.key || ''}`.trim();
    case 'outgoing_request': return `${e.method || ''} ${e.url || ''}`.trim();
    case 'log': return `${e.level || ''}: ${e.message || ''}`;
    case 'exception': return `${shortCls(e.class)}: ${e.message || ''}`;
    case 'job': return e.job || '';
    case 'mail': return e.mailable || e.subject || '';
    case 'notification': return e.notification || '';
    default: return '';
  }
}
function evErr(e) {
  return (e.type === 'exception' && !e.handled) || (e.type === 'outgoing_request' && (e.status_code == null || e.status_code >= 500))
    || (e.type === 'job' && String(e.status).toLowerCase() === 'failed') || ((e.type === 'mail' || e.type === 'notification') && e.failed)
    || (e.type === 'log' && ['error', 'critical', 'alert', 'emergency'].includes(String(e.level).toLowerCase()));
}
function mkTrace() {
  const sp = [];
  const add = (p, type, detail, s, d, meta = {}) => { sp.push({ id: sp.length, p, type, detail, s: Math.max(0, s || 0), d: Math.max(0, d || 0), err: !!meta.err, ev: meta.ev || null, meta }); return sp.length - 1; };
  // depth-first order (children by start time) so a parent is always followed by its own rows
  const done = () => {
    const kids = new Map(); sp.forEach(s => { if (!kids.has(s.p)) kids.set(s.p, []); kids.get(s.p).push(s); });
    const out = []; const walk = (p, np) => (kids.get(p) || []).sort((a, b) => a.s - b.s || a.id - b.id).forEach(s => { const n = { ...s, id: out.length, p: np }; out.push(n); walk(s.id, n.id); });
    walk(-1, -1); return out;
  };
  return { sp, add, done };
}
function addEvents(tr, events, parentFor) {
  for (const e of events) {
    const label = EV_LABEL[e.type] || e.type;
    tr.add(parentFor(e), label, evDetail(e), e.offset_ms, e.duration_ms || 0, { ev: e, err: evErr(e) });
  }
}
function traceRequest(req, events) {
  const tr = mkTrace(); const D = req.duration_ms || Math.max(1, ...events.map(e => (e.offset_ms || 0) + (e.duration_ms || 0)));
  const root = tr.add(-1, 'REQUEST', `${methodOf(req.method)} ${req.route && req.route.path ? req.route.path : req.url}`, 0, D, { err: req.status_code >= 500 });
  const stageSpan = {};
  for (const st of req.stages || []) if (st.duration_ms > 0) stageSpan[st.name] = tr.add(root, STAGE_LABEL[st.name] || st.name.toUpperCase(), '', st.offset_ms, st.duration_ms);
  const own = events.filter(e => e.same_execution !== false), linked = events.filter(e => e.same_execution === false);
  addEvents(tr, own, e => stageSpan[e.stage] ?? root);
  // executions linked by trace id (queued jobs that ran later): one JOB root per job event, their events under it
  const jobRoots = [];
  for (const e of linked.filter(e => e.type === 'job')) jobRoots.push({ id: tr.add(-1, 'JOB', e.job || 'Job', e.offset_ms, e.duration_ms || 0, { ev: e, err: evErr(e) }), s: e.offset_ms, e: e.offset_ms + (e.duration_ms || 0) });
  addEvents(tr, linked.filter(e => e.type !== 'job'), e => { const j = jobRoots.find(j => e.offset_ms >= j.s && e.offset_ms <= j.e + 1); return j ? j.id : root; });
  return tr.done();
}
function traceAttempt(at, events) {
  const tr = mkTrace(); const D = at.duration_ms || 1;
  const root = tr.add(-1, 'JOB', at.job || 'Job', 0, D, { err: String(at.status).toLowerCase() === 'failed' });
  const att = tr.add(root, 'ATTEMPT', '#' + (at.attempt || 1), 0, D, { err: String(at.status).toLowerCase() !== 'processed' });
  addEvents(tr, events, e => e.same_execution === false ? root : att);
  return tr.done();
}
function traceRun(run, events, kind) {
  const tr = mkTrace(); const D = run.duration_ms || 1;
  const root = tr.add(-1, kind, run.command || run.name || '', 0, D, { err: run.exit_code != null && run.exit_code !== 0 });
  addEvents(tr, events, () => root);
  return tr.done();
}

// ---------- timeline component ----------
let TL = null;
function timeline(spans, title = 'Timeline') {
  if (!spans.length) return '';
  const end = Math.max(1, ...spans.map(s => s.s + s.d));
  TL = { spans, end, v0: 0, v1: end * 1.02, col: new Set(), sel: null };
  return `<div class="tl" id="tl"><div class="tl-head"><div class="tl-l"><h3>${title}</h3><div class="tl-tools"><button data-act="tlZoom" data-arg="in" title="Zoom in" aria-label="Zoom in">${ic('zin', 16)}</button><button data-act="tlZoom" data-arg="out" title="Zoom out" aria-label="Zoom out">${ic('zout', 16)}</button><button data-act="tlZoom" data-arg="fit" title="Reset zoom" aria-label="Reset zoom">${ic('fit', 15)}</button><button data-act="tlFold" title="Collapse or expand all" aria-label="Collapse or expand all">${ic('fold', 16)}</button></div></div><div class="tl-axis" id="tl-axis"></div><div class="cursor-b" id="tl-cb"></div></div><div class="tl-body" id="tl-body"></div><div class="tl-hint"><span>${spans.length} spans</span><span>Drag across the waterfall to zoom</span><span>Ctrl + scroll to zoom at the cursor</span><span>Click a row or bar for details</span></div></div>`;
}
function tlNice(range) { const raw = range / 10; const p = Math.pow(10, Math.floor(Math.log10(raw))); const m = raw / p; return (m < 1.5 ? 1 : m < 3.5 ? 2 : m < 7.5 ? 5 : 10) * p; }
function tlFmt(ms, step) { if (ms >= 1000 || step >= 1000) return (ms / 1000).toFixed(step >= 1000 ? 0 : step >= 100 ? 1 : 2) + 's'; return (step < 1 ? ms.toFixed(1) : Math.round(ms)) + 'ms'; }
function hasKids(id) { return TL.spans.some(s => s.p === id); }
function hiddenBy(s) { let p = s.p; while (p >= 0) { if (TL.col.has(p)) return true; p = TL.spans[p].p; } return false; }
function depth(s) { let d = 0, p = s.p; while (p >= 0) { d++; p = TL.spans[p].p; } return d; }
function renderTL() {
  if (!TL || !$('#tl')) return; const { v0, v1 } = TL; const rng_ = v1 - v0; const step = tlNice(rng_);
  let ax = ''; for (let t = Math.ceil(v0 / step) * step; t <= v1; t += step) { const x = (t - v0) / rng_ * 100; if (x > 1.5 && x < 98.5 || t === 0) ax += `<span style="left:${Math.max(2.2, x)}%">${tlFmt(t, step)}</span>`; }
  $('#tl-axis').innerHTML = ax;
  const rows = TL.spans.filter(s => !hiddenBy(s)).map(s => {
    const d = depth(s); const seg = SEGS.has(s.type); const kids = hasKids(s.id); const col = TL.col.has(s.id);
    const x0 = (s.s - v0) / rng_ * 100, x1 = (s.s + s.d - v0) / rng_ * 100;
    const isRoot = s.p < 0; const label = seg ? s.type : s.type.toUpperCase();
    let bar = '';
    if (x1 < 0) bar = `<span class="offarrow" style="left:8px">${ic('cl', 13)}</span>`;
    else if (x0 > 100) bar = `<span class="offarrow" style="right:8px">${ic('cr', 13)}</span>`;
    else { const L = Math.max(0, x0), W = Math.max(.25, Math.min(100, x1) - L); const right = L > 62; bar = `<div class="sp ${seg ? 'seg' : ''} ${s.err ? 'err' : ''} ${TL.sel === s.id ? 'on' : ''}" data-span="${s.id}" style="left:${L}%;width:${W}%"></div><div class="spl ${isRoot ? 'root' : ''} ${s.err ? 'err' : ''}" style="${right ? `right:${100 - L}%` : `left:${L}%`}"><b style="font-weight:500">${esc(label)}</b><span class="d">${fmtD(s.d)}</span><span class="x">${esc((s.detail || '').slice(0, 90))}${(s.detail || '').length > 90 ? '…' : ''}</span></div>`; }
    const inds = Array.from({ length: d }, () => '<span class="ind"></span>').join('');
    return `<div class="tl-row ${TL.sel === s.id ? 'sel' : ''}"><div class="tl-l ${s.err ? 'err' : ''}" data-span="${s.id}" title="${esc(s.type + ' ' + (s.detail || ''))}">${inds}<span class="tg" ${kids ? `data-fold="${s.id}"` : ''}>${kids ? (col ? '+' : '—') : ''}</span>${s.err && !seg ? `<span style="color:var(--red);margin-right:6px;display:inline-flex">${ic('ac', 14, 2)}</span>` : ''}<span class="ty ${seg ? '' : 'ev'}">${esc(s.type)}${s.type === 'ATTEMPT' ? ' ' + esc(s.detail) : ''}</span>${s.type !== 'ATTEMPT' && s.detail ? `<span class="de">${esc(s.detail)}</span>` : ''}</div><div class="tl-r">${bar}</div></div>`;
  }).join('');
  $('#tl-body').innerHTML = rows + '<div class="cursor" id="tl-cur"></div><div class="brush" id="tl-brush"></div>';
}
function tlXToMs(clientX) { const r = $('#tl-axis').getBoundingClientRect(); return TL.v0 + (clientX - r.left) / r.width * (TL.v1 - TL.v0); }
function tlZoom(f, at) { const c = at ?? (TL.v0 + TL.v1) / 2; let v0 = c - (c - TL.v0) * f, v1 = c + (TL.v1 - c) * f; const max = TL.end * 1.02; if (v1 - v0 >= max) { v0 = 0; v1 = max; } v0 = Math.max(0, v0); v1 = Math.min(max, v1); if (v1 - v0 < .05) return; TL.v0 = v0; TL.v1 = v1; renderTL(); }
let brush = null;
document.addEventListener('mousemove', e => {
  if (!TL || !$('#tl')) return; const ax = $('#tl-axis'); const r = ax.getBoundingClientRect(); const body = $('#tl-body'); const br = body.getBoundingClientRect();
  const inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= br.bottom;
  const cur = $('#tl-cur'), cb = $('#tl-cb'); if (!cur || !cb) return;
  if (inside) { const x = e.clientX - r.left; const lx = r.left - br.left + x; cur.style.display = 'block'; cur.style.left = lx + 'px'; cur.style.top = body.scrollTop + 'px'; cur.style.height = body.clientHeight + 'px'; cb.style.display = 'block'; cb.style.left = (r.left - $('#tl').getBoundingClientRect().left + x) + 'px'; cb.textContent = tlFmt(tlXToMs(e.clientX), (TL.v1 - TL.v0) < 20 ? .5 : 1); }
  else { cur.style.display = 'none'; cb.style.display = 'none'; }
  if (brush) { const b = $('#tl-brush'); const a = Math.min(brush.x, e.clientX), z = Math.max(brush.x, e.clientX); b.style.display = 'block'; b.style.left = (a - br.left) + 'px'; b.style.width = (z - a) + 'px'; b.style.top = body.scrollTop + 'px'; b.style.height = body.clientHeight + 'px'; brush.moved = Math.abs(e.clientX - brush.x) > 6; }
});
document.addEventListener('mousedown', e => { const r = e.target.closest && e.target.closest('.tl-r'); if (r && !e.target.closest('.sp') && e.button === 0) { brush = { x: e.clientX }; e.preventDefault(); } });
document.addEventListener('mouseup', e => {
  if (!brush) return; const b = brush; brush = null; const el = $('#tl-brush'); if (el) el.style.display = 'none';
  if (b.moved && TL) { const a = tlXToMs(Math.min(b.x, e.clientX)), z = tlXToMs(Math.max(b.x, e.clientX)); TL.v0 = Math.max(0, a); TL.v1 = Math.min(TL.end * 1.02, z); renderTL(); }
});
document.addEventListener('wheel', e => { if (!TL || !(e.ctrlKey || e.metaKey)) return; const r = e.target.closest && e.target.closest('.tl-r'); if (!r) return; e.preventDefault(); tlZoom(e.deltaY > 0 ? 1.25 : .8, tlXToMs(e.clientX)); }, { passive: false });

function spanDrawer(id) {
  const s = TL.spans[id]; TL.sel = id; renderTL(); const e = s.ev || {}; const seg = SEGS.has(s.type);
  const rows = [['Type', esc(s.type)], ['Started', '+' + fmtD(s.s)], ['Duration', fmtD(s.d)], ['Share of trace', (s.d / TL.end * 100).toFixed(1) + '%']];
  if (e.stage) rows.push(['Stage', esc(e.stage)]);
  let body = '';
  const card = (h, b, link) => `<div class="dcard"><div class="dcard-h">${h}<span class="r">${link || ''}</span></div><div class="dcard-b">${b}</div></div>`;
  const btn = (go, t) => `<a class="btn sm" data-go="${esc(go)}">${esc(t)} ${ic('aur', 13)}</a>`;
  if (e.type === 'query') { rows.push(['Connection', esc(e.connection || '—')]); if (e.file) rows.push(['Source', esc(e.file + (e.line ? ':' + e.line : ''))]); body = card('SQL', `<pre class="box">${sqlHi(e.sql || '')}</pre><div class="mut" style="margin-top:8px;font-size:12px;font-family:var(--sans)">Bindings are not recorded, so values show as ?</div>`, e.group_hash ? btn('queries/' + e.group_hash, 'Query') : ''); }
  else if (e.type === 'outgoing_request') { rows.push(['Method', esc(e.method || '—')], ['Status', e.status_code ? `<span class="st ${e.status_code >= 500 ? 's5' : e.status_code >= 400 ? 's4' : ''}">${e.status_code}</span>` : '<span class="st s5">no response</span>'], ['Host', esc(e.host || '—')]); body = card('URL', esc(e.url || ''), e.group_hash ? btn('outgoing/' + e.group_hash, 'Domain') : ''); }
  else if (e.type === 'cache') { rows.push(['Event', esc(e.event || '—')], ['Store', esc(e.store || '—')]); if (e.ttl != null) rows.push(['TTL', esc(e.ttl + 's')]); body = card('Key', esc(e.key || ''), `<a class="btn sm" data-act="goSearch" data-arg="cache|cache|${esc(e.key || '')}">Cache ${ic('aur', 13)}</a>`); }
  else if (e.type === 'exception') { rows.push(['Handled', e.handled ? 'yes' : 'no']); if (e.file) rows.push(['File', esc(e.file + (e.line ? ':' + e.line : ''))]); body = card(`<span style="color:var(--red)" class="ell">${esc(e.class || '')}</span>`, esc(e.message || ''), e.fingerprint ? btn('exceptions/' + e.fingerprint, 'Exception') : ''); }
  else if (e.type === 'log') { rows.push(['Level', lvlHtml(e.level)], ['Channel', esc(e.channel || '—')]); const ctx = parseJson(e.context); body = card('Message', esc(e.message || '')) + (ctx != null && ctx !== '' ? card('Context', typeof ctx === 'object' ? `<div class="json">${jsonHtml(ctx)}</div>` : esc(ctx)) : ''); }
  else if (e.type === 'job') { rows.push(['Queue', esc(e.queue || '—')], ['Status', esc(e.status || '—')], ['Attempt', esc(e.attempt ?? '—')]); body = card('Job', esc(e.job || ''), `<a class="btn sm" data-act="goSearch" data-arg="jobs|jobs|${esc(e.job || '')}">Jobs ${ic('aur', 13)}</a>`); }
  else if (e.type === 'mail') { rows.push(['Queued', e.queued ? 'yes' : 'no'], ['Failed', e.failed ? 'yes' : 'no']); body = card('Mailable', esc(e.mailable || '—')) + (e.subject ? card('Subject', esc(e.subject)) : ''); }
  else if (e.type === 'notification') { rows.push(['Channel', esc(e.channel || '—')], ['Notifiable', esc(e.notifiable_type || '—')], ['Queued', e.queued ? 'yes' : 'no'], ['Failed', e.failed ? 'yes' : 'no']); body = card('Notification', esc(e.notification || '')); }
  else if (s.detail) body = card('Detail', esc(s.detail));
  const kids = TL.spans.filter(x => x.p === s.id);
  if (kids.length) body += `<div class="dcard"><div class="dcard-h">Events<span class="r">${kids.length}</span></div><div class="dcard-b" style="padding:6px 16px">${kids.slice(0, 200).map(k => `<div class="kv" style="cursor:pointer" data-spango="${k.id}"><span class="k" style="${k.err ? 'color:var(--red)' : ''}">${esc(k.type)}</span><span class="f"></span><span class="v">${fmtD(k.d)}</span></div>`).join('')}</div></div>`;
  openDrawer(`<span style="${s.err ? 'color:var(--red)' : ''}">${esc(seg ? s.type : s.type.toUpperCase())}</span> <span class="mut">${fmtD(s.d)}</span>`, `<div class="dcard"><div class="dcard-b" style="padding:6px 16px">${rows.map(r => kv(r[0], r[1])).join('')}</div></div>${body}`, false);
}
