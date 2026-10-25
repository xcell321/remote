(() => {
  'use strict';

  const state = {
    activeDeviceId: window.REMOTE_APP?.activeDeviceId ?? null,
    toastTimer: null,
    // Volume / channel / source are tracked PER DEVICE (keyed by device_id),
    // so switching devices no longer mixes their state together.
    deviceState: {},
  };

  function deviceState(id) {
    const key = String(id);
    if (!state.deviceState[key]) {
      const device = devices().find((item) => Number(item.device_id) === Number(id));
      state.deviceState[key] = { volume: 24, channel: 7, source: device?.source_input || 'HDMI 1' };
    }
    return state.deviceState[key];
  }

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const devices = () => window.REMOTE_APP?.devices ?? [];
  const activeDevice = () => devices().find((device) => Number(device.device_id) === Number(state.activeDeviceId));

  function updateLocalDateTime() {
    const now = new Date();
    const hour = now.getHours();
    const period = hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening';
    const date = new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric' }).format(now);
    const dateElement = $('#currentDate');
    const greetingElement = $('#greetingPeriod');
    if (dateElement) dateElement.textContent = date;
    if (greetingElement) greetingElement.textContent = period;
  }

  function showToast(message, isError = false) {
    const toast = $('#toast');
    if (!toast) return;
    $('#toastMessage').textContent = message;
    toast.style.borderColor = isError ? 'rgba(255,121,121,.3)' : '';
    toast.style.background = isError ? '#2a1818' : '';
    toast.style.color = isError ? '#ffb3ad' : '';
    toast.classList.add('show');
    window.clearTimeout(state.toastTimer);
    state.toastTimer = window.setTimeout(() => toast.classList.remove('show'), 2800);
  }

  function openModal(id) {
    if (id === 'settingsModal') closeModal('profileModal');
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    const focusable = $('input, select, button', modal);
    focusable?.focus();
  }

  function closeModal(modal) {
    const element = typeof modal === 'string' ? document.getElementById(modal) : modal;
    if (!element) return;
    element.hidden = true;
    if (!$$('.modal-backdrop:not([hidden])').length) document.body.style.overflow = '';
  }

  function setActiveDevice(id) {
    const device = devices().find((item) => Number(item.device_id) === Number(id));
    if (!device) return;
    state.activeDeviceId = Number(device.device_id);
    $$('.device-card').forEach((card) => card.classList.toggle('selected', Number(card.dataset.deviceId) === state.activeDeviceId));
    const name = $('#activeDeviceName');
    const meta = $('#activeDeviceMeta');
    const emblem = $('#deviceEmblem');
    if (name) name.textContent = device.device_name;
    if (meta) meta.textContent = `${device.brand} · ${device.device_type}`;
    if (emblem) emblem.textContent = device.device_type.toLowerCase().includes('audio') ? 'A' : device.device_type.toLowerCase().includes('projector') ? 'P' : 'TV';
    renderApps(device);
    const ds = deviceState(device.device_id);
    $('#volumeValue').textContent = String(ds.volume);
    $('#channelValue').textContent = String(ds.channel).padStart(2, '0');
    // Audio devices (soundbar/speaker) have no channels or D-pad menus:
    // hide those controls so the remote adapts to the device type.
    const panel = $('.remote-panel');
    if (panel) {
      const isAudio = device.device_type.toLowerCase().includes('audio')
        || device.device_type.toLowerCase().includes('soundbar')
        || device.device_type.toLowerCase().includes('speaker');
      panel.classList.toggle('audio-mode', isAudio);
    }
    $('#commandFeedback').textContent = device.connection_status === 'offline' ? 'This device is offline. Commands will be sent when it reconnects.' : 'Select a control to send a command.';
  }

  function renderApps(device) {
    const row = $('.quick-row');
    if (!row) return;
    const shortcuts = device.apps ?? [];
    row.innerHTML = `<button class="quick-chip active" type="button" data-app-filter="all">All apps</button>` + shortcuts.map((app) => {
      return `<button class="quick-chip" type="button" data-app-filter="${escapeHtml(String(app.app_name).toLowerCase())}" data-app-name="${escapeHtml(app.app_name)}">${escapeHtml(app.app_name)}</button>`;
    }).join('') + '<button class="quick-chip" type="button" data-open="add-shortcut"><span aria-hidden="true">＋</span>Add shortcut</button>';
    bindAppChips();
  }

  function escapeHtml(value) {
    return value.replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));
  }

  async function api(payload) {
    const response = await fetch('backend/api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.REMOTE_APP.csrfToken },
      body: JSON.stringify(payload),
    });
    const result = await response.json().catch(() => ({ ok: false, message: 'The server returned an invalid response.' }));
    if (!response.ok || !result.ok) throw new Error(result.message || 'Request failed.');
    return result.data;
  }

  async function apiGet(action) {
    const response = await fetch(`backend/api.php?action=${encodeURIComponent(action)}`);
    const result = await response.json().catch(() => ({ ok: false, message: 'The server returned an invalid response.' }));
    if (!response.ok || !result.ok) throw new Error(result.message || 'Request failed.');
    return result.data;
  }

  async function sendCommand(command, value = null) {
    if (!state.activeDeviceId) {
      showToast('Add a device before sending commands.', true);
      return;
    }
    if (activeDevice()?.connection_status === 'offline') {
      const message = 'This device is disconnected. Connect it before sending commands.';
      $('#commandFeedback').textContent = message;
      showToast(message, true);
      return;
    }
    const feedback = $('#commandFeedback');
    feedback.textContent = 'Sending command…';
    try {
      const result = await api({ action: 'command', deviceId: state.activeDeviceId, command, value });
      feedback.textContent = result.message;
      const ds = deviceState(state.activeDeviceId);
      if (command === 'volume-up') ds.volume = Math.min(100, ds.volume + 1);
      if (command === 'volume-down') ds.volume = Math.max(0, ds.volume - 1);
      if (command === 'channel-up') ds.channel = Math.min(999, ds.channel + 1);
      if (command === 'channel-down') ds.channel = Math.max(1, ds.channel - 1);
      $('#volumeValue').textContent = String(ds.volume);
      $('#channelValue').textContent = String(ds.channel).padStart(2, '0');
      addActivity(result.message, activeDevice()?.device_name || 'Active device');
    } catch (error) {
      feedback.textContent = error.message;
      showToast(error.message, true);
    }
  }

  function addActivity(action, deviceName) {
    const list = $('#activityList');
    if (!list) return;
    const empty = $('.empty-activity', list);
    empty?.remove();
    const row = document.createElement('div');
    row.className = 'activity-row';
    row.innerHTML = `<div class="activity-mark" aria-hidden="true">⌁</div><div class="activity-copy"><strong>${escapeHtml(action)}</strong><span>${escapeHtml(deviceName)} · Just now</span></div>`;
    list.prepend(row);
    while ($$('.activity-row', list).length > 8) list.lastElementChild.remove();
  }

  function bindAppChips() {
    $$('[data-app-filter]').forEach((chip) => {
      chip.addEventListener('click', () => {
        $$('[data-app-filter]').forEach((item) => item.classList.remove('active'));
        chip.classList.add('active');
        const name = chip.dataset.appFilter;
        // A real app chip now SENDS the open-app command (queued for the
        // home agent, which launches the app on the TV). "All apps" is
        // only a filter reset.
        if (name !== 'all' && chip.dataset.appName) {
          sendCommand('open-app', chip.dataset.appName);
        }
      });
    });
  }

  function bindNavigation() {
    $$('[data-view]').forEach((button) => button.addEventListener('click', () => {
      const target = button.dataset.view;
      const element = document.getElementById(`${target}View`);
      element?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      $$('[data-view]').forEach((item) => item.classList.toggle('active', item.dataset.view === target));
    }));
  }

  function bindSearch() {
    $('#searchInput')?.addEventListener('input', (event) => {
      const query = event.target.value.trim().toLowerCase();
      let visible = 0;
      $$('.device-card').forEach((card) => {
        const matches = !query || card.dataset.deviceName.includes(query);
        // Hide the whole row (card + its Connect/Remove actions), not
        // just the card button - otherwise filtered rows stay visible.
        const row = card.closest('.device-row');
        if (row) row.hidden = !matches;
        if (matches) visible += 1;
      });
      const connectedCount = devices().filter((device) => device.connection_status !== 'offline').length;
      $('#deviceCount').textContent = query ? `${visible} result${visible === 1 ? '' : 's'}` : `${connectedCount} connected`;
    });
  }

  function bindCustomSelects() {
    $$('[data-custom-select]').forEach((select) => {
      const trigger = $('[data-select-trigger]', select);
      const menu = $('.select-menu', select);
      const valueInput = $('[data-select-value]', select);
      const label = $('[data-select-label]', select);
      if (!trigger || !menu || !valueInput || !label) return;

      const close = () => {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
      };
      const open = () => {
        $$('[data-custom-select]').forEach((other) => {
          if (other !== select) $('[data-select-trigger]', other)?.setAttribute('aria-expanded', 'false');
          if (other !== select) { const otherMenu = $('.select-menu', other); if (otherMenu) otherMenu.hidden = true; }
        });
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
      };

      trigger.addEventListener('click', () => (menu.hidden ? open() : close()));
      $$('[data-select-option]', menu).forEach((option) => option.addEventListener('click', () => {
        valueInput.value = option.dataset.value ?? '';
        label.textContent = option.textContent.trim();
        $$('[data-select-option]', menu).forEach((item) => item.setAttribute('aria-selected', String(item === option)));
        close();
      }));
      trigger.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); menu.hidden ? open() : close(); }
        if (event.key === 'Escape') close();
      });
    });
    document.addEventListener('click', (event) => {
      if (!event.target.closest('[data-custom-select]')) {
        $$('[data-custom-select]').forEach((select) => {
          const menu = $('.select-menu', select);
          const trigger = $('[data-select-trigger]', select);
          if (menu) menu.hidden = true;
          trigger?.setAttribute('aria-expanded', 'false');
        });
      }
    });
  }

  function bindModals() {
    // Delegated on purpose: the quick-row (with its "Add shortcut" chip)
    // is re-rendered whenever the device changes, so per-button binding
    // would be lost. One document listener covers present + future chips.
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-open]');
      if (!button) return;
      const target = button.dataset.open === 'add-device' ? 'addDeviceModal'
        : button.dataset.open === 'add-shortcut' ? 'addShortcutModal'
        : button.dataset.open === 'profile' ? 'profileModal' : 'settingsModal';
      openModal(target);
    });
    $$('[data-close-modal]').forEach((button) => button.addEventListener('click', () => closeModal(button.closest('.modal-backdrop'))));
    $$('.modal-backdrop').forEach((backdrop) => backdrop.addEventListener('click', (event) => {
      if (event.target === backdrop) closeModal(backdrop);
    }));
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') $$('.modal-backdrop:not([hidden])').forEach(closeModal);
    });
  }

  function bindDeviceCards() {
    $$('.device-card').forEach((card) => card.addEventListener('click', () => setActiveDevice(card.dataset.deviceId)));
  }

  async function toggleConnection(button) {
    const deviceId = Number(button.dataset.deviceId);
    const deviceName = button.dataset.deviceLabel || 'this device';
    const action = button.dataset.connectionAction;
    if (action === 'disconnect' && !window.confirm(`Disconnect ${deviceName}?`)) return;
    button.disabled = true;
    button.textContent = action === 'connect' ? 'Connecting…' : 'Disconnecting…';
    try {
      const result = await api({ action, deviceId });
      const device = devices().find((item) => Number(item.device_id) === deviceId);
      const connected = action === 'connect';
      if (device) device.connection_status = connected ? 'connected' : 'offline';
      const row = button.closest('.device-row');
      const status = $('.device-status', row);
      if (status) {
        status.classList.toggle('offline', !connected);
        status.innerHTML = `<span class="status-dot" aria-hidden="true"></span>${connected ? 'Connected' : 'Offline'}`;
      }
      button.dataset.connectionAction = connected ? 'disconnect' : 'connect';
      button.classList.toggle('connect', connected);
      button.classList.toggle('disconnect', !connected);
      button.textContent = connected ? 'Disconnect' : 'Connect';
      button.disabled = false;
      const connectedCount = devices().filter((item) => item.connection_status !== 'offline').length;
      $('#deviceCount').textContent = `${connectedCount} connected`;
      if (state.activeDeviceId === deviceId) {
        $('#commandFeedback').textContent = connected ? 'Device connected. Select a control to send a command.' : 'This device is disconnected. Connect it before sending commands.';
      }
      addActivity(result.message, deviceName);
      showToast(`${deviceName} ${connected ? 'connected' : 'disconnected'}.`);
    } catch (error) {
      button.disabled = false;
      button.textContent = action === 'connect' ? 'Connect' : 'Disconnect';
      showToast(error.message, true);
    }
  }

  function bindConnectionButtons() {
    $$('[data-connection-action]').forEach((button) => button.addEventListener('click', (event) => {
      event.stopPropagation();
      toggleConnection(button);
    }));
  }

  async function removeDevice(button) {
    const deviceId = Number(button.dataset.removeDevice);
    const deviceName = button.dataset.deviceLabel || 'this device';
    if (!window.confirm(`Remove ${deviceName} permanently?`)) return;
    button.disabled = true;
    button.textContent = 'Removing…';
    try {
      await api({ action: 'remove-device', deviceId });
      const removedWasActive = Number(state.activeDeviceId) === deviceId;
      window.REMOTE_APP.devices = devices().filter((device) => Number(device.device_id) !== deviceId);
      button.closest('.device-row')?.remove();
      const remaining = devices();
      const connected = remaining.filter((device) => device.connection_status !== 'offline').length;
      $('#deviceCount').textContent = `${connected} connected`;
      if (removedWasActive) {
        state.activeDeviceId = remaining[0]?.device_id ?? null;
        if (remaining[0]) setActiveDevice(remaining[0].device_id);
        else $('#commandFeedback').textContent = 'Add a device to start sending commands.';
      }
      addActivity('Device removed', deviceName);
      showToast(`${deviceName} removed.`);
    } catch (error) {
      button.disabled = false;
      button.textContent = 'Remove';
      showToast(error.message, true);
    }
  }

  function bindRemoveButtons() {
    $$('[data-remove-device]').forEach((button) => button.addEventListener('click', (event) => {
      event.stopPropagation();
      removeDevice(button);
    }));
  }

  function bindCommands() {
    $$('[data-command]').forEach((button) => button.addEventListener('click', () => sendCommand(button.dataset.command)));
  }

  function bindAddDevice() {
    $('#addDeviceForm')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const message = $('#formMessage');
      message.textContent = '';
      if (!form.checkValidity() || !$('#deviceType').value) {
        message.textContent = 'Complete all fields before adding the device.';
        form.reportValidity();
        return;
      }
      const formData = new FormData(form);
      const submit = $('button[type="submit"]', form);
      submit.disabled = true;
      submit.textContent = 'Adding…';
      try {
        await api({ action: 'add-device', name: formData.get('name'), type: formData.get('type'), brand: formData.get('brand') });
        closeModal('addDeviceModal');
        showToast('Device added. Refreshing your workspace…');
        window.setTimeout(() => window.location.reload(), 650);
      } catch (error) {
        message.textContent = error.message;
      } finally {
        submit.disabled = false;
        submit.textContent = 'Add device';
      }
    });
  }

  function bindSourceButton() {
    $('#sourceButton')?.addEventListener('click', async () => {
      if (!state.activeDeviceId) {
        showToast('Add a device before sending commands.', true);
        return;
      }
      if (activeDevice()?.connection_status === 'offline') {
        showToast('This device is disconnected. Connect it before sending commands.', true);
        return;
      }
      const inputs = ['HDMI 1', 'HDMI 2', 'HDMI 3', 'TV', 'AV', 'Optical'];
      const ds = deviceState(state.activeDeviceId);
      const next = inputs[(inputs.indexOf(ds.source) + 1) % inputs.length] || inputs[0];
      const feedback = $('#commandFeedback');
      feedback.textContent = 'Switching input…';
      try {
        // set-source persists the device's input AND queues the real
        // command for the home agent (via runCommand on the server).
        const result = await api({ action: 'set-source', deviceId: state.activeDeviceId, source: next });
        ds.source = next;
        const device = activeDevice();
        if (device) device.source_input = next;
        feedback.textContent = result.message;
        addActivity(result.message, device?.device_name || 'Active device');
        showToast(result.message);
      } catch (error) {
        feedback.textContent = error.message;
        showToast(error.message, true);
      }
    });
  }

  function bindAddShortcut() {
    $('#addShortcutForm')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const message = $('#shortcutMessage');
      message.textContent = '';
      if (!state.activeDeviceId) {
        message.textContent = 'Choose a device first.';
        return;
      }
      if (!form.checkValidity()) {
        message.textContent = 'Enter an app name (2-80 characters).';
        form.reportValidity();
        return;
      }
      const submit = $('button[type="submit"]', form);
      submit.disabled = true;
      submit.textContent = 'Adding…';
      try {
        await api({ action: 'add-app', deviceId: state.activeDeviceId, name: new FormData(form).get('name') });
        closeModal('addShortcutModal');
        showToast('Shortcut added. Refreshing…');
        window.setTimeout(() => window.location.reload(), 650);
      } catch (error) {
        message.textContent = error.message;
      } finally {
        submit.disabled = false;
        submit.textContent = 'Add shortcut';
      }
    });
  }

  function bindAgentStatus() {
    // Poll the home agent's heartbeat so the topbar pill tells the
    // truth: green when the agent at home is running, marked offline
    // otherwise (commands still queue and run when it comes back).
    const pill = $('.connection-pill');
    if (!pill) return;
    const label = pill.querySelector('span:last-child');
    const check = async () => {
      try {
        const data = await apiGet('agent-status');
        pill.classList.toggle('agent-offline', !data.agentOnline);
        if (label) label.textContent = data.agentOnline ? 'All systems ready' : 'Home agent offline';
      } catch (error) {
        /* keep the last known state on network hiccups */
      }
    };
    check();
    window.setInterval(check, 15000);
  }

  function bindProfileActions() {
    $$('[data-profile-action]').forEach((button) => button.addEventListener('click', () => {
      if (button.dataset.profileAction === 'clear-search') {
        const input = $('#searchInput');
        if (input) {
          input.value = '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        closeModal('profileModal');
        showToast('Search cleared.');
      }
    }));
  }

  function bindTheme() {
    $('#saveTheme')?.addEventListener('click', async () => {
      const message = $('#settingsMessage');
      message.textContent = 'Saving…';
      try {
        await api({ action: 'theme', deviceId: state.activeDeviceId, themeId: Number($('#themeSelect').value) });
        message.textContent = 'Theme saved for this device.';
        showToast('Theme saved.');
      } catch (error) {
        message.textContent = error.message;
      }
    });
  }

  function bindAuth() {
    // Login / register live on the guest page only; every lookup is
    // guarded, so this is a no-op on the remote page.
    $$('[data-auth-show]').forEach((button) => button.addEventListener('click', () => {
      const target = button.dataset.authShow;
      const login = $('#loginPanel');
      const register = $('#registerPanel');
      if (login) login.hidden = target !== 'login';
      if (register) register.hidden = target !== 'register';
    }));
    $('#loginForm')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const message = $('#loginMessage');
      message.textContent = '';
      if (!form.checkValidity()) { form.reportValidity(); return; }
      const data = new FormData(form);
      const submit = $('button[type="submit"]', form);
      submit.disabled = true;
      submit.textContent = 'Logging in…';
      try {
        await api({ action: 'login', email: data.get('email'), password: data.get('password') });
        window.location.reload();
      } catch (error) {
        message.textContent = error.message;
        submit.disabled = false;
        submit.textContent = 'Log in';
      }
    });
    $('#registerForm')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const message = $('#registerMessage');
      message.textContent = '';
      if (!form.checkValidity()) { form.reportValidity(); return; }
      const data = new FormData(form);
      const submit = $('button[type="submit"]', form);
      submit.disabled = true;
      submit.textContent = 'Creating…';
      try {
        await api({ action: 'register', name: data.get('name'), email: data.get('email'), password: data.get('password') });
        window.location.reload();
      } catch (error) {
        message.textContent = error.message;
        submit.disabled = false;
        submit.textContent = 'Create account';
      }
    });
  }

  function bindLogout() {
    $$('[data-logout]').forEach((button) => button.addEventListener('click', async () => {
      try { await api({ action: 'logout' }); } catch (error) { /* fall through to reload */ }
      window.location.reload();
    }));
  }

  updateLocalDateTime();
  window.setInterval(updateLocalDateTime, 60000);
  bindAuth();
  bindLogout();
  bindCustomSelects();
  bindNavigation();
  bindSearch();
  bindModals();
  bindDeviceCards();
  bindConnectionButtons();
  bindRemoveButtons();
  bindCommands();
  bindSourceButton();
  bindAddDevice();
  bindAddShortcut();
  bindAgentStatus();
  bindProfileActions();
  bindTheme();
  bindAppChips();
})();
