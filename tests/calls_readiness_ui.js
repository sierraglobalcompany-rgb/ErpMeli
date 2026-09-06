// Execute the existing browser IIFE against a tiny DOM fixture; real browser QA is separate.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/app.js'), 'utf8').replace(/\r\n/g, '\n');
const start = source.indexOf("(() => {\n  const root = document.querySelector('[data-queue-v4-clean]');");
const end = source.indexOf('// Riesgos API de Cron:', start);
assert(start >= 0 && end > start, 'existing readiness IIFE found');
const code = source.slice(start, end);
function node() { return { value: '', textContent: '', disabled: false, innerHTML: '', listeners: {}, addEventListener(n, fn) { this.listeners[n] = fn; } }; }
async function scenario(state) {
  const nodes = new Map();
  const lookup = s => { if (!nodes.has(s)) nodes.set(s, node()); return nodes.get(s); };
  const actions = ['readiness', 'cancel', 'activate', 'stop'].map(action => ({
    ...node(), dataset: { qv4Action: action }, action: '/local/' + action,
    button: node(), fields: new Map(), querySelector(s) {
      if (s === 'button') return this.button;
      if (!this.fields.has(s)) this.fields.set(s, node());
      return this.fields.get(s);
    }
  }));
  const root = { dataset: { statusUrl: '/local/status' }, querySelector: lookup, querySelectorAll: () => actions };
  let posts = 0;
  const payloads = [];
  class FormDataFixture {
    constructor() { this.values = new Map(); }
    set(k,v) { this.values.set(k,String(v)); }
    get(k) { return this.values.get(k); }
  }
  const context = { document: { querySelector: () => root }, AbortController, setTimeout, clearTimeout, console,
    FormData: FormDataFixture,
    fetch: async (url, options = {}) => {
      if (options.method === 'POST') { posts++; payloads.push(options.body); }
      return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => state };
    }
  };
  vm.runInNewContext(code, context);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(posts, 0, 'render and refresh never post');
  return { nodes, actions, lookup, payloads, posts: () => posts };
}
(async () => {
  const state = { ok: true, state: 'TESTING', engine: 'STOPPED', issues: [], readiness_get_passed: 1,
    run_id: 21, run_token: 'a'.repeat(64), next_step: 2, expires_at: new Date(Date.now()+600000).toISOString(),
    accounts: [{ account_name: 'Local account' }] };
  const s = await scenario(state);
  assert.equal(s.actions[0].button.disabled, false, 'next explicit check needs no repeated password');
  assert(!s.lookup('[data-qv4-message]').textContent.includes('Automatización activa'), 'testing is not active');
  await s.actions[0].listeners.submit({ preventDefault() {} });
  assert.equal(s.posts(), 1, 'one click performs one POST, not three');
  assert.equal(s.payloads[0].get('action'), 'check');
  assert.equal(s.payloads[0].get('step_no'), '2');
  assert.equal(s.payloads[0].get('run_id'), '21');
  assert.equal(s.payloads[0].get('run_token'), 'a'.repeat(64));
  assert.equal(s.payloads[0].get('admin_password'), '');
  const expired = await scenario({ ...state, expires_at: '2000-01-01T00:00:00Z' });
  assert.equal(expired.actions[0].button.disabled, true, 'expired context cannot dispatch');
  const prepared = await scenario({ ok: true, state: 'READY_TO_TEST', engine: 'STOPPED', issues: [] });
  assert.equal(prepared.actions[0].button.disabled, true, 'prepare keeps administrative password');
  prepared.lookup('[data-qv4-password]').value = 'fixture-password';
  prepared.lookup('[data-qv4-password]').listeners.input();
  await prepared.actions[0].listeners.submit({ preventDefault() {} });
  assert.equal(prepared.payloads[0].get('action'), 'prepare');
  console.log('PASS readiness UI explicit actions and state');
})().catch(error => { console.error(error); process.exitCode = 1; });
