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
(async () => {
  for (const usage of [undefined, [], {}, { day: '2026-10-16', characters: 0 }]) await test(usage);
  console.log('PASS: 12 admin progress assertions');
})().catch(error => { console.error(error); process.exitCode = 1; });
