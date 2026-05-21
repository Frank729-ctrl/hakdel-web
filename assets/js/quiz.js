/* quiz/index.php — quiz UI logic
   Requires: window.ALL_QUESTIONS (array) set by the PHP page before this script loads. */

let filteredQuestions = [];
let currentIndex   = 0;
let sessionAnswers = [];
let selectedDomain = 'all';

document.addEventListener('DOMContentLoaded', () => {
  filteredQuestions = [...window.ALL_QUESTIONS];
});

// ── Domain selection ──────────────────────────────────────────────────────────
function selectDomain(btn) {
  document.querySelectorAll('.domain-pill').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  selectedDomain = btn.dataset.domain;
  filteredQuestions = selectedDomain === 'all'
    ? [...window.ALL_QUESTIONS]
    : window.ALL_QUESTIONS.filter(q => q.domain === selectedDomain);
  document.getElementById('quiz-start-info').textContent =
    filteredQuestions.length + ' question' + (filteredQuestions.length !== 1 ? 's' : '') + ' available';
}

// ── Shuffle helper ────────────────────────────────────────────────────────────
function shuffle(arr) {
  for (let i = arr.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [arr[i], arr[j]] = [arr[j], arr[i]];
  }
  return arr;
}

// ── Start quiz ────────────────────────────────────────────────────────────────
function startQuiz() {
  if (filteredQuestions.length === 0) return;
  filteredQuestions = shuffle([...filteredQuestions]);
  currentIndex   = 0;
  sessionAnswers = [];
  document.getElementById('quiz-lobby').style.display   = 'none';
  document.getElementById('quiz-results').style.display = 'none';
  document.getElementById('quiz-active').style.display  = 'block';
  showQuestion();
}

// ── Show current question ─────────────────────────────────────────────────────
function showQuestion() {
  const q   = filteredQuestions[currentIndex];
  const tot = filteredQuestions.length;

  document.getElementById('q-progress').textContent =
    'Question ' + (currentIndex + 1) + ' of ' + tot;
  document.getElementById('q-domain-tag').textContent = q.domain;
  document.getElementById('q-difficulty').textContent =
    q.difficulty.charAt(0).toUpperCase() + q.difficulty.slice(1);
  document.getElementById('q-text').textContent = q.question;
  document.getElementById('q-progress-bar').style.width =
    ((currentIndex / tot) * 100) + '%';
  document.getElementById('q-explanation').style.display = 'none';
  document.getElementById('btn-next').style.display = 'none';

  const opts = document.getElementById('q-options');
  opts.innerHTML = '';
  ['a', 'b', 'c', 'd'].forEach(letter => {
    const btn = document.createElement('button');
    btn.className = 'quiz-option';
    btn.dataset.letter = letter;
    btn.innerHTML = '<span class="opt-letter">' + letter.toUpperCase() + '</span>' +
                    '<span class="opt-text">' + esc(q['option_' + letter]) + '</span>';
    btn.onclick = () => selectAnswer(letter, q);
    opts.appendChild(btn);
  });
}

// ── Answer selection ──────────────────────────────────────────────────────────
function selectAnswer(letter, q) {
  document.querySelectorAll('.quiz-option').forEach(b => b.disabled = true);

  const correct   = q.correct;
  const isCorrect = letter === correct;

  document.querySelectorAll('.quiz-option').forEach(b => {
    if (b.dataset.letter === correct)     b.classList.add('opt-correct');
    else if (b.dataset.letter === letter) b.classList.add('opt-wrong');
  });

  const expEl = document.getElementById('q-explanation');
  expEl.style.display = 'flex';
  expEl.className = 'quiz-explanation ' + (isCorrect ? 'exp-correct' : 'exp-wrong');
  document.getElementById('exp-icon').innerHTML      = isCorrect ? '&#10003;' : '&#10007;';
  document.getElementById('exp-verdict').textContent = isCorrect ? 'Correct!' : 'Incorrect';
  document.getElementById('exp-text').textContent    = q.explanation || '';

  sessionAnswers.push({ q, selected: letter, correct: isCorrect });
  saveAnswer(q.id, letter, isCorrect);

  document.getElementById('btn-next').style.display  = 'block';
  document.getElementById('btn-next').textContent =
    currentIndex + 1 < filteredQuestions.length ? 'Next \u203a' : 'See Results \u203a';
}

// ── Next question ─────────────────────────────────────────────────────────────
function nextQuestion() {
  currentIndex++;
  if (currentIndex < filteredQuestions.length) {
    showQuestion();
  } else {
    showResults();
  }
}

// ── Results ───────────────────────────────────────────────────────────────────
function showResults() {
  document.getElementById('quiz-active').style.display  = 'none';
  document.getElementById('quiz-results').style.display = 'block';

  const total   = sessionAnswers.length;
  const correct = sessionAnswers.filter(a => a.correct).length;
  const pct     = total > 0 ? Math.round((correct / total) * 100) : 0;
  const xp      = correct * 10;

  const C = 2 * Math.PI * 44;
  document.getElementById('qr-gauge').setAttribute('stroke-dashoffset', (C - (pct / 100) * C).toFixed(1));
  document.getElementById('qr-gauge').setAttribute('stroke',
    pct >= 70 ? '#00d4aa' : pct >= 50 ? '#ffd166' : '#ff4d6d');

  document.getElementById('qr-pct').textContent   = pct + '%';
  document.getElementById('qr-title').textContent =
    pct >= 80 ? 'Excellent work!' : pct >= 60 ? 'Good effort!' : 'Keep practising.';
  document.getElementById('qr-sub').textContent   = correct + ' / ' + total + ' correct';
  document.getElementById('qr-xp').textContent    = '+' + xp + ' XP earned';

  if (xp > 0) awardQuizXP(xp);
}

function reviewAnswers() {
  const wrap = document.getElementById('qr-review');
  if (wrap.style.display === 'block') { wrap.style.display = 'none'; return; }
  wrap.style.display = 'block';

  let html = '';
  sessionAnswers.forEach((a, i) => {
    const ic      = a.correct ? 'btr-pass' : 'btr-fail';
    const verdict = a.correct ? 'CORRECT' : 'WRONG';
    html += `
    <div class="bento-table-row" style="flex-direction:column;align-items:flex-start;gap:6px;padding:14px 18px">
      <div style="display:flex;justify-content:space-between;width:100%;align-items:center">
        <span style="font-size:13px;font-weight:500;color:var(--text)">${i + 1}. ${esc(a.q.question)}</span>
        <span class="btr-status ${ic}" style="flex-shrink:0;margin-left:12px">${verdict}</span>
      </div>
      <div style="font-size:12px;color:var(--text2)">
        Your answer: <strong style="color:${a.correct ? 'var(--accent)' : 'var(--danger)'}">${a.selected.toUpperCase()}. ${esc(a.q['option_' + a.selected])}</strong>
        ${!a.correct ? ' &nbsp;&middot;&nbsp; Correct: <strong style="color:var(--accent)">' + a.q.correct.toUpperCase() + '. ' + esc(a.q['option_' + a.q.correct]) + '</strong>' : ''}
      </div>
      ${a.q.explanation ? '<div style="font-size:12px;color:var(--text2);font-style:italic">' + esc(a.q.explanation) + '</div>' : ''}
    </div>`;
  });
  document.getElementById('qr-review-body').innerHTML = html;
}

function resetQuiz() {
  document.getElementById('quiz-results').style.display = 'none';
  document.getElementById('qr-review').style.display    = 'none';
  document.getElementById('quiz-lobby').style.display   = 'block';
}

// ── API calls ─────────────────────────────────────────────────────────────────
async function saveAnswer(questionId, answer, isCorrect) {
  try {
    await fetch('/quiz/save_answer.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ question_id: questionId, answer, is_correct: isCorrect })
    });
  } catch (e) {}
}

async function awardQuizXP(xp) {
  try {
    await fetch('/quiz/award_xp.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ amount: xp, reason: 'quiz' })
    });
  } catch (e) {}
}

function esc(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
