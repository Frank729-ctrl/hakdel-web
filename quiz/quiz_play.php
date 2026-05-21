<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$slug      = trim($_GET['slug'] ?? '');
$req_tier  = max(1, min(3, (int)($_GET['tier'] ?? 1)));
if (!$slug) { header('Location: /quiz/'); exit; }

$CAT_COLORS = [
    'it-fundamentals'    => '#00d4aa',
    'terminal-linux'     => '#00e676',
    'cs-fundamentals'    => '#7f77dd',
    'networking'         => '#0094ff',
    'cyber-awareness'    => '#ffd166',
    'web-security'       => '#ff4d6d',
    'cryptography'       => '#bd4fff',
    'malware'            => '#ff6b35',
    'social-engineering' => '#ff3cac',
    'cloud-security'     => '#48cae4',
    'ceh-domains'        => '#f72585',
];

// Load category
$cat_stmt = db()->prepare('SELECT * FROM quiz_categories WHERE slug = ? AND is_active = 1');
$cat_stmt->execute([$slug]);
$cat = $cat_stmt->fetch();
if (!$cat || (int)$cat['level_required'] > $level) { header('Location: /quiz/'); exit; }

$color = $CAT_COLORS[$slug] ?? '#00d4aa';

// Verify tier is unlocked
if ($req_tier > 1) {
    $unlock_stmt = db()->prepare('
        SELECT unlocked FROM quiz_tier_progress
        WHERE user_id = ? AND category_slug = ? AND tier = ?
    ');
    $unlock_stmt->execute([$user['id'], $slug, $req_tier - 1]);
    $prev = $unlock_stmt->fetch();
    if (!$prev || !$prev['unlocked']) {
        header('Location: /quiz/quiz_category.php?slug=' . urlencode($slug));
        exit;
    }
}

// Determine max available tier
$max_tier = 1;
for ($t = 2; $t <= 3; $t++) {
    $chk = db()->prepare('SELECT unlocked FROM quiz_tier_progress WHERE user_id = ? AND category_slug = ? AND tier = ?');
    $chk->execute([$user['id'], $slug, $t - 1]);
    $r = $chk->fetch();
    if ($r && $r['unlocked']) $max_tier = $t;
}

// Load questions up to max_tier
$q_stmt = db()->prepare('
    SELECT id, domain, domain_number, question,
           option_a, option_b, option_c, option_d,
           correct, explanation, difficulty, tier, points
    FROM quiz_questions
    WHERE category = ? AND tier <= ? AND is_active = 1
    ORDER BY tier ASC
');
$q_stmt->execute([$slug, $max_tier]);
$questions_raw = $q_stmt->fetchAll();

if (empty($questions_raw)) {
    header('Location: /quiz/quiz_category.php?slug=' . urlencode($slug));
    exit;
}

// Pass to JS as JSON (no correct answer exposed — checked server-side)
$questions_js = array_map(function($q) {
    return [
        'id'          => (int)$q['id'],
        'domain'      => $q['domain'] ?? '',
        'question'    => $q['question'],
        'option_a'    => $q['option_a'],
        'option_b'    => $q['option_b'],
        'option_c'    => $q['option_c'],
        'option_d'    => $q['option_d'],
        'correct'     => $q['correct'],   // needed for client-side feedback
        'explanation' => $q['explanation'] ?? '',
        'difficulty'  => $q['difficulty'] ?? 'medium',
        'tier'        => (int)$q['tier'],
        'points'      => (int)$q['points'],
    ];
}, $questions_raw);

$nav_active     = 'quiz';
$sidebar_sub    = 'Quiz';
$sidebar_footer = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel — <?php echo h($cat['name']); ?> Quiz</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">

<?php require __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="hk-main">

    <!-- Back link -->
    <div style="margin-bottom:8px">
      <a href="/quiz/quiz_category.php?slug=<?php echo urlencode($slug); ?>" class="hk-back-link" id="back-link">&#8592; <?php echo h($cat['name']); ?></a>
    </div>

    <!-- Quiz active view -->
    <div id="quiz-active" style="display:none">
      <div class="quiz-card" style="--cat-color:<?php echo $color; ?>">

        <div class="quiz-progress-header">
          <span class="quiz-progress-label" id="q-progress">Question 1 of 10</span>
          <span class="quiz-domain-tag" id="q-cat-tag"><?php echo h($cat['name']); ?></span>
          <span class="qplay-tier-badge" id="q-tier-badge">T1</span>
          <span class="quiz-difficulty" id="q-difficulty">Easy</span>
          <span class="qplay-xp-counter" id="xp-counter">+0 XP</span>
        </div>

        <div class="progress-bar-wrap">
          <div class="progress-bar-fill" id="q-progress-bar" style="width:0%"></div>
        </div>

        <div class="quiz-question" id="q-text"></div>

        <div class="quiz-options" id="q-options"></div>

        <div class="quiz-explanation" id="q-explanation" style="display:none">
          <div class="explanation-icon" id="exp-icon"></div>
          <div class="explanation-body">
            <div class="explanation-verdict" id="exp-verdict"></div>
            <div class="explanation-text" id="exp-text"></div>
            <div class="qplay-pts" id="exp-pts"></div>
          </div>
        </div>

        <div class="quiz-actions">
          <button class="btn-scan" id="btn-next" style="display:none" onclick="nextQuestion()">Next ›</button>
        </div>

      </div>
    </div>

    <!-- Session results -->
    <div id="quiz-results" style="display:none">
      <div class="quiz-results-card">
        <div class="qr-score-wrap">
          <svg width="110" height="110" viewBox="0 0 110 110">
            <circle cx="55" cy="55" r="44" fill="none" stroke="var(--bg4)" stroke-width="8"/>
            <circle cx="55" cy="55" r="44" fill="none" stroke-width="8"
                    stroke-linecap="round"
                    stroke-dasharray="<?php echo round(2 * M_PI * 44, 1); ?>"
                    stroke-dashoffset="<?php echo round(2 * M_PI * 44, 1); ?>"
                    transform="rotate(-90 55 55)"
                    id="qr-gauge" style="transition:stroke-dashoffset 0.8s ease,stroke 0.4s"/>
          </svg>
          <div class="qr-score-center">
            <div class="qr-pct" id="qr-pct">0%</div>
          </div>
        </div>
        <div class="qr-info">
          <div class="qr-title" id="qr-title">Loading…</div>
          <div class="qr-sub"  id="qr-sub"></div>
          <div class="qr-xp"   id="qr-xp"></div>
          <div class="qr-unlock" id="qr-unlock" style="display:none"></div>
        </div>
        <div class="qr-actions">
          <button class="btn-scan" onclick="continueQuiz()">Continue +10</button>
          <a href="/quiz/quiz_category.php?slug=<?php echo urlencode($slug); ?>"
             class="btn-secondary" style="text-align:center;display:block;text-decoration:none;padding:9px 18px">Back to Category</a>
        </div>
      </div>

      <!-- Missed questions review -->
      <div class="hk-bento" id="qr-review" style="display:none">
        <div class="hk-bento-label">Review — Incorrect Answers</div>
        <div id="qr-review-body"></div>
      </div>
      <button class="btn-export" onclick="toggleReview()" style="margin-top:8px" id="btn-review">Show Review</button>
    </div>

  </main>
</div>

<script>
// ── Data injected by PHP ──────────────────────────────────────────────────────
const ALL_QUESTIONS   = <?php echo json_encode(array_values($questions_js), JSON_UNESCAPED_UNICODE); ?>;
const CATEGORY_SLUG   = <?php echo json_encode($slug); ?>;
const CATEGORY_NAME   = <?php echo json_encode($cat['name']); ?>;
const MAX_UNLOCKED_TIER = <?php echo $max_tier; ?>;
const SESSION_SIZE    = 10;
const CURRENT_TIER    = <?php echo $req_tier; ?>;

// ── State ─────────────────────────────────────────────────────────────────────
let correctStreak   = 0;
let wrongStreak     = 0;
let sessionAnswers  = [];
let sessionXP       = 0;
let sessionStart    = 0;
let currentQ        = null;

// Split questions by tier
const tierPools = {1: [], 2: [], 3: []};
ALL_QUESTIONS.forEach(q => {
    const t = q.tier || 1;
    if (tierPools[t]) tierPools[t].push(q);
});
const tierIdx = {1: 0, 2: 0, 3: 0};
Object.values(tierPools).forEach(shuffle);

// ── Start on page load ────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('quiz-active').style.display  = 'block';
    document.getElementById('quiz-results').style.display = 'none';
    sessionStart = 0;
    sessionAnswers = [];
    sessionXP = 0;
    showQuestion();
});

// ── Adaptive tier selection ───────────────────────────────────────────────────
function pickTier() {
    const avail = Object.keys(tierPools).filter(t => parseInt(t) <= MAX_UNLOCKED_TIER && tierPools[t].length > 0);
    if (avail.length === 0) return 1;
    if (avail.length === 1) return parseInt(avail[0]);

    let weights = {};
    if (MAX_UNLOCKED_TIER === 1) {
        weights = {1: 1};
    } else if (MAX_UNLOCKED_TIER === 2) {
        if (correctStreak >= 3)      weights = {1: 0.25, 2: 0.75};
        else if (wrongStreak >= 2)   weights = {1: 0.80, 2: 0.20};
        else                         weights = {1: 0.60, 2: 0.40};
    } else {
        if (correctStreak >= 3)      weights = {1: 0.20, 2: 0.30, 3: 0.50};
        else if (wrongStreak >= 2)   weights = {1: 0.60, 2: 0.30, 3: 0.10};
        else                         weights = {1: 0.40, 2: 0.35, 3: 0.25};
    }

    const rand = Math.random();
    let cum = 0;
    for (const [t, w] of Object.entries(weights)) {
        cum += w;
        if (rand <= cum && parseInt(t) <= MAX_UNLOCKED_TIER) return parseInt(t);
    }
    return parseInt(avail[avail.length - 1]);
}

function pickQuestion() {
    const tier = pickTier();
    const pool = tierPools[tier];
    if (!pool || pool.length === 0) {
        // fallback: any available
        for (let t = 1; t <= 3; t++) {
            if (tierPools[t] && tierPools[t].length > 0) {
                const q = tierPools[t][tierIdx[t] % tierPools[t].length];
                tierIdx[t]++;
                return q;
            }
        }
        return ALL_QUESTIONS[0];
    }
    const q = pool[tierIdx[tier] % pool.length];
    tierIdx[tier]++;
    if (tierIdx[tier] >= pool.length) {
        tierIdx[tier] = 0;
        shuffle(pool);
    }
    return q;
}

// ── Display question ──────────────────────────────────────────────────────────
function showQuestion() {
    const num = sessionAnswers.length + 1;
    currentQ  = pickQuestion();
    const tot = SESSION_SIZE;

    document.getElementById('q-progress').textContent      = 'Question ' + num + ' of ' + tot;
    document.getElementById('q-tier-badge').textContent    = 'T' + (currentQ.tier || 1);
    document.getElementById('q-difficulty').textContent    = cap(currentQ.difficulty || 'medium');
    document.getElementById('q-text').textContent          = currentQ.question;
    document.getElementById('q-progress-bar').style.width  = (((num - 1) / tot) * 100) + '%';
    document.getElementById('q-explanation').style.display = 'none';
    document.getElementById('btn-next').style.display      = 'none';

    const opts = document.getElementById('q-options');
    opts.innerHTML = '';
    ['a','b','c','d'].forEach(letter => {
        const btn = document.createElement('button');
        btn.className    = 'quiz-option';
        btn.dataset.letter = letter;
        btn.innerHTML = '<span class="opt-letter">' + letter.toUpperCase() + '</span>' +
                        '<span class="opt-text">' + esc(currentQ['option_' + letter]) + '</span>';
        btn.onclick = () => selectAnswer(letter);
        opts.appendChild(btn);
    });
}

// ── Answer handling ───────────────────────────────────────────────────────────
function selectAnswer(letter) {
    document.querySelectorAll('.quiz-option').forEach(b => b.disabled = true);
    const isCorrect = letter === currentQ.correct;
    const pts       = isCorrect ? (currentQ.points || 10) : 0;

    document.querySelectorAll('.quiz-option').forEach(b => {
        if (b.dataset.letter === currentQ.correct) b.classList.add('opt-correct');
        else if (b.dataset.letter === letter)      b.classList.add('opt-wrong');
    });

    if (isCorrect) {
        correctStreak++;
        wrongStreak = 0;
        sessionXP  += pts;
    } else {
        wrongStreak++;
        correctStreak = 0;
    }

    const expEl = document.getElementById('q-explanation');
    expEl.style.display = 'flex';
    expEl.className = 'quiz-explanation ' + (isCorrect ? 'exp-correct' : 'exp-wrong');
    document.getElementById('exp-icon').innerHTML    = isCorrect ? '&#10003;' : '&#10007;';
    document.getElementById('exp-verdict').textContent = isCorrect ? 'Correct!' : 'Incorrect';
    document.getElementById('exp-text').textContent   = currentQ.explanation || '';
    document.getElementById('exp-pts').textContent    = isCorrect ? '+' + pts + ' XP' : '';
    document.getElementById('xp-counter').textContent = '+' + sessionXP + ' XP';

    sessionAnswers.push({ q: currentQ, selected: letter, correct: isCorrect, pts });
    saveAnswer(currentQ.id, letter, isCorrect, currentQ.tier, pts);

    const isLast = sessionAnswers.length >= SESSION_SIZE;
    const btn = document.getElementById('btn-next');
    btn.style.display  = 'block';
    btn.textContent    = isLast ? 'See Results ›' : 'Next ›';
}

// ── Next / results ────────────────────────────────────────────────────────────
function nextQuestion() {
    if (sessionAnswers.length >= SESSION_SIZE) {
        showResults();
    } else {
        showQuestion();
    }
}

async function showResults() {
    document.getElementById('quiz-active').style.display  = 'none';
    document.getElementById('quiz-results').style.display = 'block';

    const total   = sessionAnswers.length;
    const correct = sessionAnswers.filter(a => a.correct).length;
    const pct     = total > 0 ? Math.round((correct / total) * 100) : 0;
    const C       = 2 * Math.PI * 44;

    const gauge = document.getElementById('qr-gauge');
    gauge.setAttribute('stroke-dashoffset', (C - (pct / 100) * C).toFixed(1));
    gauge.setAttribute('stroke', pct >= 70 ? '#00d4aa' : pct >= 50 ? '#ffd166' : '#ff4d6d');

    document.getElementById('qr-pct').textContent  = pct + '%';
    document.getElementById('qr-title').textContent =
        pct >= 80 ? 'Excellent work!' : pct >= 60 ? 'Good effort!' : 'Keep practising.';
    document.getElementById('qr-sub').textContent   = correct + ' / ' + total + ' correct';
    document.getElementById('qr-xp').textContent = 'Calculating XP…';

    // Award XP + check tier unlock via complete_session.php
    try {
        const res = await fetch('/quiz/complete_session.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                category_slug: CATEGORY_SLUG,
                tier:          CURRENT_TIER,
                correct_count: correct,
                total_count:   total
            })
        });
        const data = await res.json();

        const awarded = data.total_xp_awarded || 0;
        document.getElementById('qr-xp').textContent =
            awarded > 0 ? '+' + awarded + ' XP earned this session' : 'No XP awarded this session';

        if (data.tier_unlocked) {
            const el = document.getElementById('qr-unlock');
            el.style.display = 'flex';
            el.innerHTML = '<span class="qr-unlock-badge">&#127775; Tier ' + data.new_tier + ' Unlocked!</span>';
            el.classList.add('unlock-flash');
        }

        if (awarded > 0 && typeof window.showXPNotification === 'function') {
            window.showXPNotification(data);
        }
    } catch (e) {
        document.getElementById('qr-xp').textContent = '+' + sessionXP + ' XP earned this session';
    }
}

function continueQuiz() {
    document.getElementById('quiz-results').style.display = 'none';
    document.getElementById('quiz-active').style.display  = 'block';
    document.getElementById('qr-review').style.display    = 'none';
    sessionAnswers = [];
    sessionXP      = 0;
    correctStreak  = 0;
    wrongStreak    = 0;
    showQuestion();
}

function toggleReview() {
    const wrap = document.getElementById('qr-review');
    const btn  = document.getElementById('btn-review');
    if (wrap.style.display === 'block') {
        wrap.style.display = 'none';
        btn.textContent = 'Show Review';
        return;
    }
    wrap.style.display = 'block';
    btn.textContent = 'Hide Review';

    const wrong = sessionAnswers.filter(a => !a.correct);
    if (wrong.length === 0) {
        document.getElementById('qr-review-body').innerHTML = '<div style="padding:14px;color:var(--accent);font-family:var(--mono)">Perfect session — no incorrect answers!</div>';
        return;
    }

    let html = '';
    wrong.forEach((a, i) => {
        html += `
        <div class="bento-table-row" style="flex-direction:column;align-items:flex-start;gap:6px;padding:14px 18px">
          <div style="display:flex;justify-content:space-between;width:100%;align-items:flex-start;gap:12px">
            <span style="font-size:14px;font-weight:500;color:var(--text)">${esc(a.q.question)}</span>
            <span class="btr-status btr-fail" style="flex-shrink:0">WRONG</span>
          </div>
          <div style="font-size:13px;color:var(--text2)">
            Your answer: <strong style="color:var(--danger)">${a.selected.toUpperCase()}. ${esc(a.q['option_' + a.selected])}</strong>
            &nbsp;&middot;&nbsp; Correct: <strong style="color:var(--accent)">${a.q.correct.toUpperCase()}. ${esc(a.q['option_' + a.q.correct])}</strong>
          </div>
          ${a.q.explanation ? '<div style="font-size:13px;color:var(--text2);font-style:italic">' + esc(a.q.explanation) + '</div>' : ''}
        </div>`;
    });
    document.getElementById('qr-review-body').innerHTML = html;
}

// ── API ───────────────────────────────────────────────────────────────────────
async function saveAnswer(questionId, answer, isCorrect, tier, pts) {
    try {
        await fetch('/quiz/save_quiz_answer.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                question_id:   questionId,
                answer:        answer,
                is_correct:    isCorrect,
                category_slug: CATEGORY_SLUG,
                tier:          tier,
                points:        pts
            })
        });
    } catch (e) {}
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function shuffle(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function cap(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
}
</script>
</body>
</html>
