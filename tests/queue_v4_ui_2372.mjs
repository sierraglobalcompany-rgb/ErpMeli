import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
const anchor = "(() => {\n  const root = document.querySelector('[data-queue-v4-clean]');";
const start = source.indexOf(anchor);
const end = source.indexOf('\n})();', start);
if (start < 0 || end < 0) throw new Error('queue_v4_ui_source_missing');
const queueV4Source = source.slice(start, end + 6);

let checks = 0;
const assert = (condition, message) => {
  checks++;
  if (!condition) throw new Error(message);
};

const response = ({ status = 200, type = 'application/json', json = async () => ({}) } = {}) => ({
  ok: status >= 200 && status < 300,
  status,
  headers: { get: (name) => name.toLowerCase() === 'content-type' ? type : null },
  json,
});

async function scenario(fetchResult, passwordValue = 'admin-password') {
  const nodes = new Map();
  const node = (key) => {
    if (!nodes.has(key)) nodes.set(key, { textContent: '' });
    return nodes.get(key);
  };
  const password = { value: passwordValue, addEventListener() {} };
  const feedback = node('feedback');
  const actions = ['readiness', 'activate', 'stop'].map((action) => {
    const button = { disabled: true, textContent: action };
    return {
      dataset: { qv4Action: action }, action: '/unused',
      querySelector: (selector) => selector === 'button' ? button : { value: '' },
      addEventListener() {}, button,
    };
  });
  const root = {
    dataset: { statusUrl: '/status' },
    querySelector(selector) {
      if (selector === '[data-qv4-password]') return password;
      if (selector === '[data-qv4-feedback]') return feedback;
      return node(selector);
    },
    querySelectorAll: (selector) => selector === '[data-qv4-action]' ? actions : [],
  };
  const context = vm.createContext({
    document: { querySelector: () => root },
    fetch: async () => fetchResult,
    AbortController, FormData,
    setTimeout, clearTimeout, Number, Object, Error,
  });
  vm.runInContext(queueV4Source, context);
  await new Promise((resolve) => setTimeout(resolve, 10));
  return { feedback: feedback.textContent, actions };
}

const ready = await scenario(response({ json: async () => ({
  ok: true, state: 'READY_TO_TEST', engine: 'STOPPED', issues: [], queue: {},
  accounts_oauth: 3, readiness_get_passed: 0, scheduler: 'inactive', legacy_state_consulted: false,
}) }));
assert(ready.feedback === 'Estado actualizado sin mutaciones.', 'ready feedback invalid');
assert(ready.actions[0].button.disabled === false, 'password did not enable readiness');
assert(ready.actions[1].button.disabled === true && ready.actions[2].button.disabled === true, 'unavailable action enabled');

const emptyPassword = await scenario(response({ json: async () => ({
  ok: true, state: 'READY_TO_TEST', engine: 'STOPPED', issues: [], queue: {},
}) }), '');
assert(emptyPassword.actions.every(({ button }) => button.disabled), 'empty password enabled action');

const timeout = await scenario(response({ status: 504, type: 'text/html' }));
assert(timeout.feedback === 'El backend Queue V4 agotó el tiempo de respuesta.', '504 message invalid');
assert(timeout.actions.every(({ button }) => button.disabled), '504 left action enabled');

const html = await scenario(response({ type: 'text/html' }));
assert(html.feedback === 'Queue V4 devolvió una respuesta no válida.', 'HTML response message invalid');
assert(html.actions.every(({ button }) => button.disabled), 'HTML response left action enabled');

const invalidJson = await scenario(response({ json: async () => { throw new SyntaxError('bad json'); } }));
assert(invalidJson.feedback === 'Queue V4 devolvió JSON inválido.', 'invalid JSON message invalid');
assert(invalidJson.actions.every(({ button }) => button.disabled), 'invalid JSON left action enabled');

console.log(JSON.stringify({ ok: true, checks, safe_failures: ['504', 'html', 'invalid_json'] }));
