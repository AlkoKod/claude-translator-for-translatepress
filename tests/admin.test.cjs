'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
async function test(usage) {
  const status = { textContent: '' };
  const form = { addEventListener() {} };
  const sandbox = {
    document: { getElementById: id => id === 'ctp-status' ? status : form, querySelectorAll: () => [] },
    FormData: class { set() {} },
    ctpAdmin: { url: '/mock-no-network', nonce: 'test' },
    fetch: async () => ({ json: async () => ({ success: true, data: {
      phase: 'discover', paused: true, languages: ['en_GB'], language_index: 0,
      url_index: 0, url_count: 125, translated: 0, skipped: 0, usage,
    } }) }),
    setTimeout() {}, clearTimeout() {},
  };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/admin.js'), 'utf8'), sandbox);
  await new Promise(resolve => setImmediate(resolve));
  assert(!status.textContent.includes('undefined'), 'Empty usage must not render undefined');
  assert(status.textContent.includes('UTC): 0'), 'Before any API request usage is zero');
  assert(status.textContent.includes('Language: en_GB'), 'British English status remains visible');
}
async function inspectTest() {
  let click;
  const status = { textContent: '' }, form = { addEventListener() {} };
  const preview = { value: '' }, info = { textContent: '' }, panel = { hidden: true };
  const button = { disabled: false, addEventListener: (_, callback) => { click = callback; } };
  const elements = { 'ctp-status': status, 'ctp-job-form': form, 'ctp-inspect-source': button,
    'ctp-source-panel': panel, 'ctp-source-info': info, 'ctp-source-preview': preview };
  for (const element of Object.values(elements)) Object.defineProperty(element, 'innerHTML', { set() { throw new Error('Untrusted HTML must never be executed'); } });
  const requests = []; let fail = false;
  const source = '<? //silence</textarea><script>alert(1)</script>';
  const sandbox = {
    document: { getElementById: id => elements[id], querySelectorAll: () => [] },
    FormData: class { constructor() { this.values = {}; } set(key,value) { this.values[key] = value; } },
    ctpAdmin: { url: '/mock-no-network', nonce: 'test-nonce' },
    fetch: async (_, args) => {
      requests.push(args.body.values);
      if (args.body.values.command === 'inspect_source') return { json: async () => fail ? { success: false, data: { message: 'No blocked source' } } : { success: true, data: { row_id: 4582, language: 'en_GB', kind: 'regular', source_bytes: 42, reason: 'PHP/XML marker', source } } };
      return { json: async () => ({ success: true, data: { phase: 'idle', paused: true } }) };
    }, setTimeout() {}, clearTimeout() {},
  };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/admin.js'), 'utf8'), sandbox);
  await new Promise(resolve => setImmediate(resolve));
  await click();
  assert.equal(preview.value, source, 'Source is displayed literally in textarea');
  assert(info.textContent.includes('Row 4582') && info.textContent.includes('en_GB'), 'Row and language visible');
  assert.equal(panel.hidden, false, 'Source panel opens');
  assert.equal(button.disabled, false, 'Button re-enabled after success');
  assert.deepEqual(requests.map(r => r.command), ['status','inspect_source'], 'Inspector does not resume or translate');
  assert.equal(requests[1].nonce, 'test-nonce', 'Inspector request includes admin nonce');
  fail = true; await click();
  assert.equal(preview.value, '', 'Stale source cleared after inspector failure');
  assert.equal(info.textContent, 'No blocked source', 'Inspector error remains in source panel');
  assert.equal(button.disabled, false, 'Button re-enabled after failure');
}
async function resetTest() {
  const element = extra => ({ handlers: {}, addEventListener(type, handler) { this.handlers[type] = handler; }, ...extra });
  const status = element({ textContent: '' }), form = element({}), reset = element({});
  const execute = element({ disabled: true }), confirmation = element({ value: '' }), summary = element({ textContent: '' });
  const nodes = { 'ctp-status': status, 'ctp-job-form': form, 'ctp-reset-form': reset,
    'ctp-reset-execute': execute, 'ctp-reset-confirmation': confirmation, 'ctp-reset-summary': summary };
  const requests = []; const token = '01234567-89ab-cdef-0123-456789abcdef';
  const sandbox = {
    document: { getElementById: id => nodes[id] || null, querySelectorAll: () => [] },
    FormData: class { constructor() { this.values = {}; } set(k,v) { this.values[k] = v; } },
    ctpAdmin: { url: '/mock', nonce: 'nonce' }, setTimeout() {}, clearTimeout() {},
    fetch: async (_, args) => {
      requests.push(args.body.values);
      const command = args.body.values.command;
      const data = command === 'reset_preview' ? { token, mode: 'dictionary', languages: ['de_DE'], counts: [{ language: 'de_DE', kind: 'regular', count: 42 }] } : command === 'reset_execute' ? { message: 'Reset completed' } : { phase: 'idle', paused: true };
      return { json: async () => ({ success: true, data }) };
    },
  };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/admin.js'), 'utf8'), sandbox);
  await new Promise(resolve => setImmediate(resolve));
  await execute.handlers.click();
  assert(!requests.some(r => r.command === 'reset_execute'), 'No reset before preview');
  await reset.handlers.submit({ preventDefault() {} });
  assert(summary.textContent.includes('dictionary') && summary.textContent.includes('de_DE') && summary.textContent.includes('42 rows'), 'Preview shows exact mode, languages and counts');
  assert.equal(execute.disabled, true, 'Preview alone does not enable deletion');
  confirmation.value = 'wrong'; confirmation.handlers.input();
  assert.equal(execute.disabled, true, 'Wrong confirmation blocks deletion');
  confirmation.value = 'RESET TRANSLATIONS'; confirmation.handlers.input();
  assert.equal(execute.disabled, false, 'Exact confirmation permits explicit reset');
  reset.handlers.change({ target: confirmation });
  assert.equal(execute.disabled, false, 'Confirmation blur does not invalidate preview');
  await execute.handlers.click();
  const operations = requests.filter(r => r.command === 'reset_execute');
  assert.equal(operations.length, 1, 'Exactly one reset request from explicit click');
  assert.equal(operations[0].reset_token, token, 'Reset uses server preview token');
  assert.equal(operations[0].reset_confirmation, 'RESET TRANSLATIONS', 'Reset sends exact typed confirmation');
  assert.equal(summary.textContent, 'Reset completed', 'Result visible in reset panel');
  assert.equal(execute.disabled, true, 'Reset cannot be repeated without preview');
  await reset.handlers.submit({ preventDefault() {} });
  confirmation.value = 'RESET TRANSLATIONS'; confirmation.handlers.input();
  reset.handlers.change({ target: {} });
  assert.equal(execute.disabled, true, 'Changed selection invalidates preview');
  assert(summary.textContent.includes('Preview again'), 'Changed selection requires another preview');
  await execute.handlers.click();
  assert.equal(requests.filter(r => r.command === 'reset_execute').length, 1, 'Stale preview never executes');
}
(async () => {
  for (const usage of [undefined, [], {}, { day: '2026-10-16', characters: 0 }]) await test(usage);
  await inspectTest();
  await resetTest();
  console.log('PASS: 35 admin progress/inspector/reset assertions');
})().catch(error => { console.error(error); process.exitCode = 1; });
