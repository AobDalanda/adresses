const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function element(initial = {}) {
  return {
    value: '',
    hidden: false,
    disabled: false,
    textContent: '',
    attributes: {},
    classList: { toggle() {} },
    setAttribute(name, value) { this.attributes[name] = value; },
    ...initial
  };
}

const elements = {
  '[data-login-phone]': element({ value: '+33651896602' }),
  '[data-login-otp]': element({ value: '123456' }),
  '[data-login-submit]': element(),
  '[data-login-message]': element(),
  '[data-login-screen]': element(),
  '[data-app-workspace]': element(),
  '[data-open-login]': element(),
  '[data-auth-notice]': element(),
  '[data-session-user]': element(),
  '[data-session-detail]': element(),
  '[data-view-title]': element(),
  '[data-view-subtitle]': element(),
  '[data-last-sync]': element()
};

const storage = new Map();
const context = {
  console,
  document: {
    querySelector: (selector) => elements[selector] || element(),
    querySelectorAll: () => [],
    addEventListener() {}
  },
  history: { replaceState() {} },
  location: { pathname: '/', replace() {} },
  localStorage: {
    getItem: (key) => storage.get(key) || null,
    setItem: (key, value) => storage.set(key, value),
    removeItem: (key) => storage.delete(key)
  },
  sessionStorage: {
    getItem: () => null,
    setItem() {}
  },
  navigator: { onLine: true },
  window: { addEventListener() {} },
  fetch: async (path, options) => {
    assert.equal(path, '/api/v1/back-office/auth/otp/verify');
    assert.deepEqual(JSON.parse(options.body), {
      phone: '+33651896602',
      otp: '123456'
    });

    return {
      ok: true,
      status: 200,
      json: async () => ({
        token: 'valid-back-office-token',
        user: { name: 'Admin' }
      })
    };
  }
};

vm.createContext(context);
vm.runInContext(fs.readFileSync('public/bo/app.js', 'utf8'), context);
vm.runInContext('refresh = async () => {};', context);

vm.runInContext('login({ preventDefault() {} })', context).then(() => {
  assert.equal(storage.get('aldahim.bo.jwt'), 'valid-back-office-token');
  assert.equal(elements['[data-login-screen]'].hidden, true);
  assert.equal(elements['[data-app-workspace]'].attributes['aria-hidden'], 'false');
  assert.equal(elements['[data-open-login]'].textContent, 'Connecté');
  assert.equal(elements['[data-login-submit]'].disabled, false);
});
