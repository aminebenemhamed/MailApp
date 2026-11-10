// MailApp front end: each provider has its own Inbox and Spam.
const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);
const state = {
  accounts: [], counts: {}, acc: null, box: 'inbox', page: 1, total: 0,
  messages: [], current: null, selected: new Set(), lastPick: -1,
};
const BOX_LABEL = { inbox: 'Inbox', spam: 'Spam' };

const ICON = {
  inbox: '<svg viewBox="0 0 24 24"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
  spam: '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="M12 8v4M12 16h.01"/></svg>',
  clip: '<svg viewBox="0 0 24 24"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>',
  mail: '<svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="3"/><path d="m22 7-10 6L2 7"/></svg>',
  alert: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>',
  image: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>',
  key: '<svg viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/></svg>',
  star: '<svg viewBox="0 0 24 24"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>',
  sun: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>',
  moon: '<svg viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/></svg>',
  bell: '<svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
  bellOff: '<svg viewBox="0 0 24 24"><path d="M8.7 3A6 6 0 0 1 18 8a21.3 21.3 0 0 0 .6 5M17 17H3s3-2 3-9a4.67 4.67 0 0 1 .3-1.7M10.3 21a1.94 1.94 0 0 0 3.4 0M2 2l20 20"/></svg>',
  auto: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/></svg>',
};

// ---------- helpers ----------

async function api(params, post) {
  const url = '?' + new URLSearchParams(params);
  const res = post ? await fetch(url, { method: 'POST', body: new URLSearchParams(post), headers: { 'X-MailApp': '1' } }) : await fetch(url);
  const data = await res.json().catch(() => ({ error: 'Invalid server response' }));
  if (data.code === 'auth') { location.reload(); throw new Error(data.error); }
  if (!res.ok || data.error) {
    const err = new Error(data.error || 'Error ' + res.status);
    err.code = data.code;
    throw err;
  }
  return data;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function fmtDate(ts, long) {
  if (!ts) return '';
  const d = new Date(ts * 1000), now = new Date();
  if (long) return d.toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
  return d.toLocaleDateString('en-GB');
}

function fmtSize(n) {
  if (n < 1024) return n + ' B';
  if (n < 1048576) return Math.round(n / 1024) + ' KB';
  return (n / 1048576).toFixed(1) + ' MB';
}

const plural = (n, word) => `${n} ${word}${n > 1 ? 's' : ''}`;

// Soft colored circle with initials; the hue comes from the address so it stays stable.
function avatar(from) {
  const key = (from.email || from.name || '?').toLowerCase();
  let h = 0;
  for (const ch of key) h = (h * 31 + ch.codePointAt(0)) % 360;
  const words = (from.name || '').replace(/[^\p{L}\p{N} ]/gu, ' ').trim().split(/\s+/).filter(Boolean);
  const ini = words.length
    ? ([...words[0]][0] + (words.length > 1 ? [...words[words.length - 1]][0] : '')).toUpperCase()
    : ([...(from.email || '?')][0] || '?').toUpperCase();
  return `<span class="av" style="--h:${h}">${esc(ini)}</span>`;
}

function emptyState(icon, title, sub, isError, extra = '') {
  return `<div class="empty${isError ? ' err' : ''}"><div class="ico">${ICON[icon]}</div>
    <div class="t">${esc(title)}</div>${sub ? `<div class="s">${esc(sub)}</div>` : ''}${extra}</div>`;
}

function skeleton(n) {
  return Array.from({ length: n }, () =>
    '<div class="skel"><i class="c"></i><div class="l"><i style="height:10px;width:55%"></i><i style="height:10px;width:85%"></i></div></div>').join('');
}

let toastTimer;
function toast(text) {
  let el = $('.toast');
  if (!el) { el = document.createElement('div'); el.className = 'toast'; document.body.appendChild(el); }
  el.textContent = text;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2200);
}

function show(view) { $('#app').className = 'show-' + view; }
const account = (id) => state.accounts.find((a) => a.id === id);

// ---------- theme (auto → clair → sombre) ----------

const THEMES = ['auto', 'light', 'dark'];
const THEME_LABEL = { auto: 'Theme: automatic', light: 'Theme: light', dark: 'Theme: dark' };
function setTheme(t) {
  if (t === 'auto') delete document.documentElement.dataset.theme;
  else document.documentElement.dataset.theme = t;
  try { localStorage.setItem('theme', t); } catch (e) { /* private mode */ }
  const btn = $('#themeBtn');
  btn.innerHTML = ICON[t === 'auto' ? 'auto' : t === 'light' ? 'sun' : 'moon'];
  btn.title = THEME_LABEL[t];
  btn.dataset.t = t;
}
$('#themeBtn').addEventListener('click', () => {
  const t = THEMES[(THEMES.indexOf($('#themeBtn').dataset.t) + 1) % THEMES.length];
  setTheme(t);
  toast(THEME_LABEL[t]);
});
setTheme(document.documentElement.dataset.theme || 'auto');

// ---------- accounts, counts ----------

async function loadAccounts() {
  $('#list').innerHTML = skeleton(8);
  try {
    state.accounts = await api({ api: 'accounts' });
  } catch (e) {
    $('#list').innerHTML = emptyState('alert', 'Error', e.message, true);
    return;
  }
  renderAccounts();
  const mail = state.accounts.filter((a) => a.type === 'mail');
  mail.forEach((a) => loadCounts(a.id));
  if (!mail.length) {
    closeReader();
    $('#listInfo').textContent = '';
    $('#list').innerHTML = emptyState('mail', 'No accounts', 'Add your first mail account to get started.', false,
      '<button class="btn" onclick="openAdd()">Add an account</button>');
    return;
  }
  openFolder(account(state.acc) && account(state.acc).type === 'mail' ? state.acc : mail[0].id, 'inbox');
}

function openLink(a) {
  window.open(a.url, 'mailapp-' + a.id, 'width=1200,height=820');
}

async function removeAccount(a) {
  if (!confirm(`Remove ${a.name}${a.email ? ' (' + a.email + ')' : ''} from MailApp?\nYour emails stay in your account, nothing is deleted.`)) return;
  try {
    await api({ api: 'remove', acc: a.id }, {});
    delete state.counts[a.id];
    if (state.acc === a.id) state.acc = null;
    toast('Account removed');
    loadAccounts();
  } catch (e) {
    toast(e.message);
  }
}

function renderAccounts() {
  $('#railAccts').innerHTML = state.accounts.map((a) => {
    if (a.type === 'link') {
      return `<button class="tile link" data-acc="${esc(a.id)}" style="--c:${esc(a.color)}" title="${esc(a.name)} (opens in a window)">${PROV_ICON[a.id.split('-')[0]] || esc(a.name.charAt(0))}</button>`;
    }
    const c = state.counts[a.id];
    const badge = c?.error ? `<span class="n err">!</span>` : c?.inbox?.unseen ? `<span class="n">${c.inbox.unseen > 99 ? '99+' : c.inbox.unseen}</span>` : '';
    return `<button class="tile${a.id === state.acc ? ' active' : ''}" data-acc="${esc(a.id)}" style="--c:${esc(a.color)}" title="${esc(a.name)} · ${esc(a.email)}">${esc(a.name.charAt(0).toUpperCase())}${badge}</button>`;
  }).join('');
  $$('#railAccts .tile').forEach((el) => {
    el.addEventListener('click', () => {
      const a = account(el.dataset.acc);
      if (a.type === 'link') openLink(a); else openFolder(a.id, 'inbox');
    });
    // Right click (or long press on a phone) on an account: remove it.
    el.addEventListener('contextmenu', (ev) => { ev.preventDefault(); removeAccount(account(el.dataset.acc)); });
  });

  $('#acctMenu').innerHTML = state.accounts.map((a) => {
    const c = state.counts[a.id];
    const n = c?.error ? '<span class="badge err">' + (c.code === 'oauth_required' ? ICON.key : ICON.alert) + '</span>' : c?.inbox?.unseen ? `<span class="m-n">${c.inbox.unseen}</span>` : '';
    const del = a.removable ? `<button class="m-del" data-del="${esc(a.id)}" title="Remove this account"><svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button>` : '';
    return `<div class="m-row"><button data-acc="${esc(a.id)}"><span class="dot" style="--c:${esc(a.color)}"></span>
      <span class="m-text"><div class="m-name">${esc(a.name)}</div><div class="m-email">${esc(a.email || (a.type === 'link' ? 'Opens in a window' : ''))}</div></span>${n}</button>${del}</div>`;
  }).join('') + `<button class="m-add" id="menuAdd"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><span>Add an account</span></button>`;
  $$('#acctMenu [data-acc]').forEach((el) => el.addEventListener('click', () => {
    $('#acctMenu').hidden = true;
    const a = account(el.dataset.acc);
    if (a.type === 'link') openLink(a); else openFolder(a.id, 'inbox');
  }));
  $$('#acctMenu [data-del]').forEach((el) => el.addEventListener('click', (ev) => {
    ev.stopPropagation();
    $('#acctMenu').hidden = true;
    removeAccount(account(el.dataset.del));
  }));
  $('#menuAdd').addEventListener('click', () => { $('#acctMenu').hidden = true; openAdd(); });

  const a = account(state.acc);
  if (a) {
    $('#acctName').textContent = a.name;
    $('#acctDot').style.setProperty('--c', a.color);
  }
  renderTabs();
}

function renderTabs() {
  const c = state.counts[state.acc];
  for (const box of ['inbox', 'spam']) {
    const el = $('#tab-' + box);
    el.className = 'badge';
    el.removeAttribute('title');
    if (!c) el.textContent = '';
    else if (c.error) { el.className = 'badge err'; el.innerHTML = c.code === 'oauth_required' ? ICON.key : ICON.alert; el.title = c.error; }
    else el.textContent = c[box]?.unseen ? (c[box].unseen > 999 ? '999+' : c[box].unseen) : '';
  }
  $$('#tabs button').forEach((b) => b.classList.toggle('on', b.dataset.box === state.box));
}

async function loadCounts(acc) {
  try {
    state.counts[acc] = await api({ api: 'counts', acc });
  } catch (e) {
    state.counts[acc] = { error: e.message, code: e.code };
  }
  renderAccounts();
  updateTitle();
  checkNew(acc);
}

// ---------- notifications (new mail while the tab is open) ----------

const POLL_MS = 60 * 1000;
const lastNext = {};   // account id -> UIDNEXT of its inbox when we last looked
let notifyOn = false;
try { notifyOn = localStorage.getItem('notify') === '1'; } catch (e) { /* private mode */ }
const canNotify = () => 'Notification' in window && Notification.permission === 'granted' && notifyOn;

function renderNotifyBtn() {
  const btn = $('#notifyBtn');
  const on = canNotify();
  btn.innerHTML = on ? ICON.bell : ICON.bellOff;
  btn.title = on ? 'Notifications: on' : 'Notifications: off';
  btn.classList.toggle('on', on);
}

$('#notifyBtn').addEventListener('click', async () => {
  if (!('Notification' in window)) return toast('This browser does not support notifications');
  if (Notification.permission === 'denied') {
    return toast('Notifications are blocked: allow them in the site settings (icon left of the address bar)');
  }
  if (Notification.permission === 'default') {
    const p = await Notification.requestPermission();
    notifyOn = p === 'granted';
  } else {
    notifyOn = !notifyOn;
  }
  try { localStorage.setItem('notify', notifyOn ? '1' : '0'); } catch (e) { /* private mode */ }
  renderNotifyBtn();
  toast(canNotify() ? 'Notifications on' : 'Notifications off');
});
renderNotifyBtn();

// Total unread in the tab title, e.g. "(3) MailApp".
function updateTitle() {
  const n = Object.values(state.counts).reduce((t, c) => t + (c?.inbox?.unseen || 0), 0);
  document.title = n ? `(${n}) MailApp` : 'MailApp';
  if (navigator.setAppBadge) (n ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {});
}

// Looks for mail that arrived since the last check: notifies and adds it to the open list.
async function checkNew(acc) {
  const next = state.counts[acc]?.inbox?.next;
  if (!next) return;
  const before = lastNext[acc];
  lastNext[acc] = next;
  if (!before || next <= before) return; // first look, or nothing new
  let fresh;
  try {
    fresh = (await api({ api: 'list', acc, box: 'inbox', page: 1 })).messages.filter((m) => Number(m.uid) >= before);
  } catch (e) { return; }
  if (!fresh.length) return;

  if (state.acc === acc && state.box === 'inbox') {
    const known = new Set(state.messages.map((m) => String(m.uid)));
    const add = fresh.filter((m) => !known.has(String(m.uid)));
    if (add.length) {
      state.messages = add.concat(state.messages);
      state.total += add.length;
      state.lastPick = -1;
      renderList();
    }
  }

  const unread = fresh.filter((m) => !m.seen);
  if (!unread.length || !canNotify()) return;
  const a = account(acc);
  const show1 = (title, body, tag, m) => {
    const n = new Notification(title, { body, tag, icon: 'icon-192.png', requireInteraction: true });
    n.onclick = () => {
      window.focus();
      n.close();
      const go = () => { const hit = state.messages.find((x) => String(x.uid) === String(m?.uid)); if (hit) openMessage(hit); };
      if (state.acc === acc && state.box === 'inbox') go();
      else openFolder(acc, 'inbox').then(go);
    };
  };
  if (unread.length <= 3) {
    unread.forEach((m) => show1(`${m.from.name || m.from.email || 'New message'} · ${a?.name || ''}`,
      m.subject || '(no subject)', `${acc}-${m.uid}`, m));
  } else {
    show1(`${unread.length} new messages · ${a?.name || ''}`,
      unread.slice(0, 3).map((m) => (m.from.name || m.from.email) + ' : ' + (m.subject || '(no subject)')).join('\n'), `${acc}-batch`);
  }
}

// Every minute, check each mail account one after the other.
setInterval(async () => {
  for (const a of state.accounts) {
    if (a.type !== 'mail' || state.counts[a.id]?.code === 'oauth_required') continue;
    await loadCounts(a.id);
  }
}, POLL_MS);

function bumpUnread(acc, box, delta) {
  const c = state.counts[acc]?.[box];
  if (!c) return;
  c.unseen = Math.max(0, c.unseen + delta);
  renderAccounts();
}

// ---------- list ----------

async function openFolder(acc, box) {
  Object.assign(state, { acc, box, page: 1, messages: [], current: null, lastPick: -1 });
  state.selected.clear();
  renderAccounts();
  $('#list').innerHTML = skeleton(9);
  $('#listInfo').textContent = '';
  closeReader();
  syncSelection();
  show('list');
  await loadPage();
}

async function loadPage() {
  const { acc, box, page } = state;
  $('#refreshBtn').classList.add('spin');
  try {
    const data = await api({ api: 'list', acc, box, page });
    if (acc !== state.acc || box !== state.box) return; // user moved on
    if (data.missing) {
      $('#list').innerHTML = emptyState('spam', 'No spam folder', 'This account has no spam folder.');
      return;
    }
    state.total = data.total;
    state.messages = state.messages.concat(data.messages);
    renderList();
  } catch (e) {
    if (acc !== state.acc || box !== state.box) return;
    if (e.code === 'oauth_required') {
      const why = e.message && !/required$/.test(e.message) ? e.message : 'This account signs in with your Microsoft account.';
      $('#list').innerHTML = emptyState('key', 'Microsoft sign-in', why, false,
        `<a class="btn" href="oauth.php?acc=${encodeURIComponent(acc)}">Sign in with Microsoft</a>`);
    } else {
      $('#list').innerHTML = emptyState('alert', 'Can’t connect', e.message, true);
    }
  } finally {
    $('#refreshBtn').classList.remove('spin');
  }
}

function renderList() {
  $('#listInfo').textContent = plural(state.total, 'message');
  if (!state.messages.length) {
    $('#list').innerHTML = state.box === 'spam'
      ? emptyState('spam', 'No spam', 'Nothing suspicious here.')
      : emptyState('inbox', 'Inbox empty', 'No messages yet.');
    syncSelection();
    return;
  }
  const rows = state.messages.map((m, i) => `
    <div class="row${m.seen ? '' : ' unread'}${state.current === m.uid ? ' active' : ''}${state.selected.has(m.uid) ? ' picked' : ''}" data-i="${i}">
      <div class="lead">${avatar(m.from)}<input type="checkbox" class="check" ${state.selected.has(m.uid) ? 'checked' : ''} aria-label="Select"></div>
      <div class="main">
        <div class="top"><span class="from" dir="auto">${esc(m.from.name || m.from.email || '(unknown)')}</span>${m.flagged ? `<span class="star">${ICON.star}</span>` : ''}
          <span class="date">${fmtDate(m.date)}</span></div>
        <div class="subj" dir="auto">${esc(m.subject || '(no subject)')}</div>
      </div>
    </div>`).join('');
  const more = state.messages.length < state.total ? '<button class="more" id="moreBtn">More messages</button>' : '';
  $('#list').innerHTML = rows + more;
  $('#moreBtn')?.addEventListener('click', (ev) => {
    ev.target.textContent = 'Loading…';
    state.page++;
    loadPage();
  });
  syncSelection();
}

// One listener for all rows: tap the avatar/checkbox to select, the rest to open.
$('#list').addEventListener('click', (ev) => {
  const row = ev.target.closest('.row');
  if (!row) return;
  const i = +row.dataset.i;
  if (ev.target.closest('.lead') || ev.metaKey || ev.ctrlKey || ev.shiftKey || state.selected.size) {
    pick(i, ev.shiftKey);
  } else {
    openMessage(state.messages[i]);
  }
});

// ---------- selection ----------

function pick(i, range) {
  const m = state.messages[i];
  if (range && state.lastPick >= 0) {
    const [a, b] = [Math.min(i, state.lastPick), Math.max(i, state.lastPick)];
    for (let k = a; k <= b; k++) state.selected.add(state.messages[k].uid);
  } else if (state.selected.has(m.uid)) {
    state.selected.delete(m.uid);
  } else {
    state.selected.add(m.uid);
  }
  state.lastPick = i;
  syncSelection();
}

function syncSelection() {
  const n = state.selected.size, total = state.messages.length;
  $('#listPane').classList.toggle('selecting', n > 0);
  $('#bulkBar').hidden = n === 0;
  $('#listBar').hidden = n > 0;
  $('#selInfo').textContent = `${n} selected`;
  for (const id of ['#checkAll', '#checkAll2']) {
    const cb = $(id);
    cb.checked = n > 0 && n === total;
    cb.indeterminate = n > 0 && n < total;
  }
  $$('#list .row').forEach((row) => {
    const on = state.selected.has(state.messages[+row.dataset.i]?.uid);
    row.classList.toggle('picked', on);
    const cb = row.querySelector('.check');
    if (cb) cb.checked = on;
  });
  $('[data-bulk="notspam"]').hidden = state.box !== 'spam';
  $('#readAllBtn').disabled = !state.messages.length;
  $('#checkAll').disabled = !state.messages.length;
}

function selectAll(on) {
  state.selected = new Set(on ? state.messages.map((m) => m.uid) : []);
  syncSelection();
}
$('#checkAll').addEventListener('change', (ev) => selectAll(ev.target.checked));
$('#checkAll2').addEventListener('change', (ev) => selectAll(ev.target.checked));
$('#clearSel').addEventListener('click', () => selectAll(false));

// ---------- actions (one or many messages) ----------

const DONE = {
  read: (n) => n > 1 ? `${n} messages marked read` : 'Marked as read',
  unread: (n) => n > 1 ? `${n} messages marked unread` : 'Marked as unread',
  flag: (n) => n > 1 ? `${n} messages starred` : 'Starred',
  unflag: () => 'Star removed',
  archive: (n) => n > 1 ? `${n} messages archived` : 'Message archived',
  delete: (n) => n > 1 ? `${n} messages deleted` : 'Message deleted',
  notspam: (n) => n > 1 ? `${n} messages moved to inbox` : 'Moved to inbox',
};

async function run(what, msgs) {
  if (!msgs.length) return;
  const { acc, box } = state;
  const buttons = $$('#bulkBar button, #readBar button');
  buttons.forEach((b) => (b.disabled = true));
  try {
    await api({ api: 'action', acc, box }, { do: what, uids: msgs.map((m) => m.uid).join(',') });
    if (acc !== state.acc || box !== state.box) return;
    if (what === 'read' || what === 'unread') {
      for (const m of msgs) m.seen = what === 'read';
    } else if (what === 'flag' || what === 'unflag') {
      for (const m of msgs) m.flagged = what === 'flag';
    } else {
      // Messages that left this folder
      const gone = new Set(msgs.map((m) => m.uid));
      const curIndex = state.messages.findIndex((m) => m.uid === state.current);
      state.messages = state.messages.filter((m) => !gone.has(m.uid));
      state.total = Math.max(0, state.total - gone.size);
      for (const id of gone) state.selected.delete(id);
      if (gone.has(state.current)) {
        const next = state.messages[curIndex] || state.messages[curIndex - 1];
        if (next && window.innerWidth > 760 && msgs.length === 1) { renderList(); openMessage(next); }
        else { closeReader(); show('list'); }
      }
    }
    if (what === 'unread' && msgs.some((m) => m.uid === state.current)) { closeReader(); show('list'); }
    renderList();
    const cur = currentMsg();
    if (cur) syncBar(cur);
    toast(DONE[what](msgs.length));
    loadCounts(acc);
  } catch (e) {
    toast(e.message);
  } finally {
    buttons.forEach((b) => (b.disabled = false));
  }
}

$$('[data-bulk]').forEach((b) => b.addEventListener('click', () => {
  const msgs = state.messages.filter((m) => state.selected.has(m.uid));
  let what = b.dataset.bulk;
  if (what === 'flag' && msgs.every((m) => m.flagged)) what = 'unflag';
  if (what === 'read' || what === 'unread' || what === 'flag' || what === 'unflag') {
    run(what, msgs).then(() => selectAll(false));
  } else {
    run(what, msgs);
  }
}));

$('#readAllBtn').addEventListener('click', async () => {
  const { acc, box } = state;
  try {
    await api({ api: 'action', acc, box }, { do: 'readall' });
    if (acc !== state.acc || box !== state.box) return;
    state.messages.forEach((m) => (m.seen = true));
    renderList();
    toast('Whole folder marked as read');
    loadCounts(acc);
  } catch (e) {
    toast(e.message);
  }
});

// ---------- reader ----------

function closeReader() {
  state.current = null;
  $('#readBar').hidden = true;
  $('#reader').innerHTML = emptyState('mail', 'No message selected', 'Pick a message from the list to read it.');
  $$('#list .row.active').forEach((r) => r.classList.remove('active'));
}

function currentMsg() {
  return state.messages.find((x) => x.uid === state.current);
}

function syncBar(m) {
  $('#readBar').hidden = false;
  $('#notSpamBtn').hidden = state.box !== 'spam';
  $('#flagBtn').classList.toggle('on', !!m.flagged);
}

async function openMessage(m) {
  state.current = m.uid;
  const { acc, box } = state;
  syncBar(m);
  $$('#list .row').forEach((el) => el.classList.toggle('active', state.messages[+el.dataset.i] === m));
  $('#reader').innerHTML = `<div class="msg"><h1 dir="auto">${esc(m.subject || '(no subject)')}</h1>${skeleton(1)}</div>`;
  show('read');
  try {
    const msg = await api({ api: 'msg', acc, box, uid: m.uid });
    if (state.current !== m.uid) return;
    if (!m.seen) {
      m.seen = true;
      bumpUnread(acc, box, -1);
      $(`#list .row[data-i="${state.messages.indexOf(m)}"]`)?.classList.remove('unread');
    }
    renderMessage(msg, acc, box, m.uid, false);
  } catch (e) {
    $('#reader').innerHTML = emptyState('alert', 'Can’t open this message', e.message, true);
  }
}

function renderMessage(msg, acc, box, uid, allowImages) {
  state.msg = { msg, acc, box, uid };
  const when = fmtDate(msg.date, true);
  const atts = msg.attachments.map((a) =>
    `<a class="att" href="?${new URLSearchParams({ api: 'att', acc, box, uid, part: a.part })}">${ICON.clip}<span class="n">${esc(a.name)}</span><span class="s">${fmtSize(a.size)}</span></a>`).join('');
  const hasRemote = msg.html && /(src|background)\s*=\s*["']?\s*(https?:)?\/\//i.test(msg.html);
  $('#reader').innerHTML = `
    <article class="msg">
      <h1 dir="auto">${esc(msg.subject || '(no subject)')}</h1>
      <div class="sender">
        ${avatar(msg.from)}
        <div class="who">
          <div><span class="name" dir="auto">${esc(msg.from.name || msg.from.email)}</span>${msg.from.name ? `<span class="email">&lt;${esc(msg.from.email)}&gt;</span>` : ''}</div>
          <div class="to" data-when="${esc(when)}">To: ${esc(msg.to)}${msg.cc ? ' · Cc: ' + esc(msg.cc) : ''}</div>
        </div>
        <div class="when">${esc(when)}</div>
      </div>
      ${atts ? `<div class="atts">${atts}</div>` : ''}
      ${hasRemote && !allowImages ? `<div class="notice">${ICON.image}<span>Remote images blocked to protect your privacy.</span><button id="imgBtn">Show</button></div>` : ''}
      <div class="body"></div>
    </article>`;
  const body = $('#reader .body');
  if (msg.html) {
    // Sandboxed: no scripts, no access to the app. Remote images blocked unless asked.
    const csp = allowImages
      ? "default-src 'none'; img-src * data:; style-src 'unsafe-inline' *; font-src * data:"
      : "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:";
    const frame = document.createElement('iframe');
    frame.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
    frame.setAttribute('referrerpolicy', 'no-referrer');
    frame.srcdoc = `<!doctype html><html><head><meta charset="utf-8">
      <meta http-equiv="Content-Security-Policy" content="${csp}">
      <base target="_blank"><style>html{background:transparent}body{font:14.5px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:22px 24px;color:#26262a;word-wrap:break-word}img{max-width:100%;height:auto}a{color:#a5462a}</style>
      </head><body>${msg.html}</body></html>`;
    body.appendChild(frame);
  } else {
    const pre = document.createElement('pre');
    pre.dir = 'auto';
    pre.textContent = msg.text || '';
    body.appendChild(pre);
  }
  $('#imgBtn')?.addEventListener('click', () => renderMessage(msg, acc, box, uid, true));
}

function actOnCurrent(what) {
  const m = currentMsg();
  if (!m) return;
  if (what === 'flag' && m.flagged) what = 'unflag';
  run(what, [m]);
}

// ---------- wiring ----------

$$('#tabs button').forEach((b) => b.addEventListener('click', () => state.acc && openFolder(state.acc, b.dataset.box)));
$('#acctBtn').addEventListener('click', (ev) => { ev.stopPropagation(); $('#acctMenu').hidden = !$('#acctMenu').hidden; });
document.addEventListener('click', (ev) => { if (!ev.target.closest('.acct-switch')) $('#acctMenu').hidden = true; });
$('#refreshBtn').addEventListener('click', () => { if (state.acc) { loadCounts(state.acc); openFolder(state.acc, state.box); } });
$('#backBtn').addEventListener('click', () => { show('list'); });
$('#unreadBtn').addEventListener('click', () => actOnCurrent('unread'));
$('#flagBtn').addEventListener('click', () => actOnCurrent('flag'));
$('#archiveBtn').addEventListener('click', () => actOnCurrent('archive'));
$('#deleteBtn').addEventListener('click', () => actOnCurrent('delete'));
$('#notSpamBtn').addEventListener('click', () => actOnCurrent('notspam'));

// Keyboard: j/k next/previous, x select, e archive, # delete, s important, u unread, Esc clear selection
document.addEventListener('keydown', (ev) => {
  if (ev.metaKey || ev.ctrlKey || ev.altKey || /input|textarea|select/i.test(ev.target.tagName) || !$('#addModal').hidden || !$('#composeModal').hidden) return;
  if (ev.key === 'c') { ev.preventDefault(); openCompose('new'); return; }
  if (state.msg && state.msg.uid === state.current && ['r', 'a', 'f'].includes(ev.key)) {
    ev.preventDefault();
    openCompose({ r: 'reply', a: 'replyall', f: 'forward' }[ev.key]);
    return;
  }
  const m = currentMsg();
  const i = m ? state.messages.indexOf(m) : -1;
  if (ev.key === 'j' || ev.key === 'k') {
    const next = state.messages[ev.key === 'j' ? i + 1 : Math.max(0, i - 1)];
    if (next) {
      openMessage(next);
      $(`#list .row[data-i="${state.messages.indexOf(next)}"]`)?.scrollIntoView({ block: 'nearest' });
    }
  } else if (ev.key === 'Escape') selectAll(false);
  else if (ev.key === 'x' && i >= 0) pick(i, false);
  else if (m && ev.key === 'e') actOnCurrent('archive');
  else if (m && ev.key === '#') actOnCurrent('delete');
  else if (m && ev.key === 's') actOnCurrent('flag');
  else if (m && ev.key === 'u') actOnCurrent('unread');
});

// ---------- write: new message, reply, forward ----------

const compose = { mode: 'new', ref: null, files: [], initial: '' };

function htmlToText(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  doc.querySelectorAll('style,script,head,title').forEach((n) => n.remove());
  doc.querySelectorAll('br').forEach((n) => n.replaceWith('\n'));
  doc.querySelectorAll('p,div,tr,li,h1,h2,h3,h4,h5,h6,blockquote,table').forEach((n) => n.append('\n'));
  return (doc.body?.textContent || '').replace(/[ \t\u00a0]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
}

const fmtAddr = (a) => (a.name ? `${a.name} <${a.email}>` : a.email);

function addrList(list, skip) {
  const seen = new Set(skip.map((x) => x.toLowerCase()));
  const out = [];
  for (const a of list) {
    const k = a.email.toLowerCase();
    if (seen.has(k)) continue;
    seen.add(k);
    out.push(fmtAddr(a));
  }
  return out.join(', ');
}

function composeSnapshot() {
  return ['#cTo', '#cCc', '#cBcc', '#cSubject', '#cBody'].map((s) => $(s).value).join('\u0000') + compose.files.length;
}

function openCompose(mode) {
  const mail = state.accounts.filter((a) => a.type === 'mail');
  if (!mail.length) return toast('Add a mail account first');
  const ref = mode === 'new' ? null : state.msg;
  if (mode !== 'new' && !ref) return;
  Object.assign(compose, { mode, ref, files: [] });

  const fromId = ref ? ref.acc : (account(state.acc)?.type === 'mail' ? state.acc : mail[0].id);
  $('#cFrom').innerHTML = mail.map((a) => `<option value="${esc(a.id)}"${a.id === fromId ? ' selected' : ''}>${esc(a.name)}${a.email ? ' · ' + esc(a.email) : ''}</option>`).join('');
  ['#cTo', '#cCc', '#cBcc', '#cSubject', '#cBody'].forEach((s) => ($(s).value = ''));
  $('#cCcRow').hidden = true;
  $('#cBccRow').hidden = true;
  $('#cMoreBtn').hidden = false;
  $('#cErr').hidden = true;

  let title = 'New message';
  if (ref) {
    const m = ref.msg;
    const subj = m.subject || '';
    const original = (m.text || (m.html ? htmlToText(m.html) : '')).trimEnd();
    const when = fmtDate(m.date, true);
    if (mode === 'forward') {
      title = 'Forward';
      $('#cSubject').value = /^(fwd?|tr)\s*:/i.test(subj) ? subj : 'Fwd: ' + subj;
      $('#cBody').value = `\n\n---------- Forwarded message ----------\nFrom: ${fmtAddr(m.from)}\nDate: ${when}\nSubject: ${subj}\nTo: ${m.to}${m.cc ? '\nCc: ' + m.cc : ''}\n\n${original}`;
    } else {
      title = mode === 'replyall' ? 'Reply all' : 'Reply';
      const me = [m.me || ''];
      const back = m.reply_to?.length ? m.reply_to : [m.from];
      $('#cTo').value = addrList(back, mode === 'replyall' ? me : []);
      if (mode === 'replyall') {
        const cc = addrList([...m.to_list, ...m.cc_list], [...me, ...back.map((a) => a.email)]);
        if (cc) { $('#cCc').value = cc; $('#cCcRow').hidden = false; }
      }
      $('#cSubject').value = /^(re|réf?)\s*:/i.test(subj) ? subj : 'Re: ' + subj;
      const quoted = original.split('\n').map((l) => '> ' + l).join('\n');
      $('#cBody').value = `\n\n${when ? 'On ' + when + ', ' : ''}${fmtAddr(m.from)} wrote:\n${quoted}`;
    }
  }
  $('#composeTitle').textContent = title;
  renderFiles();
  compose.initial = composeSnapshot();
  $('#composeModal').hidden = false;
  const focus = !$('#cTo').value ? '#cTo' : '#cBody';
  $(focus).focus();
  if (focus === '#cBody') $('#cBody').setSelectionRange(0, 0);
  $('#cBody').scrollTop = 0;
}

function renderFiles() {
  const own = compose.files.map((f, i) => `<span class="c-file">${ICON.clip}<span class="n">${esc(f.name)}</span><span class="s">${fmtSize(f.size)}</span>
    <button type="button" data-rm="${i}" title="Remove"><svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button></span>`).join('');
  const orig = compose.mode === 'forward' && compose.ref?.msg.attachments.length
    ? `<span class="c-file">${ICON.clip}<span class="n">${compose.ref.msg.attachments.length > 1 ? compose.ref.msg.attachments.length + ' original attachments' : '1 original attachment'}</span></span>` : '';
  $('#cFiles').innerHTML = orig + own;
  $$('#cFiles [data-rm]').forEach((b) => b.addEventListener('click', () => { compose.files.splice(+b.dataset.rm, 1); renderFiles(); }));
}

function closeCompose(force) {
  if (!force && composeSnapshot() !== compose.initial && !confirm('Close and discard this draft?')) return;
  $('#composeModal').hidden = true;
}

async function sendCompose() {
  const btn = $('#cSend');
  const err = $('#cErr');
  err.hidden = true;
  if (!$('#cTo').value.trim() && !$('#cCc').value.trim() && !$('#cBcc').value.trim()) {
    err.textContent = 'Add at least one recipient';
    err.hidden = false;
    $('#cTo').focus();
    return;
  }
  if (!$('#cSubject').value.trim() && !confirm('Send without a subject?')) return;
  const fd = new FormData();
  for (const k of ['from', 'to', 'cc', 'bcc', 'subject', 'body']) fd.append(k, $('#composeForm').elements[k].value);
  if (compose.ref) {
    fd.append('mode', compose.mode === 'forward' ? 'forward' : 'reply');
    fd.append('ref_acc', compose.ref.acc);
    fd.append('ref_box', compose.ref.box);
    fd.append('ref_uid', compose.ref.uid);
  }
  compose.files.forEach((f) => fd.append('files[]', f, f.name));
  btn.disabled = true;
  btn.querySelector('span').textContent = 'Sending…';
  try {
    const res = await fetch('?api=send', { method: 'POST', body: fd, headers: { 'X-MailApp': '1' } });
    const data = await res.json().catch(() => ({ error: res.status === 413 ? 'Attachments too large' : `Server error (${res.status})` }));
    if (data.code === 'auth') { location.reload(); return; }
    if (!res.ok || data.error) throw new Error(data.error || 'Error ' + res.status);
    closeCompose(true);
    toast(data.warning || 'Message sent');
  } catch (e) {
    err.textContent = e.message;
    err.hidden = false;
  } finally {
    btn.disabled = false;
    btn.querySelector('span').textContent = 'Send';
  }
}

$('#composeBtn').addEventListener('click', () => openCompose('new'));
$('#replyBtn').addEventListener('click', () => openCompose('reply'));
$('#replyAllBtn').addEventListener('click', () => openCompose('replyall'));
$('#forwardBtn').addEventListener('click', () => openCompose('forward'));
$('#composeClose').addEventListener('click', () => closeCompose());
$('#cDiscard').addEventListener('click', () => closeCompose());
$('#composeModal').addEventListener('mousedown', (ev) => { if (ev.target.id === 'composeModal') closeCompose(); });
$('#cMoreBtn').addEventListener('click', () => { $('#cCcRow').hidden = false; $('#cBccRow').hidden = false; $('#cMoreBtn').hidden = true; $('#cCc').focus(); });
$('#cAttach').addEventListener('change', (ev) => {
  compose.files.push(...ev.target.files);
  ev.target.value = '';
  const total = compose.files.reduce((t, f) => t + f.size, 0);
  if (total > 20 * 1024 * 1024) toast('Over 20 MB: the server may refuse to send it');
  renderFiles();
});
$('#composeForm').addEventListener('submit', (ev) => { ev.preventDefault(); sendCompose(); });
$('#composeForm').addEventListener('keydown', (ev) => {
  if (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey)) { ev.preventDefault(); sendCompose(); }
  else if (ev.key === 'Escape') { ev.preventDefault(); closeCompose(); }
});
// Drop files anywhere on the compose window to attach them.
$('#composeForm').addEventListener('dragover', (ev) => ev.preventDefault());
$('#composeForm').addEventListener('drop', (ev) => {
  ev.preventDefault();
  if (ev.dataTransfer.files.length) { compose.files.push(...ev.dataTransfer.files); renderFiles(); }
});

// ---------- add account ----------

const PROV_ICON = {
  gmail: '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></svg>',
  outlook: '<b>O</b>',
  yahoo: '<b>Y!</b>',
  icloud: '<svg viewBox="0 0 24 24"><path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.78A6 6 0 0 0 4.5 12.5 3.5 3.5 0 0 0 6 19z"/></svg>',
  whatsapp: '<svg viewBox="0 0 24 24"><path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9z"/><path d="M9.5 9.5c0 2.8 2.2 5 5 5"/></svg>',
  imap: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
};
const PROVIDERS = [
  { id: 'gmail', name: 'Gmail', color: '#ea4335', help: 'https://myaccount.google.com/apppasswords', helpText: 'Create a Google app password (2-Step Verification must be on).' },
  { id: 'outlook', name: 'Outlook / Hotmail', color: '#0078d4' },
  { id: 'yahoo', name: 'Yahoo Mail', color: '#6001d2', help: 'https://login.yahoo.com/account/security', helpText: 'Yahoo → Account security → Generate app password.' },
  { id: 'icloud', name: 'iCloud Mail', color: '#3693f3', help: 'https://account.apple.com', helpText: 'Apple Account → Sign-In and Security → App-Specific Passwords.' },
  { id: 'whatsapp', name: 'WhatsApp', color: '#25d366' },
  { id: 'imap', name: 'Other (IMAP)', color: '#6b6b73', helpText: 'For your own domain (DreamHost: imap.dreamhost.com) or any other provider.' },
];
let msClientKnown = false;

function provGrid(filter = '') {
  const f = filter.trim().toLowerCase();
  const items = PROVIDERS.filter((p) => !f || p.name.toLowerCase().includes(f));
  $('#provGrid').innerHTML = items.map((p) => `
    <button type="button" class="prov" data-prov="${p.id}"><span class="plogo" style="--c:${p.color}">${PROV_ICON[p.id]}</span>${esc(p.name)}</button>`).join('')
    || '<p class="sub">No results. Try “Other (IMAP)”.</p>';
  $$('#provGrid .prov').forEach((b) => b.addEventListener('click', () => addStep(b.dataset.prov)));
}

function openAdd() {
  $('#addModal').hidden = false;
  $('#addPick').hidden = false;
  $('#addForm').hidden = true;
  $('#addSubmit').hidden = true;
  $('#addSearch').value = '';
  provGrid();
  setTimeout(() => $('#addSearch').focus(), 30);
  api({ api: 'info' }).then((i) => (msClientKnown = i.ms_client_id)).catch(() => {});
}
function closeAdd() { $('#addModal').hidden = true; }

const field = (name, label, type = 'text', extra = '') =>
  `<label class="field"><span>${label}</span><input name="${name}" type="${type}" ${extra}></label>`;

function addStep(id) {
  const p = PROVIDERS.find((x) => x.id === id);
  $('#addPick').hidden = true;
  $('#addForm').hidden = false;
  $('#addSubmit').hidden = false;
  $('#addSubmit').textContent = id === 'outlook' ? 'Continue with Microsoft' : 'Add';
  $('#formErr').hidden = true;
  $('#formLogo').style.setProperty('--c', p.color);
  $('#formLogo').innerHTML = PROV_ICON[id];
  $('#formTitle').textContent = p.name;
  $('#addForm').dataset.prov = id;
  let html = '';
  const help = p.help ? `<small>${esc(p.helpText)} <a href="${p.help}" target="_blank" rel="noopener">Open</a></small>` : '';
  if (id === 'whatsapp') {
    html = `<div class="hint">WhatsApp has no mailbox: MailApp adds a shortcut that opens <b>WhatsApp Web</b> in its own window. Scan the QR code with your phone the first time.</div>`;
  } else if (id === 'outlook') {
    html = field('email', 'Email address', 'email', 'required placeholder="you@hotmail.com"')
      + (msClientKnown ? '' : field('client_id', 'Microsoft client ID', 'text', 'required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"').replace('</label>', '<small>The “Application (client) ID” of your app on entra.microsoft.com (see README).</small></label>'))
      + `<div class="hint">You’ll be sent to Microsoft to sign in. No password is stored.</div>`;
  } else {
    html = field('email', 'Email address', 'email', 'required placeholder="you@example.com"')
      + `<label class="field"><span>${id === 'imap' ? 'Password' : 'App password'}</span><input name="pass" type="password" required>${help}</label>`;
    if (id === 'imap') {
      html += `<div class="row2">${field('host', 'IMAP server', 'text', 'required placeholder="imap.dreamhost.com"')}${field('port', 'Port', 'number', 'value="993" min="1" max="65535"')}</div>`
        + `<small class="field" style="color:var(--text-3);margin-top:-6px">${esc(p.helpText)}</small>`;
    }
  }
  html += field('name', 'Display name (optional)', 'text', `placeholder="${esc(p.name.split(' ')[0])}" maxlength="40"`);
  $('#formFields').innerHTML = html;
  setTimeout(() => $('#formFields input')?.focus(), 30);
}

$('#addForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const btn = $('#addSubmit');
  const label = btn.textContent;
  btn.disabled = true;
  btn.textContent = $('#addForm').dataset.prov === 'outlook' || $('#addForm').dataset.prov === 'whatsapp' ? 'One moment…' : 'Checking…';
  $('#formErr').hidden = true;
  try {
    const data = Object.fromEntries(new FormData($('#addForm')));
    data.provider = $('#addForm').dataset.prov;
    const res = await api({ api: 'add' }, data);
    if (res.oauth) { location.href = 'oauth.php?acc=' + encodeURIComponent(res.id); return; }
    closeAdd();
    toast('Account added');
    if (data.provider !== 'whatsapp') state.acc = res.id;
    loadAccounts();
  } catch (e) {
    $('#formErr').textContent = e.message;
    $('#formErr').hidden = false;
  } finally {
    btn.disabled = false;
    btn.textContent = label;
  }
});
$('#addBtn').addEventListener('click', openAdd);
$('#logoutBtn').addEventListener('click', async () => {
  await api({ api: 'logout' }, {}).catch(() => {});
  location.reload();
});
$('#addBack').addEventListener('click', () => { $('#addPick').hidden = false; $('#addForm').hidden = true; $('#addSubmit').hidden = true; });
$('#addCancel').addEventListener('click', closeAdd);
$('#addModal').addEventListener('click', (ev) => { if (ev.target.id === 'addModal') closeAdd(); });
$('#addSearch').addEventListener('input', (ev) => provGrid(ev.target.value));
document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && !$('#addModal').hidden) closeAdd(); });

loadAccounts();
