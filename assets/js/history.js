/* scanner/history.php — scan history modal logic */

async function viewScan(scanId) {
  document.getElementById('scan-modal').style.display = 'flex';
  document.getElementById('modal-body').innerHTML = '<div class="modal-loading">Loading report...</div>';
  try {
    const res  = await fetch('/scanner/get.php?id=' + scanId);
    const data = await res.json();
    if (data.error) {
      document.getElementById('modal-body').innerHTML = '<p class="modal-error">' + data.error + '</p>';
      return;
    }
    renderModal(data);
  } catch (e) {
    document.getElementById('modal-body').innerHTML = '<p class="modal-error">Failed to load scan.</p>';
  }
}

function closeModal(e) {
  if (e.target.id === 'scan-modal') document.getElementById('scan-modal').style.display = 'none';
}

function renderModal(s) {
  const findings = (s.findings || []).sort((a, b) => {
    const o = {critical: 0, high: 1, medium: 2, low: 3, info: 4};
    return (o[a.severity] ?? 9) - (o[b.severity] ?? 9);
  });
  const scoreColor = s.score >= 75 ? '#00d4aa' : s.score >= 50 ? '#ffd166' : '#ff4d6d';
  const C      = 2 * Math.PI * 38;
  const offset = C - (s.score / 100) * C;

  document.getElementById('modal-title').textContent = s.target_url;

  let html = `
  <div class="modal-score-row">
    <svg viewBox="0 0 80 80" width="80" height="80" style="flex-shrink:0">
      <circle cx="40" cy="40" r="38" fill="none" stroke="#1a2535" stroke-width="5"/>
      <circle cx="40" cy="40" r="38" fill="none" stroke="${scoreColor}" stroke-width="5"
        stroke-dasharray="${C.toFixed(1)}" stroke-dashoffset="${offset.toFixed(1)}"
        stroke-linecap="round" transform="rotate(-90 40 40)"/>
    </svg>
    <div class="modal-score-info">
      <div class="modal-score-num" style="color:${scoreColor}">${s.score}<span style="font-size:14px;color:var(--text2)">/100</span></div>
      <div class="modal-score-grade">${s.grade} &nbsp;&middot;&nbsp; ${s.scanned_at}</div>
      <div class="modal-score-summary">${esc(s.summary)}</div>
    </div>
    <button class="btn-secondary" onclick="exportFromModal()" style="align-self:flex-start;white-space:nowrap;flex-shrink:0">&#8659; Export</button>
  </div>
  <div class="modal-findings">`;

  for (const f of findings) {
    const bc = {critical: 'bdr-crit', high: 'bdr-high', medium: 'bdr-med', low: 'bdr-low', info: 'bdr-info'}[f.severity] || '';
    const sc = {pass: 'fs-pass', fail: 'fs-fail', warn: 'fs-warn', info: 'fs-info'}[f.status] || '';
    const sv = {critical: 'sv-crit', high: 'sv-high', medium: 'sv-med', low: 'sv-low', info: 'sv-info'}[f.severity] || '';
    html += `
    <div class="finding ${bc}" onclick="this.classList.toggle('open')">
      <div class="finding-top">
        <span class="finding-status ${sc}">${f.status.toUpperCase()}</span>
        <span class="finding-title">${esc(f.title)}</span>
        <span class="finding-sev ${sv}">${f.severity.toUpperCase()}</span>
        <span class="finding-caret">&#8964;</span>
      </div>
      <div class="finding-body">
        <p class="finding-desc">${esc(f.detail)}</p>
        ${f.remediation ? '<p class="finding-fix"><span class="fix-lbl">Fix:</span> ' + esc(f.remediation) + '</p>' : ''}
      </div>
    </div>`;
  }

  html += '</div>';
  document.getElementById('modal-body').innerHTML = html;
  window._modalScan = s;
}

function exportFromModal() {
  const s = window._modalScan;
  if (!s) return;
  let txt = 'HAKDEL SECURITY REPORT\nTarget: ' + s.target_url + '\nScore: ' + s.score + '/100 (' + s.grade + ')\nDate: ' + s.scanned_at + '\n\nFINDINGS\n' + '='.repeat(60) + '\n';
  for (const f of s.findings)
    txt += '\n[' + f.status.toUpperCase() + '] [' + f.severity.toUpperCase() + '] ' + f.title + '\n  ' + f.detail + (f.remediation ? '\n  Fix: ' + f.remediation : '') + '\n';
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([txt], {type: 'text/plain'}));
  a.download = 'hakdel-report-' + Date.now() + '.txt';
  a.click();
}

function esc(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function handleCompareCheck() {
  const checked = Array.from(document.querySelectorAll('.compare-check:checked'));
  const btn = document.getElementById('btn-compare');
  if (checked.length >= 2) {
    btn.style.display = 'inline-flex';
    // uncheck extras beyond 2
    if (checked.length > 2) checked[2].checked = false;
  } else {
    btn.style.display = 'none';
  }
}

function startCompare() {
  const checked = Array.from(document.querySelectorAll('.compare-check:checked'));
  if (checked.length < 2) return;
  window.location.href = '/scanner/compare.php?a=' + checked[0].value + '&b=' + checked[1].value;
}
