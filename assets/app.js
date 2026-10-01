const $ = (id) => document.getElementById(id);
const seen = new Set(JSON.parse(localStorage.getItem('tga_seen') || '[]'));
let pin = localStorage.getItem('tga_pin') || '';
let primed = false;
let pollTimer = null;
let deferredPrompt = null;
let audioCtx = null;

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
  [0, 0.22, 0.44].forEach((delay, i) => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = i === 1 ? 1174 : 880;
    osc.connect(gain);
    gain.connect(ctx.destination);
    const t0 = now + delay;
    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(0.28, t0 + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.18);
    osc.start(t0);
    osc.stop(t0 + 0.2);
  });
  navigator.vibrate?.([200, 80, 200, 80, 420]);
}

async function api(action, body) {
  const res = await fetch('api.php?action=' + encodeURIComponent(action), {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', 'X-Pin': pin },
    body: body ? JSON.stringify(body) : undefined,
  });
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

function showApp() {
  $('lock').classList.add('hidden');
  $('app').classList.remove('hidden');
}

function showLock(msg) {
  $('app').classList.add('hidden');
  $('lock').classList.remove('hidden');
  $('lockError').textContent = msg || '';
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

function paint(data) {
  $('live').textContent = data.status === 'connected' ? 'Live' : (data.status || 'Wait');
  $('clock').textContent = data.fetchedAt || '—';
  if (data.config?.targetUsers && document.activeElement !== $('users')) {
    $('users').value = data.config.targetUsers.join(', ');
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
    if (e.code === 'pin') {
      localStorage.removeItem('tga_pin');
      showLock('PIN galat hai');
      return;
    }
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
  $('installBtn').classList.remove('hidden');
});

$('lockForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  pin = $('pin').value.trim();
  localStorage.setItem('tga_pin', pin);
  try {
    paint(await api('feed'));
    showApp();
    startPolling();
  } catch (err) {
    showLock('PIN galat hai');
  }
});

$('alertBtn').addEventListener('click', () => enableAlerts().catch((e) => {
  $('secureNote').textContent = e.message || 'Alert setup fail';
}));
$('testBtn').addEventListener('click', () => testAlert().catch((e) => {
  $('secureNote').textContent = e.message || 'Test fail';
}));
$('installBtn').addEventListener('click', async () => {
  if (!deferredPrompt) {
    $('secureNote').textContent = 'Chrome menu (⋮) → Add to Home screen / Install app.';
    return;
  }
  deferredPrompt.prompt();
  await deferredPrompt.userChoice;
  deferredPrompt = null;
});
$('saveBtn').addEventListener('click', async () => {
  const users = $('users').value.split(',').map((s) => s.trim()).filter(Boolean);
  await api('save_users', { targetUsers: users });
  refresh();
});

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

if (pin) {
  $('pin').value = pin;
  api('feed').then((data) => {
    paint(data);
    showApp();
    startPolling();
  }).catch(() => showLock('PIN galat hai'));
}
