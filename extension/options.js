// Options: one field, the Better-Cal address. Saving stores it (synced
// across the person's Chrome profiles) and applies the redirect rules for it.

const input = document.getElementById('origin');
const status = document.getElementById('status');

function say(text, kind) {
  status.textContent = text;
  status.className = kind || '';
}

async function load() {
  const origin = await storedOrigin();
  input.value = origin || '';
  say(origin ? 'Redirecting "Add to Google Calendar" links to ' + origin + '.' : 'Not redirecting: no address set.', origin ? 'ok' : '');
}

document.getElementById('save').addEventListener('click', async () => {
  const origin = normalizeOrigin(input.value);
  if (!origin) {
    say('Enter the address of your Better-Cal, for example https://cal.example.com', 'err');
    return;
  }
  try {
    await applyOrigin(origin);
    await chrome.storage.sync.set({ origin });
    input.value = origin;
    say('Saved. Redirecting "Add to Google Calendar" links to ' + origin + '.', 'ok');
  } catch (e) {
    say('Could not apply the redirect: ' + (e && e.message ? e.message : e), 'err');
  }
});

document.getElementById('clear').addEventListener('click', async () => {
  await applyOrigin(null);
  await chrome.storage.sync.remove('origin');
  input.value = '';
  say('Not redirecting: no address set.');
});

// The new-event key (2.1): none by default. Chrome owns the key; this page
// shows it and links to where it is set, changed or removed.
const keyName = document.getElementById('keyName');

async function loadKey() {
  const cmd = (await chrome.commands.getAll()).find((c) => c.name === 'new-event');
  keyName.textContent = cmd && cmd.shortcut ? 'Key: ' + cmd.shortcut : 'No key set';
}

// Back from Chrome's shortcut page: show the key as it now is.
document.addEventListener('visibilitychange', () => { if (!document.hidden) loadKey(); });

document.getElementById('keys').addEventListener('click', () => {
  chrome.tabs.create({ url: 'chrome://extensions/shortcuts' });
});

input.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') document.getElementById('save').click();
});

load();
loadKey();
