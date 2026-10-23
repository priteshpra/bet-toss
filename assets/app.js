const $ = (id) => document.getElementById(id);
const seen = new Set(JSON.parse(localStorage.getItem('tga_seen') || '[]'));
let primed = false;
let pollTimer = null;
let deferredPrompt = null;
let audioCtx = null;
let currentUsers = [];

try {
  const savedUsersRaw = localStorage.getItem('tga_users');
  if (savedUsersRaw !== null) {
    const list = JSON.parse(savedUsersRaw);
    if (Array.isArray(list)) {
      currentUsers = list;
    }
  }
} catch (e) {}

function saveSeen() {
  localStorage.setItem('tga_seen', JSON.stringify([...seen].slice(-400)));
}

function unlockAudio() {
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) return null;
  if (!audioCtx) audioCtx = new AC();
  if (audioCtx.state === 'suspended') audioCtx.resume();
  return audioCtx;
}

function playBeep() {
  const ctx = unlockAudio();
  if (!ctx) return;
  const now = ctx.currentTime;
  [0, 0.38, 0.76].forEach((delay) => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'square';
    osc.frequency.setValueAtTime(140, now + delay);
    osc.frequency.exponentialRampToValueAtTime(55, now + delay + 0.22);
    osc.connect(gain);
    gain.connect(ctx.destination);
    const t0 = now + delay;
    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(0.95, t0 + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.28);
    osc.start(t0);
    osc.stop(t0 + 0.3);
  });
  navigator.vibrate?.([500, 140, 500, 140, 700]);
}

async function api(action, body) {
  const res = await fetch('api.php?action=' + encodeURIComponent(action), {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) {
    return { ok: false, error: 'HTTP ' + res.status };
  }
  const data = await res.json().catch(() => ({ ok: false, error: 'bad response' }));
  if (res.status === 401) {
    const err = new Error('pin');
    err.code = 'pin';
    throw err;
  }
  return data;
}

function startPolling() {
  if (pollTimer) return;
  pollTimer = setInterval(refresh, 8000);
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function renderFeed(bets) {
  const box = $('feed');
  if (!bets.length) {
    box.innerHTML = '<div class="empty">Abhi in users ka koi bet nahi mila. Jaise hi channel pe drop hoga, yahan dikhega aur phone par notification aayegi.</div>';
    return;
  }
  box.innerHTML = bets.map((b) => `
    <article class="bet">
      <div class="who">${esc(b.userName)} · ${esc(b.displayTime)}</div>
      <h2>${esc(b.teamName || 'Update')} · ${esc(b.amount || '')}</h2>
      <div class="raw">${esc(b.rawText)}</div>
    </article>
  `).join('');
}

function noteFresh(bets) {
  bets.forEach((bet) => {
    if (!bet?.postId || seen.has(bet.postId)) return;
    seen.add(bet.postId);
    saveSeen();
    playBeep();
  });
}

function renderUserChips() {
  const box = $('userChips');
  if (!box) return;
  if (!currentUsers.length) {
    box.innerHTML = '<span class="user-chips-empty">Abhi koi user track nahi ho raha. Niche likh kar Add dabayein.</span>';
    return;
  }
  box.innerHTML = currentUsers.map((u, idx) => `
    <span class="user-chip">
      <span>${esc(u)}</span>
      <button type="button" class="remove-chip" data-index="${idx}" title="Remove ${esc(u)}" aria-label="Remove ${esc(u)}">&times;</button>
    </span>
  `).join('');
}

async function saveAndSyncUsers() {
  renderUserChips();
  localStorage.setItem('tga_users', JSON.stringify(currentUsers));
  const addBtn = $('addUserBtn');
  const oldText = addBtn ? addBtn.textContent : 'Add';
  if (addBtn) addBtn.textContent = 'Saving...';
  try {
    await api('save_users', { targetUsers: currentUsers });
    if (addBtn) {
      addBtn.textContent = 'Saved!';
      setTimeout(() => { addBtn.textContent = oldText; }, 900);
    }
  } catch (e) {
    if (addBtn) {
      addBtn.textContent = 'Error';
      setTimeout(() => { addBtn.textContent = oldText; }, 900);
    }
  }
  refresh();
}

async function addUserFromInput() {
  const input = $('newUserInput');
  if (!input) return;
  const raw = input.value.trim();
  if (!raw) return;
  const items = raw.split(',').map((s) => s.trim()).filter(Boolean);
  let changed = false;
  for (const name of items) {
    const exists = currentUsers.some((u) => u.toLowerCase() === name.toLowerCase());
    if (!exists) {
      currentUsers.push(name);
      changed = true;
    }
  }
  input.value = '';
  if (changed) {
    await saveAndSyncUsers();
  }
}

function paint(data) {
  if (!data || !data.ok) {
    $('live').textContent = 'Retry';
    $('clock').textContent = data?.error ? String(data.error).slice(0, 24) : 'Offline';
    return;
  }
  $('live').textContent = data.status === 'connected' ? 'Live' : (data.status || 'Connecting...');
  $('clock').textContent = data.fetchedAt || '—';

  const localSaved = localStorage.getItem('tga_users');
  if (localSaved !== null) {
    try {
      const list = JSON.parse(localSaved);
      if (Array.isArray(list)) {
        currentUsers = list;
        renderUserChips();
        const serverTargets = Array.isArray(data.config?.targetUsers) ? data.config.targetUsers : [];
        if (serverTargets.join(',') !== list.join(',')) {
          api('save_users', { targetUsers: list }).catch(() => {});
        }
      }
    } catch (e) {
      if (data.config?.targetUsers) {
        currentUsers = [...data.config.targetUsers];
        renderUserChips();
      }
    }
  } else if (data.config?.targetUsers) {
    currentUsers = [...data.config.targetUsers];
    renderUserChips();
    localStorage.setItem('tga_users', JSON.stringify(currentUsers));
  }

  const link = $('phoneLink');
  if (data.tunnelUrl) {
    link.classList.remove('hidden');
    link.innerHTML = `Phone par ye link kholo:<br><a href="${esc(data.tunnelUrl)}">${esc(data.tunnelUrl)}</a>`;
  }
  const watched = data.watched || [];
  renderFeed(watched);
  if (!primed) {
    watched.forEach((b) => { if (b.postId) seen.add(b.postId); });
    saveSeen();
    primed = true;
    return;
  }
  noteFresh(data.fresh || []);
}

async function refresh() {
  try {
    paint(await api('feed'));
  } catch (e) {
    $('live').textContent = 'Retry';
  }
}

function urlBase64ToUint8Array(base64String) {
  const pad = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + pad).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

async function enableAlerts() {
  unlockAudio();
  playBeep();
  if (!window.isSecureContext) {
    $('secureNote').textContent = 'Is HTTP page par phone notification nahi chalegi. Upar wali HTTPS link phone Chrome mein kholo, phir Alerts on dabao.';
    return;
  }
  if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
    $('secureNote').textContent = 'Ye browser notification support nahi karta. Android Chrome use karo.';
    return;
  }
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    $('secureNote').textContent = 'Notification allow nahi hui. Browser setting se Allow karke dubara Alerts on dabao.';
    return;
  }
  const feed = await api('feed');
  const reg = await navigator.serviceWorker.register('sw.js');
  await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(feed.vapidPublicKey),
  });
  const saved = await api('subscribe', sub.toJSON());
  $('alertBtn').textContent = 'Alerts on';
  $('secureNote').textContent = saved.push?.sent
    ? 'Phone subscribe ho gaya. Test notification bhej di hai.'
    : 'Alerts on ho gaye. App band karke bhi bet ki notification aayegi.';
}

async function testAlert() {
  unlockAudio();
  playBeep();
  if (!window.isSecureContext) {
    $('secureNote').textContent = 'Test phone par HTTPS link khol kar karo.';
    return;
  }
  const data = await api('test');
  $('secureNote').textContent = data.push?.sent
    ? 'Test notification bhej di. Phone ki notification shade check karo.'
    : 'Pehle Alerts on dabao, taaki phone subscribe ho.';
}

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  if ($('installBtn')) {
    $('installBtn').classList.remove('hidden');
  }
});

if ($('alertBtn')) {
  $('alertBtn').addEventListener('click', () => enableAlerts().catch((e) => {
    $('secureNote').textContent = e.message || 'Alert setup fail';
  }));
}

if ($('testBtn')) {
  $('testBtn').addEventListener('click', () => testAlert().catch((e) => {
    $('secureNote').textContent = e.message || 'Test fail';
  }));
}

if ($('installBtn')) {
  $('installBtn').addEventListener('click', async () => {
    if (!deferredPrompt) {
      $('secureNote').textContent = 'Chrome menu (⋮) → Add to Home screen / Install app.';
      return;
    }
    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    deferredPrompt = null;
  });
}

if ($('userChips')) {
  $('userChips').addEventListener('click', (e) => {
    const btn = e.target.closest('.remove-chip');
    if (!btn) return;
    const idx = parseInt(btn.dataset.index, 10);
    if (!isNaN(idx) && idx >= 0 && idx < currentUsers.length) {
      currentUsers.splice(idx, 1);
      saveAndSyncUsers();
    }
  });
}

if ($('addUserBtn')) {
  $('addUserBtn').addEventListener('click', () => {
    addUserFromInput();
  });
}

if ($('newUserInput')) {
  $('newUserInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      addUserFromInput();
    }
  });
}

if ('serviceWorker' in navigator && window.isSecureContext) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data?.type === 'push') {
      playBeep();
      refresh();
    }
  });
}

if (!window.isSecureContext) {
  $('secureNote').textContent = 'Ye page PC par hai. Phone notification ke liye neeche HTTPS link aane do, use phone Chrome mein kholo.';
}

renderUserChips();

api('feed').then((data) => {
  paint(data);
  startPolling();
}).catch(() => {
  $('live').textContent = 'Retry';
});
