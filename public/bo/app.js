const state = {
  token: loadStoredToken(),
  user: loadStoredUser(),
  providers: [],
  plans: [],
  users: [],
  userType: 'BO',
  deferredInstall: null,
  otpRequested: false,
  loginInProgress: false,
  reloadedForVersion: wasReloadedForVersion()
};

const views = {
  overview: {
    title: "Vue d'ensemble",
    subtitle: 'Suivi opérationnel des comptes, prestataires et accès mobile.'
  },
  providers: {
    title: 'Prestataires',
    subtitle: 'Dossiers livreurs et prestataires à contrôler.'
  },
  users: {
    title: 'Utilisateurs',
    subtitle: 'Gestion des comptes BO, prestataires et clients.'
  },
  subscriptions: {
    title: 'Abonnements',
    subtitle: 'Plans et offres visibles dans l’application.'
  },
  settings: {
    title: 'Session',
    subtitle: 'Compte connecté et accès back-office.'
  }
};

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));

function loadStoredToken() {
  try {
    return localStorage.getItem('aldahim.bo.jwt') || '';
  } catch (error) {
    return '';
  }
}

function wasReloadedForVersion() {
  try {
    return sessionStorage.getItem('aldahim.bo.versionReloaded') === '10';
  } catch (error) {
    return false;
  }
}

function loadStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('aldahim.bo.user') || 'null');
  } catch (error) {
    localStorage.removeItem('aldahim.bo.user');
    return null;
  }
}

function authorizationHeader() {
  const token = state.token.trim();
  if (!token) {
    return null;
  }

  return token.startsWith('Bearer ') ? token : `Bearer ${token}`;
}

async function api(path) {
  return authorizedJson(path);
}

async function authorizedJson(path, options = {}) {
  const authorization = authorizationHeader();
  if (!authorization) {
    throw new Error('TOKEN_REQUIRED');
  }

  const response = await fetch(path, {
    method: options.method || 'GET',
    headers: {
      Accept: 'application/json',
      Authorization: authorization,
      ...(options.body ? { 'Content-Type': 'application/json' } : {})
    },
    body: options.body ? JSON.stringify(options.body) : undefined
  });

  if (response.status === 401 || response.status === 403) {
    throw new Error('FORBIDDEN');
  }

  let data = {};
  if (response.status !== 204) {
    try {
      data = await response.json();
    } catch (error) {
      data = {};
    }
  }

  if (!response.ok) {
    throw new Error(typeof data.message === 'string' ? data.message : `HTTP_${response.status}`);
  }

  return data;
}

async function postJson(path, payload) {
  const response = await fetch(path, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(payload)
  });

  let data = {};
  try {
    data = await response.json();
  } catch (error) {
    data = {};
  }

  if (!response.ok) {
    const message = typeof data.message === 'string' ? data.message : `HTTP_${response.status}`;
    throw new Error(message);
  }

  return data;
}

function setView(name) {
  const view = views[name] ? name : 'overview';
  $$('.nav-item').forEach((button) => button.classList.toggle('active', button.dataset.view === view));
  $$('[data-view-panel]').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.viewPanel !== view));
  $('[data-view-title]').textContent = views[view].title;
  $('[data-view-subtitle]').textContent = views[view].subtitle;
  history.replaceState(null, '', view === 'overview' ? '/' : `/${view}`);
}

function setAuthenticated(token, user) {
  state.token = token;
  state.user = user || null;
  try {
    localStorage.setItem('aldahim.bo.jwt', token);
    localStorage.setItem('aldahim.bo.user', JSON.stringify(state.user));
  } catch (error) {
    setLoginMessage('Session active pour cet onglet. Le stockage local est indisponible.', true);
  }
  updateAuthUi();
}

function logout() {
  state.token = '';
  state.user = null;
  state.providers = [];
  state.plans = [];
  state.users = [];
  state.otpRequested = false;
  try {
    localStorage.removeItem('aldahim.bo.jwt');
    localStorage.removeItem('aldahim.bo.user');
  } catch (error) {
    // Ignore storage cleanup failures; in-memory state is already cleared.
  }
  $('[data-login-phone]').value = '';
  $('[data-login-otp]').value = '';
  setLoginMessage('');
  updateOtpStep(false);
  updateAuthUi();
  renderMetrics();
  renderProviders();
  renderPlans();
  renderUsers();
}

function updateAuthUi() {
  const authenticated = Boolean(state.token);
  $('[data-login-screen]').hidden = authenticated;
  $('[data-app-workspace]').setAttribute('aria-hidden', authenticated ? 'false' : 'true');
  $('[data-open-login]').textContent = authenticated ? 'Connecté' : 'Se connecter';
  $('[data-auth-notice]').hidden = authenticated;

  const userLabel = state.user?.fullName || state.user?.name || state.user?.phone || state.user?.phoneNumber || 'Utilisateur connecté';
  $('[data-session-user]').textContent = authenticated ? userLabel : 'Non connecté';
  $('[data-session-detail]').textContent = authenticated
    ? 'La session est stockée localement dans cette PWA.'
    : 'Connectez-vous pour accéder aux données protégées.';
}

function updateOtpStep(enabled) {
  state.otpRequested = enabled;
  $('[data-otp-field]').hidden = !enabled;
  $('[data-login-submit]').hidden = !enabled;
  if (enabled) {
    $('[data-login-otp]').focus();
  }
}

function setLoginMessage(message, isError = false) {
  const element = $('[data-login-message]');
  element.textContent = message;
  element.classList.toggle('error', isError);
}

function normalizeStatus(value) {
  if (!value) {
    return 'inconnu';
  }

  return String(value).replaceAll('_', ' ');
}

function renderMetrics() {
  const approved = state.providers.filter((provider) => provider.validationStatus === 'approved').length;
  const pending = state.providers.filter((provider) => provider.validationStatus === 'pending').length;
  $('[data-metric="providers"]').textContent = String(state.providers.length || '--');
  $('[data-metric="approved"]').textContent = String(approved || '--');
  $('[data-metric="pending"]').textContent = String(pending || '--');
  $('[data-metric="plans"]').textContent = String(state.plans.length || '--');
  $('[data-summary="providers"]').textContent = state.providers.length
    ? `${pending} dossier(s) en attente sur ${state.providers.length} prestataire(s).`
    : 'Aucun dossier chargé pour le moment.';
}

function renderProviders() {
  const tbody = $('[data-provider-rows]');
  const status = $('[data-provider-filter]').value;
  const providers = status ? state.providers.filter((provider) => provider.validationStatus === status) : state.providers;

  if (!providers.length) {
    tbody.innerHTML = '<tr><td colspan="5">Aucun prestataire à afficher.</td></tr>';
    return;
  }

  tbody.innerHTML = providers.map((provider) => `
    <tr>
      <td>${escapeHtml(provider.name || provider.fullName || 'Sans nom')}</td>
      <td>${escapeHtml(provider.phone || provider.phoneNumber || '--')}</td>
      <td><span class="badge">${escapeHtml(normalizeStatus(provider.validationStatus || provider.status))}</span></td>
      <td>${provider.canDeliver ? 'Oui' : 'Non'}</td>
      <td>${provider.canTransportPeople ? 'Oui' : 'Non'}</td>
    </tr>
  `).join('');
}

function renderPlans() {
  const container = $('[data-plan-cards]');
  $('[data-plans-count]').textContent = state.plans.length ? `${state.plans.length} plan(s)` : '--';

  if (!state.plans.length) {
    container.innerHTML = '<div class="plan-card"><h3>Aucun plan chargé</h3><p class="help">Actualisez après configuration du jeton.</p></div>';
    return;
  }

  container.innerHTML = state.plans.map((plan) => `
    <article class="plan-card">
      <h3>${escapeHtml(plan.name || plan.code || 'Plan')}</h3>
      <p class="help">${escapeHtml(plan.description || 'Sans description')}</p>
      <p><strong>${escapeHtml(formatPrice(plan))}</strong></p>
    </article>
  `).join('');
}

function renderUsers() {
  const tbody = $('[data-user-rows]');
  if (!tbody) {
    return;
  }
  $('[data-users-count]').textContent = `${state.users.length} utilisateur(s)`;
  if (!state.users.length) {
    tbody.innerHTML = '<tr><td colspan="5">Aucun utilisateur à afficher.</td></tr>';
    return;
  }

  tbody.innerHTML = state.users.map((user) => {
    const details = user.type === 'BO'
      ? (user.roles || []).map(formatRole).join(', ') || 'Aucun rôle'
      : user.type === 'PRESTATAIRE'
        ? [user.canDeliver ? 'Livraison' : '', user.canTransportPeople ? 'Transport' : ''].filter(Boolean).join(', ')
        : 'Client mobile';
    const status = user.type === 'PRESTATAIRE' ? normalizeStatus(user.providerStatus) : (user.enabled ? 'actif' : 'désactivé');
    return `
      <tr>
        <td><strong>${escapeHtml(user.name || 'Sans nom')}</strong><br><span class="cell-meta">#${user.id}</span></td>
        <td>${escapeHtml(user.phone)}<br><span class="cell-meta">${escapeHtml(user.email || 'Sans e-mail')}</span></td>
        <td><span class="badge">${escapeHtml(user.type)}</span><br><span class="cell-meta">${escapeHtml(details)}</span></td>
        <td><span class="status-badge ${user.enabled ? 'enabled' : 'disabled'}">${escapeHtml(status)}</span></td>
        <td class="row-actions">
          <button class="table-button" type="button" data-user-edit="${user.id}">Modifier</button>
          <button class="table-button" type="button" data-user-toggle="${user.id}">${user.enabled ? 'Désactiver' : 'Activer'}</button>
          ${user.type === 'BO' ? '' : `<button class="table-button danger" type="button" data-user-delete="${user.id}">Supprimer</button>`}
        </td>
      </tr>`;
  }).join('');
}

function formatRole(role) {
  const labels = {
    ROLE_ADMIN: 'Administrateur',
    ROLE_PROVIDER_REVIEWER: 'Vérification',
    ROLE_PROVIDER_APPROVER: 'Approbation',
    ROLE_PROVIDER_SECURITY_ADMIN: 'Suspension'
  };
  return labels[role] || role;
}

async function loadUsers() {
  if (!state.token) {
    return;
  }
  const type = $('[data-user-type]').value;
  const search = $('[data-user-search]').value.trim();
  state.userType = type;
  setUsersMessage('Chargement...');
  try {
    const data = await authorizedJson(`/api/v1/admin/users?type=${encodeURIComponent(type)}&search=${encodeURIComponent(search)}`);
    state.users = data.users || [];
    setUsersMessage('');
    renderUsers();
  } catch (error) {
    state.users = [];
    renderUsers();
    setUsersMessage(error.message === 'FORBIDDEN' ? 'Cette section est réservée aux administrateurs.' : error.message, true);
  }
}

function setUsersMessage(message, isError = false) {
  const element = $('[data-users-message]');
  element.textContent = message;
  element.classList.toggle('error', isError);
}

function syncUserFormOptions() {
  const type = $('[data-user-form-type]').value;
  $('[data-bo-options]').hidden = type !== 'BO';
  $('[data-provider-options]').hidden = type !== 'PRESTATAIRE';
}

function openUserForm(user = null) {
  const dialog = $('[data-user-dialog]');
  $('[data-user-form]').reset();
  $('[data-user-id]').value = user?.id || '';
  $('[data-user-form-title]').textContent = user ? 'Modifier l’utilisateur' : 'Ajouter un utilisateur';
  $('[data-user-form-type]').value = user?.type || state.userType;
  $('[data-user-form-type]').disabled = Boolean(user);
  $('[data-user-name]').value = user?.name || '';
  $('[data-user-phone]').value = user?.phone || '';
  $('[data-user-email]').value = user?.email || '';
  $$('[data-user-role]').forEach((input) => {
    input.checked = user ? (user.roles || []).includes(input.value) : input.value === 'ROLE_ADMIN';
  });
  $('[data-user-deliver]').checked = Boolean(user?.canDeliver);
  $('[data-user-transport]').checked = Boolean(user?.canTransportPeople);
  $('[data-user-form-message]').textContent = '';
  syncUserFormOptions();
  dialog.showModal();
}

function closeUserForm() {
  $('[data-user-dialog]').close();
}

async function saveUser(event) {
  event.preventDefault();
  const id = $('[data-user-id]').value;
  const type = $('[data-user-form-type]').value;
  const payload = {
    type,
    name: $('[data-user-name]').value.trim(),
    phone: $('[data-user-phone]').value.trim(),
    email: $('[data-user-email]').value.trim(),
    ...(type === 'BO' ? { roles: $$('[data-user-role]:checked').map((input) => input.value) } : {}),
    ...(type === 'PRESTATAIRE' ? {
      canDeliver: $('[data-user-deliver]').checked,
      canTransportPeople: $('[data-user-transport]').checked
    } : {})
  };
  const message = $('[data-user-form-message]');
  message.textContent = 'Enregistrement...';
  message.classList.remove('error');
  $('[data-user-save]').disabled = true;
  try {
    await authorizedJson(id ? `/api/v1/admin/users/${id}` : '/api/v1/admin/users', {
      method: id ? 'PATCH' : 'POST',
      body: payload
    });
    closeUserForm();
    $('[data-user-type]').value = type;
    await loadUsers();
  } catch (error) {
    message.textContent = error.message;
    message.classList.add('error');
  } finally {
    $('[data-user-save]').disabled = false;
  }
}

async function handleUserTableAction(event) {
  const editButton = event.target.closest('[data-user-edit]');
  const toggleButton = event.target.closest('[data-user-toggle]');
  const deleteButton = event.target.closest('[data-user-delete]');
  const id = Number((editButton || toggleButton || deleteButton)?.dataset.userEdit
    || toggleButton?.dataset.userToggle || deleteButton?.dataset.userDelete);
  if (!id) {
    return;
  }
  const user = state.users.find((item) => item.id === id);
  if (!user) {
    return;
  }
  if (editButton) {
    openUserForm(user);
    return;
  }
  try {
    if (toggleButton) {
      await authorizedJson(`/api/v1/admin/users/${id}/status`, {
        method: 'PATCH', body: { type: user.type, enabled: !user.enabled }
      });
    } else if (deleteButton) {
      if (!window.confirm(`Supprimer définitivement le compte de ${user.name || user.phone} ?`)) {
        return;
      }
      await authorizedJson(`/api/v1/admin/users/${id}?type=${encodeURIComponent(user.type)}`, { method: 'DELETE' });
    }
    await loadUsers();
  } catch (error) {
    setUsersMessage(error.message, true);
  }
}

function formatPrice(plan) {
  const amount = plan.priceAmount ?? plan.amount ?? plan.monthlyAmount;
  const currency = plan.currency || 'GNF';
  if (amount === undefined || amount === null) {
    return 'Tarif non renseigné';
  }

  return `${Number(amount).toLocaleString('fr-FR')} ${currency}`;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

async function refresh() {
  updateAuthUi();
  if (!state.token) {
    renderMetrics();
    renderProviders();
    renderPlans();
    return;
  }

  try {
    const [providers, plans] = await Promise.all([
      api('/api/v1/admin/providers'),
      api('/api/v1/subscription-plans')
    ]);

    state.providers = providers.providers || providers['hydra:member'] || providers.member || [];
    state.plans = plans.plans || plans['hydra:member'] || plans.member || [];
    $('[data-last-sync]').textContent = `Synchronisé à ${new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
    $('[data-auth-notice]').hidden = true;
  } catch (error) {
    if (error.message === 'FORBIDDEN') {
      logout();
      setLoginMessage('Votre ancienne session a expiré. Reconnectez-vous.', true);
      return;
    }

    $('[data-auth-notice]').hidden = false;
    $('[data-auth-notice] span').textContent = 'Impossible de charger les données pour le moment.';
  }

  renderMetrics();
  renderProviders();
  renderPlans();
  if ($('[data-view-panel="users"]') && !$('[data-view-panel="users"]').classList.contains('hidden')) {
    await loadUsers();
  }
}

function showDashboardAfterLogin() {
  updateAuthUi();
  setView('overview');
  $('[data-last-sync]').textContent = 'Synchronisation en cours...';
}

function updateOnlineState() {
  const online = navigator.onLine;
  $('[data-online-dot]').classList.toggle('online', online);
  $('[data-online-label]').textContent = online ? 'En ligne' : 'Hors ligne';
}

function bootstrapInstallPrompt() {
  const installButton = $('[data-install]');

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    state.deferredInstall = event;
    installButton.hidden = false;
  });

  installButton.addEventListener('click', async () => {
    if (!state.deferredInstall) {
      return;
    }

    state.deferredInstall.prompt();
    await state.deferredInstall.userChoice;
    state.deferredInstall = null;
    installButton.hidden = true;
  });
}

function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    return;
  }

  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data?.type !== 'PWA_VERSION_READY' || state.reloadedForVersion) {
      return;
    }

    state.reloadedForVersion = true;
    try {
      sessionStorage.setItem('aldahim.bo.versionReloaded', '10');
    } catch (error) {
      // Continue with the one-time reload even when session storage is unavailable.
    }
    window.location.replace('/?pwa=aldahim-bo&v=10');
  });

  navigator.serviceWorker.register('/service-worker.js').then((registration) => {
    registration.update();
  }).catch(() => {
    // The PWA remains usable when service worker registration is unavailable.
  });
}

function bindEvents() {
  $$('.nav-item').forEach((button) => button.addEventListener('click', () => {
    setView(button.dataset.view);
    if (button.dataset.view === 'users') {
      loadUsers();
    }
  }));
  $('[data-refresh]').addEventListener('click', refresh);
  $('[data-provider-filter]').addEventListener('change', renderProviders);
  $('[data-open-login]').addEventListener('click', () => {
    if (state.token) {
      setView('settings');
      return;
    }

    $('[data-login-screen]').hidden = false;
    $('[data-login-phone]').focus();
  });
  $('[data-logout]').addEventListener('click', logout);
  $('[data-request-otp]').addEventListener('click', requestOtp);
  $('[data-login-otp]').addEventListener('input', handleOtpInput);
  $('[data-login-form]').addEventListener('submit', login);
  $('[data-user-type]').addEventListener('change', loadUsers);
  $('[data-user-search]').addEventListener('input', debounce(loadUsers, 300));
  $('[data-user-create]').addEventListener('click', () => openUserForm());
  $('[data-user-form-type]').addEventListener('change', syncUserFormOptions);
  $('[data-user-form]').addEventListener('submit', saveUser);
  $('[data-user-rows]').addEventListener('click', handleUserTableAction);
  $('[data-user-close]').addEventListener('click', closeUserForm);
  $('[data-user-cancel]').addEventListener('click', closeUserForm);
  window.addEventListener('online', updateOnlineState);
  window.addEventListener('offline', updateOnlineState);
}

function debounce(callback, delay) {
  let timeout;
  return (...args) => {
    window.clearTimeout(timeout);
    timeout = window.setTimeout(() => callback(...args), delay);
  };
}

function handleOtpInput(event) {
  const input = event.currentTarget;
  const digits = input.value.replace(/\D/g, '').slice(0, 8);
  input.value = digits;

  if (digits.length >= 6 && !state.loginInProgress) {
    $('[data-login-form]').requestSubmit();
  }
}

async function requestOtp() {
  const phone = $('[data-login-phone]').value.trim();
  if (!phone) {
    setLoginMessage('Renseignez votre numéro de téléphone.', true);
    return;
  }

  setLoginMessage('Envoi du code OTP...');
  $('[data-request-otp]').disabled = true;

  try {
    await postJson('/api/v1/back-office/auth/otp/request', { phone });
    updateOtpStep(true);
    setLoginMessage('Code OTP envoyé. Vérifiez vos messages.');
  } catch (error) {
    setLoginMessage(loginErrorMessage(error), true);
  } finally {
    $('[data-request-otp]').disabled = false;
  }
}

async function login(event) {
  event.preventDefault();
  if (state.loginInProgress) {
    return;
  }

  const phone = $('[data-login-phone]').value.trim();
  const otp = $('[data-login-otp]').value.replace(/\D/g, '');
  if (!phone || !otp) {
    setLoginMessage('Téléphone et code OTP sont requis.', true);
    return;
  }

  state.loginInProgress = true;
  setLoginMessage('Vérification du code...');
  $('[data-login-submit]').disabled = true;

  try {
    const data = await postJson('/api/v1/back-office/auth/otp/verify', { phone, otp });
    if (!data.token) {
      throw new Error('TOKEN_MISSING');
    }

    setAuthenticated(data.token, data.user || null);
    setLoginMessage('');
    showDashboardAfterLogin();
    refresh();
  } catch (error) {
    setLoginMessage(loginErrorMessage(error), true);
  } finally {
    state.loginInProgress = false;
    $('[data-login-submit]').disabled = false;
  }
}

function loginErrorMessage(error) {
  const message = error.message || '';
  if (message === 'USER_NOT_FOUND') {
    return 'Aucun compte vérifié ne correspond à ce numéro.';
  }

  if (message === 'BACK_OFFICE_FORBIDDEN') {
    return 'Ce compte n’a pas accès au back-office.';
  }

  if (message.includes('OTP invalide')) {
    return 'Code OTP invalide ou expiré.';
  }

  if (message === 'TOKEN_MISSING') {
    return 'Connexion impossible: token absent dans la réponse.';
  }

  return 'Connexion impossible pour le moment.';
}

function initialView() {
  const view = location.pathname.replace('/', '') || 'overview';
  return views[view] ? view : 'overview';
}

document.addEventListener('DOMContentLoaded', () => {
  bindEvents();
  updateOnlineState();
  bootstrapInstallPrompt();
  registerServiceWorker();
  setView(initialView());
  updateAuthUi();
  refresh();
});
