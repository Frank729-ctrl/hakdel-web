/* compare.php — scan diff rendering */

function updateUrl() {
  const a = document.getElementById('sel-a').value;
  const b = document.getElementById('sel-b').value;
  if (a && b) window.location.href = '?a=' + a + '&b=' + b;
}

// Render diff if both scan findings are available
(function() {
  if (typeof window.SCAN_A_FINDINGS === 'undefined') return;

  const a = window.SCAN_A_FINDINGS;
  const b = window.SCAN_B_FINDINGS;

  // Index by finding ID
  const aMap = {};
  a.forEach(f => aMap[f.id] = f);
  const bMap = {};
  b.forEach(f => bMap[f.id] = f);

  const allIds = [...new Set([...Object.keys(aMap), ...Object.keys(bMap)])];

  const fixed    = [];  // was fail/warn in A, now pass in B
  const regressed= [];  // was pass in A, now fail/warn in B
  const newIssues= [];  // not in A, fail/warn in B
  const resolved = [];  // was fail/warn in A, not in B
  const unchanged= [];  // same status

  allIds.forEach(id => {
    const fa = aMap[id];
    const fb = bMap[id];
    const aBad = fa && (fa.status === 'fail' || fa.status === 'warn');
    const bBad = fb && (fb.status === 'fail' || fb.status === 'warn');
    const aPass = fa && fa.status === 'pass';
    const bPass = fb && fb.status === 'pass';

    if (aBad && bPass)       fixed.push({ a: fa, b: fb });
    else if (aPass && bBad)  regressed.push({ a: fa, b: fb });
    else if (!fa && bBad)    newIssues.push({ b: fb });
    else if (aBad && !fb)    resolved.push({ a: fa });
    else if (fa && fb && fa.status !== fb.status) regressed.push({ a: fa, b: fb });
    else if (fb)             unchanged.push({ b: fb });
  });

  let html = '';

  function fRow(label, f, colorClass) {
    if (!f) return '';
    const sc = {pass:'fs-pass',fail:'fs-fail',warn:'fs-warn',info:'fs-info'}[f.status]||'';
    return `<div class="diff-row ${colorClass}">
      <span class="diff-tag">${label}</span>
      <span class="finding-status ${sc}">${f.status.toUpperCase()}</span>
      <span class="diff-title">${esc(f.title)}</span>
      <span class="finding-sev sv-${f.severity||'info'}">${(f.severity||'info').toUpperCase()}</span>
    </div>`;
  }

  function section(title, colorClass, items, renderer) {
    if (!items.length) return '';
    return `<div class="diff-section">
      <div class="diff-section-title ${colorClass}">${title} (${items.length})</div>
      ${items.map(renderer).join('')}
    </div>`;
  }

  html += section('&#10003; Fixed — issues resolved in Scan B', 'dsc-fixed',
    fixed, i => fRow('FIXED', i.b, 'dr-fixed'));
  html += section('&#9660; Regressed — new issues in Scan B', 'dsc-regressed',
    regressed, i => fRow('WORSE', i.b || i.a, 'dr-regressed'));
  html += section('&#8853; New issues not in Scan A', 'dsc-new',
    newIssues, i => fRow('NEW', i.b, 'dr-new'));
  html += section('&#9632; Unchanged', 'dsc-unchanged',
    unchanged.slice(0, 20), i => fRow('SAME', i.b, 'dr-unchanged'));

  if (!html) html = '<div style="padding:20px;color:var(--text2);font-family:var(--mono)">No differences found — scans are identical.</div>';

  document.getElementById('diff-body').innerHTML = html;
})();

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
