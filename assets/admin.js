/* global ctpAdmin */
(() => {
  'use strict';
  const status = document.getElementById('ctp-status');
  const form = document.getElementById('ctp-job-form');
  if (!status || !form) return;
  let timer;
  let busy = false;
  async function command(name, fields) {
    const body = fields || new FormData();
    body.set('action', 'ctp_admin');
    body.set('nonce', ctpAdmin.nonce);
    body.set('command', name);
    const response = await fetch(ctpAdmin.url, { method: 'POST', body, credentials: 'same-origin' });
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || 'Request failed. Refresh this page and check the job status.');
    return json.data;
  }
  function render(job) {
    const lang = job.languages?.[job.language_index] || '';
    status.textContent = job.phase === 'idle' ? 'No job started.' : [
      `Phase: ${job.phase}${job.paused ? ' (paused)' : ''}`,
      lang ? `Language: ${lang}` : '',
      ['discover', 'finalize'].includes(job.phase) ? `Page: ${job.url_index + 1} / ${job.url_count}` : '',
      `Strings processed: ${job.translated || 0} · skipped: ${job.skipped || 0}`,
      job.xml_declarations ? `XML declarations left unchanged (no API call): ${job.xml_declarations}` : '',
      job.reviewed_equivalents ? `Left for review (a reviewed variant differing only in case, accents or spacing exists): ${job.reviewed_equivalents}` : '',
      `Reserved source bytes today (${job.usage?.day || new Date().toISOString().slice(0, 10)} UTC): ${Number.isFinite(Number(job.usage?.characters)) ? Number(job.usage.characters) : 0}`,
      job.error ? `Attention: ${job.error}` : '',
      job.phase === 'done' ? 'Run finished. Clear affected translated-page caches and review translations in TRP.' : '',
    ].filter(Boolean).join('\n');
    clearTimeout(timer);
    if (!job.paused && !['idle', 'done', 'cancelled'].includes(job.phase)) {
      timer = setTimeout(pump, 1500);
    }
  }
  async function pump() {
    if (busy) { timer = setTimeout(pump, 1500); return; }
    busy = true;
    try { render(await command('tick')); }
    catch (error) { status.textContent = `${error.message}\nRefresh to inspect saved progress; do not start a replacement job.`; }
    finally { busy = false; }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    try { render(await command('start', new FormData(form))); }
    catch (error) { status.textContent = error.message; }
    finally { busy = false; }
  });
  for (const button of document.querySelectorAll('[data-ctp]')) {
    button.addEventListener('click', async () => {
      // Pause remains usable while a translation request is in flight.
      if (busy && button.dataset.ctp !== 'pause') return;
      try { render(await command(button.dataset.ctp)); }
      catch (error) { status.textContent = error.message; }
    });
  }
  const inspect = document.getElementById('ctp-inspect-source');
  if (inspect) inspect.addEventListener('click', async () => {
    const panel = document.getElementById('ctp-source-panel');
    const info = document.getElementById('ctp-source-info');
    const preview = document.getElementById('ctp-source-preview');
    panel.hidden = false;
    preview.value = '';
    info.textContent = 'Reading blocked source…';
    inspect.disabled = true;
    try {
      const row = await command('inspect_source');
      info.textContent = `Row ${row.row_id} · ${row.language} · ${row.kind} · ${row.source_bytes} bytes. ${row.reason}`;
      preview.value = row.source;
    } catch (error) { info.textContent = error.message; }
    finally { inspect.disabled = false; }
  });
  command('status').then(render).catch(error => { status.textContent = error.message; });
  const resetForm = document.getElementById('ctp-reset-form');
  if (resetForm) {
    const summary = document.getElementById('ctp-reset-summary');
    const execute = document.getElementById('ctp-reset-execute');
    const confirmation = document.getElementById('ctp-reset-confirmation');
    let resetToken = '';
    let resetRevision = 0;
    resetForm.addEventListener('change', event => {
      if (event.target === confirmation) return;
      ++resetRevision;
      resetToken = ''; execute.disabled = true; summary.textContent = 'Selection changed. Preview again.';
    });
    confirmation.addEventListener('input', () => { execute.disabled = busy || !resetToken || confirmation.value !== 'RESET TRANSLATIONS'; });
    resetForm.addEventListener('submit', async event => {
      event.preventDefault(); if (busy) return;
      const revision = ++resetRevision;
      resetToken = ''; execute.disabled = true;
      try {
        const preview = await command('reset_preview', new FormData(resetForm));
        if (revision !== resetRevision) return;
        resetToken = preview.token;
        summary.textContent = `Mode: ${preview.mode}\nLanguages: ${preview.languages.join(', ')}\n` + preview.counts.map(row => `${row.language} · ${row.kind}: ${row.count} rows`).join('\n');
        confirmation.value = '';
      } catch (error) { summary.textContent = error.message; }
    });
    execute.addEventListener('click', async () => {
      if (busy || !resetToken || confirmation.value !== 'RESET TRANSLATIONS') return;
      busy = true; execute.disabled = true; clearTimeout(timer);
      const fields = new FormData(); fields.set('reset_token', resetToken); fields.set('reset_confirmation', confirmation.value);
      resetToken = '';
      try { const result = await command('reset_execute', fields); summary.textContent = result.message; render(await command('status')); }
      catch (error) { summary.textContent = error.message; }
      finally { busy = false; }
    });
  }
})();
