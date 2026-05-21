/* scanner/index.php — scan UI logic
   Requires: window.SCANNER_API set by the PHP page before this script loads. */

let selectedModules = ['whois','ssl','headers','dns','cookies','waf','stack'];
let pollInterval = null;

function selectProfile(btn) {
  document.querySelectorAll('.profile-pill').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  selectedModules = JSON.parse(btn.dataset.modules);
  const key = btn.dataset.profile;
  document.getElementById('custom-modules').style.display = key === 'custom' ? 'block' : 'none';
  if (key === 'custom') document.querySelectorAll('.mod-check').forEach(c => c.checked = true);
}

function getModules() {
  const active = document.querySelector('.profile-pill.active');
  if (active && active.dataset.profile === 'custom')
    return Array.from(document.querySelectorAll('.mod-check:checked')).map(c => c.value);
  return selectedModules;
}

function validateUrl(url) {
  try { const u = new URL(url); return u.protocol === 'http:' || u.protocol === 'https:'; }
  catch { return false; }
}

async function startScan() {
  const url   = document.getElementById('scan-url').value.trim();
  const errEl = document.getElementById('url-error');
  const mods  = getModules();
  if (!url)              { errEl.textContent = 'Enter a target URL.'; return; }
  if (!validateUrl(url)) { errEl.textContent = 'Enter a valid URL starting with https://'; return; }
  if (!mods.length)      { errEl.textContent = 'Select at least one module.'; return; }
  errEl.textContent = '';
  const profile = document.querySelector('.profile-pill.active').dataset.profile;
  document.getElementById('btn-scan').disabled = true;
  document.getElementById('btn-scan').textContent = '... Scanning';
  document.getElementById('scan-progress').style.display = 'block';
  document.getElementById('results-area').innerHTML = '';
  document.getElementById('result-actions').style.display = 'none';
  document.getElementById('scan-eta').textContent = '';
  try {
    const res = await fetch(window.SCANNER_API + '/scan/start', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ url, modules: mods, profile })
    });
    const data = await res.json();
    if (!data.job_id) throw new Error('No job ID returned');
    pollJob(data.job_id, mods, profile);
  } catch (e) {
    showError('Could not reach scanner API: ' + e.message);
    resetScanBtn();
  }
}

function pollJob(jobId, mods, profile) {
  pollInterval = setInterval(async () => {
    try {
      const res = await fetch(window.SCANNER_API + '/scan/status/' + jobId);
      const job = await res.json();
      setProgress(job);
      if (job.status === 'done') {
        clearInterval(pollInterval);
        renderResults(job.result);
        saveScan(jobId, job.result, mods, profile);
        resetScanBtn();
        document.getElementById('scan-eta').textContent = '';
      }
      if (job.status === 'error') {
        clearInterval(pollInterval);
        showError('Scan error: ' + job.error);
        resetScanBtn();
      }
    } catch (e) {}
  }, 1000);
}

function setProgress(job) {
  const pct       = job.progress  || 0;
  const status    = job.status;
  const remaining = job.remaining ?? null;

  document.getElementById('progress-fill').style.width = pct + '%';

  const msgs = {
    pending: 'Queued...',
    running: pct < 40 ? 'Passive checks: WHOIS, SSL, DNS, headers...' :
             pct < 70 ? 'Active checks: ports, CMS, CVE...' :
                        'Advanced checks: XSS, SQLi, cookies, WAF...',
    done:    'Complete.',
    error:   'Error.',
  };
  document.getElementById('progress-status').textContent = msgs[status] || status;

  const etaEl = document.getElementById('scan-eta');
  if (remaining !== null && status === 'running') {
    if (remaining > 0) {
      const mins = Math.floor(remaining / 60);
      const secs = remaining % 60;
      etaEl.textContent = mins > 0
        ? '~' + mins + 'm ' + secs + 's remaining'
        : '~' + secs + 's remaining';
    } else {
      etaEl.textContent = 'Finishing up...';
    }
  } else if (status === 'done') {
    etaEl.textContent = '';
  }
}

function resetScanBtn() {
  const btn = document.getElementById('btn-scan');
  btn.disabled = false;
  btn.textContent = '\u25BA Scan';
}

function resetScan() {
  document.getElementById('results-area').innerHTML = '';
  document.getElementById('result-actions').style.display = 'none';
  document.getElementById('scan-progress').style.display = 'none';
  document.getElementById('scan-url').value = '';
  document.getElementById('scan-input-card').style.display = 'block';
  document.getElementById('scan-eta').textContent = '';
  window._aiFullText = '';
  const aiBtn = document.getElementById('btn-ai');
  if (aiBtn) aiBtn.classList.remove('active');
}

function showError(msg) {
  document.getElementById('results-area').innerHTML = '<div class="hk-error">' + esc(msg) + '</div>';
}

function renderResults(r) {
  const findings = (r.findings || []).sort((a, b) => {
    const o = {critical: 0, high: 1, medium: 2, low: 3, info: 4};
    return (o[a.severity] ?? 9) - (o[b.severity] ?? 9);
  });
  const crits   = findings.filter(f => f.severity === 'critical' && f.status === 'fail');
  const highs   = findings.filter(f => f.severity === 'high'     && f.status === 'fail');
  const mediums = findings.filter(f => f.severity === 'medium'   && f.status !== 'pass');
  const passed  = findings.filter(f => f.status === 'pass');
  const score   = r.score;
  const C       = 2 * Math.PI * 46;
  const offset  = C - (score / 100) * C;
  const scoreColor = score >= 75 ? '#00d4aa' : score >= 50 ? '#ffd166' : '#ff4d6d';
  const gradeClass = score >= 75 ? 'grade-a' : score >= 60 ? 'grade-b' : score >= 50 ? 'grade-c' : 'grade-f';

  const tableIds = ['whois_registrar','whois_expiry','ssl_expiry','ssl_protocol','dns_a',
                    'ports_none','ports_summary','cms_none','cve_headers',
                    'waf_detected','waf_none','sniff_https','session_secure_ok'];
  const tableFindings = tableIds
    .map(id => findings.find(f => f.id === id || (id === 'cms_none' && f.id.startsWith('cms_'))))
    .filter(Boolean).slice(0, 8);

  let html = '<div class="bento-grid">';

  html += `<div class="bento-card bento-score">
    <svg class="gauge-svg" viewBox="0 0 100 100">
      <circle cx="50" cy="50" r="46" fill="none" stroke="#1a2535" stroke-width="6"/>
      <circle cx="50" cy="50" r="46" fill="none" stroke="${scoreColor}" stroke-width="6"
        stroke-dasharray="${C.toFixed(1)}" stroke-dashoffset="${offset.toFixed(1)}"
        stroke-linecap="round" transform="rotate(-90 50 50)"/>
    </svg>
    <div class="gauge-center">
      <div class="gauge-score">${score}</div>
      <div class="gauge-grade ${gradeClass}">${r.grade}</div>
    </div>
    <div class="gauge-label">HakDel Security Index</div>
    <p class="gauge-summary">${esc(r.summary)}</p>
  </div>`;

  html += `<div class="bento-card bento-stat bento-stat-pass"><div class="stat-icon">&#10003;</div><div class="stat-num">${String(passed.length).padStart(2, '0')}</div><div class="stat-label">Passed</div></div>`;
  html += `<div class="bento-card bento-stat bento-stat-warn"><div class="stat-icon">!</div><div class="stat-num">${String(mediums.length).padStart(2, '0')}</div><div class="stat-label">Medium</div></div>`;
  html += `<div class="bento-card bento-stat bento-stat-crit"><div class="stat-icon">&#9888;</div><div class="stat-num">${String(crits.length + highs.length).padStart(2, '0')}</div><div class="stat-label">Crit / High</div></div>`;

  html += `<div class="bento-card bento-table">
    <div class="bento-table-header">
      <span class="bento-table-title">Module Breakdown</span>
      <span class="bento-table-meta">${esc(r.target)}</span>
    </div>
    <div class="bento-table-body">
      ${tableFindings.map(f => `
      <div class="bento-table-row">
        <span class="btr-title">${esc(f.title)}</span>
        <span class="btr-status ${f.status === 'pass' ? 'btr-pass' : f.status === 'fail' ? 'btr-fail' : 'btr-warn'}">${f.status.toUpperCase()}</span>
      </div>`).join('')}
    </div>
  </div>`;

  const FINDING_CATEGORIES = [
    { key: 'ssl',     label: 'SSL & Certificates',   icon: '&#128274;', prefixes: ['ssl_', 'http_redirect'] },
    { key: 'headers', label: 'HTTP Headers',          icon: '&#128196;', prefixes: ['hdr_', 'header_'] },
    { key: 'dns',     label: 'DNS & Email',           icon: '&#9993;',   prefixes: ['dns_', 'whois_', 'email_', 'smtp_'] },
    { key: 'cookies', label: 'Cookies & Sessions',    icon: '&#127850;', prefixes: ['cookie_', 'session_'] },
    { key: 'vulns',   label: 'Vulnerabilities',       icon: '&#9888;',   prefixes: ['xss_', 'sqli_', 'cors_', 'redirect_'] },
    { key: 'network', label: 'Network & Ports',       icon: '&#128267;', prefixes: ['port_', 'ports_', 'sniff_', 'db_'] },
    { key: 'stack',   label: 'Technology & Stack',    icon: '&#9881;',   prefixes: ['cms_', 'cve_', 'stack_', 'waf_'] },
    { key: 'threat',  label: 'Threat Intelligence',   icon: '&#128269;', prefixes: ['vt_', 'nvd_', 'subdomains_'] },
    { key: 'infra',   label: 'Infrastructure',        icon: '&#128218;', prefixes: ['dir_', 'dirs_', 'access_', 'malware_', 'methods_', 'ratelimit_'] },
  ];

  function findingCategory(f) {
    for (const cat of FINDING_CATEGORIES) {
      for (const prefix of cat.prefixes) {
        if (f.id && f.id.startsWith(prefix)) return cat.key;
      }
    }
    return 'other';
  }

  function renderFindingRow(f) {
    const bc = {critical: 'bdr-crit', high: 'bdr-high', medium: 'bdr-med', low: 'bdr-low', info: 'bdr-info'}[f.severity] || '';
    const sc = {pass: 'fs-pass', fail: 'fs-fail', warn: 'fs-warn', info: 'fs-info'}[f.status] || '';
    const sv = {critical: 'sv-crit', high: 'sv-high', medium: 'sv-med', low: 'sv-low', info: 'sv-info'}[f.severity] || '';
    return `<div class="finding ${bc}" onclick="this.classList.toggle('open')">
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

  // Build groups
  const groupMap = {};
  for (const f of findings) {
    const key = findingCategory(f);
    if (!groupMap[key]) groupMap[key] = [];
    groupMap[key].push(f);
  }

  const allCatDefs = [...FINDING_CATEGORIES, { key: 'other', label: 'Other', icon: '&#9632;', prefixes: [] }];
  let groupsHtml = '';
  let totalCats = 0;

  for (const cat of allCatDefs) {
    const catFindings = groupMap[cat.key];
    if (!catFindings || catFindings.length === 0) continue;
    totalCats++;
    const passCount = catFindings.filter(f => f.status === 'pass').length;
    const failCount = catFindings.filter(f => f.status === 'fail' || f.status === 'warn').length;
    const defaultClosed = failCount === 0;
    groupsHtml += `<div class="findings-group${defaultClosed ? ' fg-closed' : ''}">
      <div class="fg-header" onclick="this.closest('.findings-group').classList.toggle('fg-closed')">
        <span class="fg-icon">${cat.icon}</span>
        <span class="fg-label">${cat.label}</span>
        <span class="fg-counts">
          <span class="fg-pass">${passCount} pass</span>
          ${failCount > 0 ? `<span class="fg-fail">${failCount} issues</span>` : ''}
        </span>
        <span class="fg-caret">&#8964;</span>
      </div>
      <div class="fg-body">
        ${catFindings.map(renderFindingRow).join('')}
      </div>
    </div>`;
  }

  html += `<div class="bento-card bento-findings">
    <div class="bento-findings-header">
      <span class="bento-findings-title">All Findings</span>
      <span class="bento-findings-count">${totalCats} categories &nbsp;&middot;&nbsp; ${findings.length} checks total</span>
    </div>
    ${groupsHtml}
  </div>`;

  html += `<div class="bento-footer">HakDel Security Engine &nbsp;&middot;&nbsp; Score: ${score}/100 (${r.grade}) &nbsp;&middot;&nbsp; ${esc(r.scanned_at)}</div>`;
  html += '</div>';

  document.getElementById('results-area').innerHTML = html;
  document.getElementById('result-actions').style.display = 'flex';
  document.getElementById('scan-input-card').style.display = 'none';
  window._lastResult = r;

  // Inject AI Analysis section below results
  const aiWrap = document.createElement('div');
  aiWrap.id = 'ai-analysis-wrap';
  aiWrap.style.cssText = 'margin-top:20px;';
  aiWrap.innerHTML = `
    <div class="ai-analysis-card" id="ai-card" style="display:none;">
      <div class="ai-card-header">
        <span class="ai-card-icon">&#129302;</span>
        <span>AI Security Analysis</span>
        <button class="ai-copy-btn" id="ai-copy-btn" onclick="copyAIAnalysis()" style="display:none;">
          &#128203; Copy
        </button>
      </div>
      <div id="ai-loading" class="ai-loading" style="display:none;">
        <div class="tool-loading-dots"><span></span><span></span><span></span></div>
        <span style="font-family:var(--mono);font-size:13px;color:var(--text3);margin-left:14px;">
          AI is analysing your scan results&hellip;
        </span>
      </div>
      <div id="ai-output" class="ai-output"></div>
    </div>`;
  document.getElementById('results-area').appendChild(aiWrap);
}

function exportReport() {
  if (!window._lastResult) return;
  const r = window._lastResult;
  let txt = 'HAKDEL SECURITY REPORT\nTarget: ' + r.target + '\nScore: ' + r.score + '/100 (' + r.grade + ')\nDate: ' + r.scanned_at + '\n\nFINDINGS\n' + '='.repeat(60) + '\n';
  for (const f of r.findings)
    txt += '\n[' + f.status.toUpperCase() + '] [' + f.severity.toUpperCase() + '] ' + f.title + '\n  ' + f.detail + (f.remediation ? '\n  Fix: ' + f.remediation : '') + '\n';
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([txt], {type: 'text/plain'}));
  a.download = 'hakdel-report-' + Date.now() + '.txt';
  a.click();
}

function openPdfReport() {
  if (!window._lastResult || !window._lastResult._scan_id) {
    alert('Save a scan first or wait for it to be saved.');
    return;
  }
  window.open('/scanner/report_pdf.php?scan_id=' + window._lastResult._scan_id, '_blank');
}

async function saveScan(jobId, result, modules, profile) {
  try {
    const res = await fetch('/scanner/save.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        job_id:     jobId,
        target_url: result.target,
        profile:    profile,
        modules:    modules,
        score:      result.score,
        grade:      result.grade,
        summary:    result.summary,
        result:     result,
      })
    });
    const data = await res.json();
    if (data.id) window._lastResult._scan_id = data.id;
    else if (data.scan_id) window._lastResult._scan_id = data.scan_id;
  } catch (e) {}
}

function esc(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ── AI Analysis ──────────────────────────────────────────────────────────────

window._aiFullText = '';

async function requestAIAnalysis() {
  const btn = document.getElementById('btn-ai');
  const card = document.getElementById('ai-card');
  const output = document.getElementById('ai-output');
  const loading = document.getElementById('ai-loading');

  if (!card) return;

  // Toggle off if already shown with content
  if (card.style.display !== 'none' && window._aiFullText) {
    card.style.display = 'none';
    if (btn) btn.classList.remove('active');
    return;
  }

  card.style.display = 'block';
  card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  if (btn) btn.classList.add('active');

  // Already have cached result
  if (window._aiFullText) {
    output.innerHTML = renderMarkdown(window._aiFullText);
    return;
  }

  // Wait for scan_id (saveScan is async — may take a moment)
  let scanId = window._lastResult?._scan_id;
  if (!scanId) {
    loading.style.display = 'flex';
    output.innerHTML = '';
    for (let i = 0; i < 20 && !scanId; i++) {
      await new Promise(r => setTimeout(r, 500));
      scanId = window._lastResult?._scan_id;
    }
    if (!scanId) {
      loading.style.display = 'none';
      output.innerHTML = '<div class="ai-error">Scan not saved yet. Please wait a moment and try again.</div>';
      return;
    }
  }

  loading.style.display = 'flex';
  output.innerHTML = '';
  window._aiFullText = '';

  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';

  try {
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('scan_id', scanId);

    const response = await fetch('/tools/ai_analysis.php', { method: 'POST', body: fd });

    if (!response.ok) {
      throw new Error('HTTP ' + response.status);
    }

    const reader  = response.body.getReader();
    const decoder = new TextDecoder();
    let   buf     = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buf += decoder.decode(value, { stream: true });

      // Process complete SSE lines
      let nl;
      while ((nl = buf.indexOf('\n\n')) !== -1) {
        const block = buf.substring(0, nl);
        buf = buf.substring(nl + 2);

        for (const line of block.split('\n')) {
          if (!line.startsWith('data: ')) continue;
          let payload;
          try { payload = JSON.parse(line.slice(6)); } catch { continue; }

          if (payload.error) {
            loading.style.display = 'none';
            output.innerHTML = `<div class="ai-error">&#9888; ${payload.error}</div>`;
            return;
          }

          if (payload.cached) {
            // Return full cached text
            window._aiFullText = payload.text;
            loading.style.display = 'none';
            output.innerHTML = renderMarkdown(payload.text);
            document.getElementById('ai-copy-btn').style.display = 'inline-flex';
            return;
          }

          if (payload.text) {
            loading.style.display = 'none';
            window._aiFullText += payload.text;
            // Stream: update output as markdown renders progressively
            output.innerHTML = renderMarkdown(window._aiFullText);
          }

          if (payload.done) {
            document.getElementById('ai-copy-btn').style.display = 'inline-flex';
          }
        }
      }
    }
  } catch (e) {
    loading.style.display = 'none';
    output.innerHTML = `<div class="ai-error">&#9888; ${e.message || 'Failed to connect to AI service.'}</div>`;
  }
}

function copyAIAnalysis() {
  if (!window._aiFullText) return;
  navigator.clipboard.writeText(window._aiFullText).then(() => {
    const btn = document.getElementById('ai-copy-btn');
    const orig = btn.innerHTML;
    btn.innerHTML = '&#10003; Copied!';
    setTimeout(() => { btn.innerHTML = orig; }, 2000);
  });
}

// Simple markdown renderer for AI output
function renderMarkdown(md) {
  if (!md) return '';
  let html = md
    // Escape HTML first
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    // Bold
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    // Italic
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    // Inline code
    .replace(/`([^`]+)`/g, '<code class="ai-inline-code">$1</code>')
    // H2
    .replace(/^## (.+)$/gm, '<h2 class="ai-h2">$1</h2>')
    // H3
    .replace(/^### (.+)$/gm, '<h3 class="ai-h3">$1</h3>')
    // H4
    .replace(/^#### (.+)$/gm, '<h4 class="ai-h4">$1</h4>')
    // Numbered list items
    .replace(/^(\d+)\. (.+)$/gm, '<li class="ai-li ai-oli"><span class="ai-num">$1.</span> $2</li>')
    // Bullet list items
    .replace(/^[-*] (.+)$/gm, '<li class="ai-li">$1</li>')
    // Wrap consecutive <li> in <ul>/<ol>
    .replace(/(<li class="ai-li">(?:.*\n?)*?<\/li>)/g, (m) => '<ul class="ai-ul">' + m + '</ul>');

  // Paragraphs — wrap lines not already wrapped in block tags
  const lines = html.split('\n');
  const result = [];
  for (const line of lines) {
    const t = line.trim();
    if (!t) { result.push(''); continue; }
    if (/^<(h[2-4]|ul|li|\/ul)/.test(t)) {
      result.push(t);
    } else {
      result.push('<p class="ai-p">' + t + '</p>');
    }
  }
  return result.join('\n');
}
