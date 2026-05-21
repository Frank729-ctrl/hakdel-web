<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'incidents';
$uid = (int)$user['id'];
$pdo = db();

$inc_id = (int)($_GET['id'] ?? 0);
if (!$inc_id) redirect('/incidents/');

// Load incident
$stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = ? AND user_id = ?');
$stmt->execute([$inc_id, $uid]);
$inc = $stmt->fetch();
if (!$inc) {
    http_response_code(404);
    echo '<p style="color:white;padding:40px">Incident not found.</p>';
    exit;
}

$topbar_title = h($inc['title']);

// Handle JSON POST actions
if (is_post()) {
    header('Content-Type: application/json');
    if (!verify_csrf($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF error']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $status = $_POST['status'] ?? '';
        $valid = ['open', 'investigating', 'contained', 'resolved', 'closed'];
        if (!in_array($status, $valid)) { echo json_encode(['error' => 'Invalid status']); exit; }
        $pdo->prepare('UPDATE incidents SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
            ->execute([$status, $inc_id, $uid]);
        echo json_encode(['ok' => true, 'status' => $status]);
        exit;
    }

    if ($action === 'update_description') {
        $desc = trim($_POST['description'] ?? '');
        $pdo->prepare('UPDATE incidents SET description = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
            ->execute([$desc ?: null, $inc_id, $uid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_title') {
        $title = trim($_POST['title'] ?? '');
        if (strlen($title) < 3) { echo json_encode(['error' => 'Title too short']); exit; }
        $pdo->prepare('UPDATE incidents SET title = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
            ->execute([$title, $inc_id, $uid]);
        echo json_encode(['ok' => true, 'title' => $title]);
        exit;
    }

    if ($action === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if (!$note) { echo json_encode(['error' => 'Note is empty']); exit; }
        $pdo->prepare('INSERT INTO incident_notes (incident_id, user_id, note) VALUES (?, ?, ?)')
            ->execute([$inc_id, $uid, $note]);
        $note_id = $pdo->lastInsertId();
        $pdo->prepare('UPDATE incidents SET updated_at = NOW() WHERE id = ?')->execute([$inc_id]);
        echo json_encode(['ok' => true, 'id' => $note_id, 'created_at' => date('Y-m-d H:i:s')]);
        exit;
    }

    if ($action === 'add_evidence') {
        $type  = trim($_POST['type']   ?? 'note');
        $title = trim($_POST['title']  ?? '');
        $detail = trim($_POST['detail'] ?? '');
        $ref_id = (int)($_POST['ref_id'] ?? 0) ?: null;
        $valid_types = ['scan', 'ip', 'hash', 'cve', 'url', 'domain', 'note'];
        if (!in_array($type, $valid_types)) { echo json_encode(['error' => 'Invalid type']); exit; }
        if (!$title && !$detail) { echo json_encode(['error' => 'Title or detail required']); exit; }
        $pdo->prepare('INSERT INTO incident_evidence (incident_id, type, ref_id, title, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([$inc_id, $type, $ref_id, $title ?: null, $detail ?: null]);
        $ev_id = $pdo->lastInsertId();
        $pdo->prepare('UPDATE incidents SET updated_at = NOW() WHERE id = ?')->execute([$inc_id]);
        echo json_encode(['ok' => true, 'id' => $ev_id]);
        exit;
    }

    if ($action === 'delete_evidence') {
        $ev_id = (int)($_POST['ev_id'] ?? 0);
        if (!$ev_id) { echo json_encode(['error' => 'Missing ID']); exit; }
        $pdo->prepare('DELETE ie FROM incident_evidence ie
                       JOIN incidents i ON i.id = ie.incident_id
                       WHERE ie.id = ? AND i.user_id = ?')
            ->execute([$ev_id, $uid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM incident_notes WHERE incident_id = ?')->execute([$inc_id]);
        $pdo->prepare('DELETE FROM incident_evidence WHERE incident_id = ?')->execute([$inc_id]);
        $pdo->prepare('DELETE FROM incidents WHERE id = ? AND user_id = ?')->execute([$inc_id, $uid]);
        echo json_encode(['ok' => true, 'redirect' => '/incidents/']);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// Load evidence
$evidence = $pdo->prepare('SELECT * FROM incident_evidence WHERE incident_id = ? ORDER BY added_at ASC');
$evidence->execute([$inc_id]);
$evidence = $evidence->fetchAll();

// Load notes
$notes = $pdo->prepare('SELECT * FROM incident_notes WHERE incident_id = ? ORDER BY created_at ASC');
$notes->execute([$inc_id]);
$notes = $notes->fetchAll();

function ev_icon(string $type): string {
    return match($type) {
        'scan'   => '&#9632;',
        'ip'     => '&#127760;',
        'hash'   => '&#128273;',
        'cve'    => '&#9888;',
        'url'    => '&#128279;',
        'domain' => '&#127760;',
        'note'   => '&#128203;',
        default  => '&#128203;',
    };
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h($inc['title']); ?> — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    .inc-view-layout { display: flex; gap: 20px; align-items: flex-start; }
    .inc-view-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .inc-view-side { width: 300px; flex-shrink: 0; display: flex; flex-direction: column; gap: 16px; }
    .inc-card {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .inc-card-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 18px; border-bottom: 1px solid var(--border);
    }
    .inc-card-title { font-family: var(--mono); font-size: 12px; font-weight: 700; color: var(--text); }
    .inc-card-body { padding: 18px; }
    .sev-badge, .status-badge {
      display: inline-flex; align-items: center;
      font-family: var(--mono); font-size: 10px; font-weight: 700;
      padding: 3px 9px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;
    }
    .sev-critical { background: rgba(220,38,38,0.15); color: #ef4444; border: 1px solid rgba(220,38,38,0.3); }
    .sev-high     { background: rgba(234,88,12,0.15); color: #f97316; border: 1px solid rgba(234,88,12,0.3); }
    .sev-medium   { background: rgba(202,138,4,0.15); color: #fbbf24; border: 1px solid rgba(202,138,4,0.3); }
    .sev-low      { background: rgba(37,99,235,0.15); color: #60a5fa; border: 1px solid rgba(37,99,235,0.3); }
    .sev-info     { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }
    .status-open         { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.25); }
    .status-investigating { background: rgba(251,146,60,0.12); color: #fb923c; border: 1px solid rgba(251,146,60,0.25); }
    .status-contained    { background: rgba(250,204,21,0.12); color: #fbbf24; border: 1px solid rgba(250,204,21,0.25); }
    .status-resolved     { background: rgba(0,212,170,0.12); color: var(--accent); border: 1px solid rgba(0,212,170,0.25); }
    .status-closed       { background: rgba(107,114,128,0.12); color: #9ca3af; border: 1px solid rgba(107,114,128,0.25); }
    .inc-title-display { font-family: var(--mono); font-size: 20px; font-weight: 700; color: var(--text); }
    .inc-title-edit { display: none; background: var(--bg3); border: 1px solid var(--accent); border-radius: var(--radius); padding: 6px 10px; font-family: var(--mono); font-size: 18px; font-weight: 700; color: var(--text); outline: none; width: 100%; }
    .inc-meta-row { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-top: 8px; }
    .inc-meta-item { font-family: var(--mono); font-size: 10px; color: var(--text3); }
    .btn-sm {
      background: var(--bg3); border: 1px solid var(--border);
      color: var(--text2); font-size: 11px; font-family: var(--mono);
      padding: 4px 10px; border-radius: var(--radius); cursor: pointer;
      transition: all 0.12s;
    }
    .btn-sm:hover { color: var(--text); border-color: rgba(255,255,255,0.2); }
    .btn-sm.accent { background: rgba(0,212,170,0.1); border-color: var(--accent); color: var(--accent); }
    .btn-sm.danger { color: var(--danger); }
    .btn-sm.danger:hover { background: rgba(255,77,77,0.08); border-color: rgba(255,77,77,0.3); }
    .desc-display { font-size: 13px; color: var(--text2); line-height: 1.7; white-space: pre-wrap; }
    .desc-display.empty { color: var(--text3); font-style: italic; }
    .desc-edit { display: none; width: 100%; min-height: 100px; background: var(--bg3); border: 1px solid var(--accent); border-radius: var(--radius); padding: 10px 12px; font-size: 13px; color: var(--text); outline: none; resize: vertical; line-height: 1.6; font-family: inherit; }
    .evidence-list { display: flex; flex-direction: column; gap: 8px; }
    .ev-item {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 10px 14px; background: var(--bg3);
      border: 1px solid var(--border); border-radius: var(--radius);
    }
    .ev-icon { font-size: 16px; flex-shrink: 0; margin-top: 2px; }
    .ev-body { flex: 1; min-width: 0; }
    .ev-title { font-size: 13px; font-weight: 600; color: var(--text); }
    .ev-detail { font-size: 11px; color: var(--text2); margin-top: 2px; white-space: pre-wrap; }
    .ev-type-badge {
      display: inline-block; background: var(--bg4);
      font-family: var(--mono); font-size: 9px; color: var(--text3);
      padding: 1px 5px; border-radius: 3px; text-transform: uppercase; margin-bottom: 3px;
    }
    .ev-time { font-family: var(--mono); font-size: 10px; color: var(--text3); margin-top: 3px; }
    .notes-list { display: flex; flex-direction: column; gap: 12px; }
    .note-item {
      padding: 12px 14px; background: var(--bg3);
      border: 1px solid var(--border); border-radius: var(--radius);
      border-left: 3px solid var(--accent);
    }
    .note-text { font-size: 13px; color: var(--text2); line-height: 1.6; white-space: pre-wrap; }
    .note-time { font-family: var(--mono); font-size: 10px; color: var(--text3); margin-top: 6px; }
    .note-add-area {
      display: flex; flex-direction: column; gap: 8px;
    }
    .note-textarea {
      width: 100%; min-height: 80px; background: var(--bg3);
      border: 1px solid var(--border2); border-radius: var(--radius);
      padding: 10px 12px; font-size: 13px; color: var(--text);
      outline: none; resize: vertical; line-height: 1.6; font-family: inherit;
      transition: border-color 0.15s;
    }
    .note-textarea:focus { border-color: var(--accent); }
    .add-ev-form {
      display: none; flex-direction: column; gap: 12px;
      padding: 14px; background: var(--bg3);
      border: 1px solid var(--border); border-radius: var(--radius);
    }
    .add-ev-form.open { display: flex; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .form-field { display: flex; flex-direction: column; gap: 5px; }
    .form-label { font-size: 11px; font-weight: 600; color: var(--text2); }
    .form-input, .form-select, .form-textarea-sm {
      background: var(--bg4); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 8px 10px;
      font-size: 12px; color: var(--text); outline: none;
      transition: border-color 0.15s; font-family: inherit; width: 100%;
    }
    .form-input:focus, .form-select:focus, .form-textarea-sm:focus { border-color: var(--accent); }
    .form-textarea-sm { min-height: 60px; resize: vertical; }
    .status-select {
      background: var(--bg3); border: 1px solid var(--border2);
      border-radius: var(--radius); padding: 7px 10px;
      font-family: var(--mono); font-size: 12px; color: var(--text);
      outline: none; cursor: pointer; width: 100%;
      transition: border-color 0.15s;
    }
    .status-select:focus { border-color: var(--accent); }
    .side-label { font-family: var(--mono); font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .side-val { font-size: 13px; color: var(--text2); }
    .empty-state { padding: 24px; text-align: center; font-size: 13px; color: var(--text3); }
  </style>
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<main class="hk-main">

  <div class="hk-page-header">
    <div>
      <div class="hk-page-eyebrow">INCIDENTS</div>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span class="inc-title-display" id="inc-title-display"><?php echo h($inc['title']); ?></span>
        <input type="text" class="inc-title-edit" id="inc-title-edit" value="<?php echo h($inc['title']); ?>" maxlength="255">
        <button class="btn-sm" id="btn-edit-title" onclick="toggleTitleEdit()">Edit</button>
        <button class="btn-sm accent" id="btn-save-title" style="display:none" onclick="saveTitle()">Save</button>
        <button class="btn-sm" id="btn-cancel-title" style="display:none" onclick="cancelTitleEdit()">Cancel</button>
      </div>
      <div class="inc-meta-row">
        <span class="sev-badge sev-<?php echo h($inc['severity']); ?>" id="sev-badge"><?php echo h($inc['severity']); ?></span>
        <span class="status-badge status-<?php echo h($inc['status']); ?>" id="status-badge-display"><?php echo h($inc['status']); ?></span>
        <span class="inc-meta-item">Created <?php echo date('M j, Y H:i', strtotime($inc['created_at'])); ?></span>
        <span class="inc-meta-item">Updated <?php echo date('M j, Y H:i', strtotime($inc['updated_at'])); ?></span>
      </div>
    </div>
    <div class="hk-page-actions">
      <a href="/incidents/" class="btn-sm" style="text-decoration:none;padding:8px 14px;font-size:12px">&larr; Back</a>
      <button class="btn-sm danger" onclick="deleteIncident()">Delete</button>
    </div>
  </div>

  <div class="inc-view-layout">
    <div class="inc-view-main">

      <!-- Description -->
      <div class="inc-card">
        <div class="inc-card-header">
          <div class="inc-card-title">&#128203; Description</div>
          <div style="display:flex;gap:6px">
            <button class="btn-sm" id="btn-edit-desc" onclick="toggleDescEdit()">Edit</button>
            <button class="btn-sm accent" id="btn-save-desc" style="display:none" onclick="saveDesc()">Save</button>
            <button class="btn-sm" id="btn-cancel-desc" style="display:none" onclick="cancelDescEdit()">Cancel</button>
          </div>
        </div>
        <div class="inc-card-body">
          <div class="desc-display <?php echo !$inc['description'] ? 'empty' : ''; ?>" id="desc-display">
            <?php echo $inc['description'] ? h($inc['description']) : 'No description yet. Click Edit to add one.'; ?>
          </div>
          <textarea class="desc-edit" id="desc-edit"><?php echo h($inc['description'] ?? ''); ?></textarea>
        </div>
      </div>

      <!-- Evidence -->
      <div class="inc-card">
        <div class="inc-card-header">
          <div class="inc-card-title">&#128270; Evidence <span id="ev-count" style="color:var(--text3);font-weight:400">(<?php echo count($evidence); ?>)</span></div>
          <button class="btn-sm" onclick="document.getElementById('add-ev-form').classList.toggle('open')">+ Add Evidence</button>
        </div>
        <div class="inc-card-body" style="display:flex;flex-direction:column;gap:12px">

          <!-- Add evidence form -->
          <div class="add-ev-form" id="add-ev-form">
            <div class="form-row">
              <div class="form-field">
                <label class="form-label">Type</label>
                <select class="form-select" id="ev-type">
                  <option value="note">Note</option>
                  <option value="ip">IP Address</option>
                  <option value="hash">File Hash</option>
                  <option value="cve">CVE</option>
                  <option value="url">URL</option>
                  <option value="domain">Domain</option>
                  <option value="scan">Scan</option>
                </select>
              </div>
              <div class="form-field">
                <label class="form-label">Title</label>
                <input type="text" class="form-input" id="ev-title" placeholder="Short label">
              </div>
            </div>
            <div class="form-field">
              <label class="form-label">Detail / Notes</label>
              <textarea class="form-textarea-sm" id="ev-detail" placeholder="Detailed information, raw output, notes..."></textarea>
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn-sm accent" onclick="addEvidence()">Add</button>
              <button class="btn-sm" onclick="document.getElementById('add-ev-form').classList.remove('open')">Cancel</button>
            </div>
          </div>

          <!-- Evidence list -->
          <div class="evidence-list" id="evidence-list">
            <?php if (empty($evidence)): ?>
            <div class="empty-state" id="ev-empty">No evidence added yet.</div>
            <?php else: ?>
            <?php foreach ($evidence as $ev): ?>
            <div class="ev-item" id="ev-<?php echo (int)$ev['id']; ?>">
              <div class="ev-icon"><?php echo ev_icon($ev['type']); ?></div>
              <div class="ev-body">
                <div class="ev-type-badge"><?php echo h($ev['type']); ?></div>
                <?php if ($ev['title']): ?>
                <div class="ev-title"><?php echo h($ev['title']); ?></div>
                <?php endif; ?>
                <?php if ($ev['detail']): ?>
                <div class="ev-detail"><?php echo h($ev['detail']); ?></div>
                <?php endif; ?>
                <div class="ev-time"><?php echo date('M j, Y H:i', strtotime($ev['added_at'])); ?></div>
              </div>
              <button class="btn-sm danger" onclick="deleteEvidence(<?php echo (int)$ev['id']; ?>)">&#10005;</button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Notes / Timeline -->
      <div class="inc-card">
        <div class="inc-card-header">
          <div class="inc-card-title">&#128172; Timeline / Notes <span style="color:var(--text3);font-weight:400">(<?php echo count($notes); ?>)</span></div>
        </div>
        <div class="inc-card-body" style="display:flex;flex-direction:column;gap:14px">
          <div class="notes-list" id="notes-list">
            <?php if (empty($notes)): ?>
            <div class="empty-state" id="notes-empty">No notes yet.</div>
            <?php else: ?>
            <?php foreach ($notes as $note): ?>
            <div class="note-item">
              <div class="note-text"><?php echo h($note['note']); ?></div>
              <div class="note-time"><?php echo date('M j, Y H:i', strtotime($note['created_at'])); ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="note-add-area">
            <textarea class="note-textarea" id="note-input" placeholder="Add a note or timeline entry..."></textarea>
            <div>
              <button class="btn-sm accent" onclick="addNote()">Add Note</button>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /.inc-view-main -->

    <!-- Sidebar -->
    <div class="inc-view-side">
      <div class="inc-card">
        <div class="inc-card-header">
          <div class="inc-card-title">&#9881; Properties</div>
        </div>
        <div class="inc-card-body" style="display:flex;flex-direction:column;gap:16px">

          <div>
            <div class="side-label">Status</div>
            <select class="status-select" id="status-select" onchange="updateStatus(this.value)">
              <?php foreach (['open', 'investigating', 'contained', 'resolved', 'closed'] as $s): ?>
              <option value="<?php echo $s; ?>" <?php echo $inc['status'] === $s ? 'selected' : ''; ?>>
                <?php echo ucfirst($s); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <div class="side-label">Severity</div>
            <div><span class="sev-badge sev-<?php echo h($inc['severity']); ?>"><?php echo h($inc['severity']); ?></span></div>
          </div>

          <div>
            <div class="side-label">Created</div>
            <div class="side-val"><?php echo date('M j, Y', strtotime($inc['created_at'])); ?></div>
            <div style="font-family:var(--mono);font-size:10px;color:var(--text3)"><?php echo date('H:i', strtotime($inc['created_at'])); ?></div>
          </div>

          <div>
            <div class="side-label">Last Updated</div>
            <div class="side-val"><?php echo date('M j, Y', strtotime($inc['updated_at'])); ?></div>
            <div style="font-family:var(--mono);font-size:10px;color:var(--text3)"><?php echo date('H:i', strtotime($inc['updated_at'])); ?></div>
          </div>

          <div>
            <div class="side-label">Evidence Items</div>
            <div class="side-val" id="side-ev-count"><?php echo count($evidence); ?></div>
          </div>

        </div>
      </div>

      <div class="inc-card">
        <div class="inc-card-header">
          <div class="inc-card-title">&#128279; Quick Links</div>
        </div>
        <div class="inc-card-body" style="display:flex;flex-direction:column;gap:8px">
          <a href="/tools/ip_check.php" class="btn-sm" style="text-decoration:none;display:block;text-align:center">&#127760; Check IP</a>
          <a href="/tools/hash_check.php" class="btn-sm" style="text-decoration:none;display:block;text-align:center">&#128273; Hash Lookup</a>
          <a href="/tools/cve_check.php" class="btn-sm" style="text-decoration:none;display:block;text-align:center">&#9888; CVE Lookup</a>
          <a href="/tools/domain.php" class="btn-sm" style="text-decoration:none;display:block;text-align:center">&#127760; Domain Intel</a>
        </div>
      </div>
    </div>

  </div><!-- /.inc-view-layout -->

</main>
</div>

<script>
var CSRF = <?php echo json_encode($csrf); ?>;
var INC_ID = <?php echo $inc_id; ?>;

function apiPost(data) {
  data.csrf = CSRF;
  var fd = new FormData();
  Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
  return fetch('/incidents/view.php?id=' + INC_ID, {method:'POST', body:fd}).then(function(r){ return r.json(); });
}

// Title edit
function toggleTitleEdit() {
  document.getElementById('inc-title-display').style.display = 'none';
  document.getElementById('inc-title-edit').style.display = 'block';
  document.getElementById('btn-edit-title').style.display = 'none';
  document.getElementById('btn-save-title').style.display = 'inline-flex';
  document.getElementById('btn-cancel-title').style.display = 'inline-flex';
  document.getElementById('inc-title-edit').focus();
}
function cancelTitleEdit() {
  document.getElementById('inc-title-display').style.display = '';
  document.getElementById('inc-title-edit').style.display = 'none';
  document.getElementById('btn-edit-title').style.display = '';
  document.getElementById('btn-save-title').style.display = 'none';
  document.getElementById('btn-cancel-title').style.display = 'none';
}
function saveTitle() {
  var title = document.getElementById('inc-title-edit').value.trim();
  if (!title) return;
  apiPost({action:'update_title', title:title}).then(function(d){
    if (d.ok) {
      document.getElementById('inc-title-display').textContent = title;
      cancelTitleEdit();
    } else { alert(d.error || 'Error'); }
  });
}

// Description edit
function toggleDescEdit() {
  document.getElementById('desc-display').style.display = 'none';
  document.getElementById('desc-edit').style.display = 'block';
  document.getElementById('btn-edit-desc').style.display = 'none';
  document.getElementById('btn-save-desc').style.display = 'inline-flex';
  document.getElementById('btn-cancel-desc').style.display = 'inline-flex';
  document.getElementById('desc-edit').focus();
}
function cancelDescEdit() {
  document.getElementById('desc-display').style.display = '';
  document.getElementById('desc-edit').style.display = 'none';
  document.getElementById('btn-edit-desc').style.display = '';
  document.getElementById('btn-save-desc').style.display = 'none';
  document.getElementById('btn-cancel-desc').style.display = 'none';
}
function saveDesc() {
  var desc = document.getElementById('desc-edit').value;
  apiPost({action:'update_description', description:desc}).then(function(d){
    if (d.ok) {
      var el = document.getElementById('desc-display');
      if (desc.trim()) { el.textContent = desc; el.classList.remove('empty'); }
      else { el.textContent = 'No description yet. Click Edit to add one.'; el.classList.add('empty'); }
      cancelDescEdit();
    } else { alert(d.error || 'Error'); }
  });
}

// Status update
function updateStatus(status) {
  apiPost({action:'update_status', status:status}).then(function(d){
    if (d.ok) {
      var badge = document.getElementById('status-badge-display');
      badge.textContent = status;
      badge.className = 'status-badge status-' + status;
    }
  });
}

// Add note
function addNote() {
  var note = document.getElementById('note-input').value.trim();
  if (!note) return;
  apiPost({action:'add_note', note:note}).then(function(d){
    if (d.ok) {
      var list = document.getElementById('notes-list');
      var empty = document.getElementById('notes-empty');
      if (empty) empty.remove();
      var div = document.createElement('div');
      div.className = 'note-item';
      var now = new Date();
      var timeStr = now.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) + ' ' + now.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', hour12:false});
      div.innerHTML = '<div class="note-text">' + escHtml(note) + '</div><div class="note-time">' + timeStr + '</div>';
      list.appendChild(div);
      document.getElementById('note-input').value = '';
    } else { alert(d.error || 'Error'); }
  });
}

// Add evidence
var EV_ICONS = {scan:'&#9632;', ip:'&#127760;', hash:'&#128273;', cve:'&#9888;', url:'&#128279;', domain:'&#127760;', note:'&#128203;'};
function addEvidence() {
  var type   = document.getElementById('ev-type').value;
  var title  = document.getElementById('ev-title').value.trim();
  var detail = document.getElementById('ev-detail').value.trim();
  if (!title && !detail) { alert('Please enter a title or detail.'); return; }
  apiPost({action:'add_evidence', type:type, title:title, detail:detail}).then(function(d){
    if (d.ok) {
      var list = document.getElementById('evidence-list');
      var empty = document.getElementById('ev-empty');
      if (empty) empty.remove();
      var div = document.createElement('div');
      div.className = 'ev-item';
      div.id = 'ev-' + d.id;
      div.innerHTML = '<div class="ev-icon">' + (EV_ICONS[type]||'&#128203;') + '</div>'
        + '<div class="ev-body">'
        + '<div class="ev-type-badge">' + escHtml(type) + '</div>'
        + (title ? '<div class="ev-title">' + escHtml(title) + '</div>' : '')
        + (detail ? '<div class="ev-detail">' + escHtml(detail) + '</div>' : '')
        + '<div class="ev-time">just now</div>'
        + '</div>'
        + '<button class="btn-sm danger" onclick="deleteEvidence(' + d.id + ')">&#10005;</button>';
      list.appendChild(div);
      document.getElementById('ev-title').value = '';
      document.getElementById('ev-detail').value = '';
      document.getElementById('add-ev-form').classList.remove('open');
      var count = document.querySelectorAll('.ev-item').length;
      document.getElementById('ev-count').textContent = '(' + count + ')';
      document.getElementById('side-ev-count').textContent = count;
    } else { alert(d.error || 'Error'); }
  });
}

// Delete evidence
function deleteEvidence(id) {
  if (!confirm('Remove this evidence item?')) return;
  apiPost({action:'delete_evidence', ev_id:id}).then(function(d){
    if (d.ok) {
      var el = document.getElementById('ev-' + id);
      if (el) el.remove();
      var count = document.querySelectorAll('.ev-item').length;
      document.getElementById('ev-count').textContent = '(' + count + ')';
      document.getElementById('side-ev-count').textContent = count;
      if (count === 0) {
        document.getElementById('evidence-list').innerHTML = '<div class="empty-state" id="ev-empty">No evidence added yet.</div>';
      }
    }
  });
}

// Delete incident
function deleteIncident() {
  if (!confirm('Delete this incident and all evidence/notes? This cannot be undone.')) return;
  apiPost({action:'delete'}).then(function(d){
    if (d.ok && d.redirect) window.location.href = d.redirect;
  });
}

function escHtml(s) {
  return (s+'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
