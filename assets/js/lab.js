/* labs/view.php — copy-to-clipboard and hint toggle */

function copyText(elementId) {
  const el  = document.getElementById(elementId);
  const txt = el.innerText || el.textContent;
  navigator.clipboard.writeText(txt).then(() => {
    const btn = el.nextElementSibling;
    if (!btn) return;
    const orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(() => { btn.textContent = orig; }, 1500);
  }).catch(() => {
    // Fallback for browsers without clipboard API
    const ta = document.createElement('textarea');
    ta.value = txt;
    ta.style.position = 'fixed';
    ta.style.opacity  = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  });
}

function toggleHint(index) {
  const body  = document.getElementById('hint-' + index);
  const caret = document.getElementById('hcaret-' + index);
  const open  = body.style.display === 'block';
  body.style.display  = open ? 'none' : 'block';
  caret.style.transform = open ? '' : 'rotate(180deg)';
}
