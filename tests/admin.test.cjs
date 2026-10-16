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
(async () => {
  for (const usage of [undefined, [], {}, { day: '2026-10-16', characters: 0 }]) await test(usage);
  await inspectTest();
  console.log('PASS: 21 admin progress/inspector assertions');
})().catch(error => { console.error(error); process.exitCode = 1; });
