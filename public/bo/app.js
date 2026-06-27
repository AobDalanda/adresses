const state = {
  token: loadStoredToken(),
  user: loadStoredUser(),
  providers: [],
  plans: [],
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
    return sessionStorage.getItem('aldahim.bo.versionReloaded') === '8';
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
  const authorization = authorizationHeader();
  if (!authorization) {
    throw new Error('TOKEN_REQUIRED');
  }

  const response = await fetch(path, {
    headers: {
      Accept: 'application/json',
      Authorization: authorization
    }
  });

  if (response.status === 401 || response.status === 403) {
    throw new Error('FORBIDDEN');
  }

  if (!response.ok) {
    throw new Error(`HTTP_${response.status}`);
  }

  return response.json();
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
      sessionStorage.setItem('aldahim.bo.versionReloaded', '8');
    } catch (error) {
      // Continue with the one-time reload even when session storage is unavailable.
    }
    window.location.replace('/?pwa=aldahim-bo&v=8');
  });

  navigator.serviceWorker.register('/service-worker.js').then((registration) => {
    registration.update();
  }).catch(() => {
    // The PWA remains usable when service worker registration is unavailable.
  });
}

function bindEvents() {
  $$('.nav-item').forEach((button) => button.addEventListener('click', () => setView(button.dataset.view)));
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
  window.addEventListener('online', updateOnlineState);
  window.addEventListener('offline', updateOnlineState);
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
