<?php
// Required: $nav_active (string), $user (array), $level (int), $xp_data (array)

$notif_count = 0;
try {
    $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $s->execute([$user['id']]);
    $notif_count = (int)$s->fetchColumn();
} catch(Exception $e) {}

// Trial check
$_sidebar_trial = false; $_sidebar_trial_days = 0;
if (is_pro($user) && !empty($user['plan_expires_at'])) {
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = ?');
        $s->execute([$user['id'], 'success']);
        if (!(int)$s->fetchColumn()) {
            $_sidebar_trial = true;
            $_sidebar_trial_days = max(0, (int)ceil((strtotime($user['plan_expires_at']) - time()) / 86400));
        }
    } catch (Exception $e) {}
}

$nav = [
    'OPERATIONS' => [
        ['href'=>'/dashboard/',           'id'=>'dashboard',     'icon'=>'▣', 'label'=>'Briefing'],
        ['href'=>'/scanner/',             'id'=>'scanner',       'icon'=>'◈', 'label'=>'Surveillance'],
        ['href'=>'/tools/watchlist.php',  'id'=>'watchlist',     'icon'=>'◉', 'label'=>'Watchlist'],
    ],
    'TRAINING' => [
        ['href'=>'/labs/',        'id'=>'labs',        'icon'=>'⌬', 'label'=>'Field Range'],
        ['href'=>'/quiz/',        'id'=>'quiz',        'icon'=>'⌖', 'label'=>'Drills'],
        ['href'=>'/leaderboard/', 'id'=>'leaderboard', 'icon'=>'♛', 'label'=>'Ranks'],
    ],
    'AUXILIARY' => [
        ['href'=>'/tools/',        'id'=>'tools',    'icon'=>'⌗', 'label'=>'Toolkit'],
        ['href'=>'/profile/',      'id'=>'profile',  'icon'=>'⌥', 'label'=>'Dossier'],
        ['href'=>'/settings/',     'id'=>'settings', 'icon'=>'⚙', 'label'=>'Config'],
    ],
];
?>
<aside class="hk-sidebar">
  <div class="hk-sidebar-brand">
    <div class="brand-mark">HAK<span class="accent">DEL</span><span class="dot"></span></div>
    <div class="brand-meta">v2.7.0 · BRIEFING</div>
  </div>

  <nav class="hk-nav">
    <?php foreach ($nav as $group => $links): ?>
    <div class="nav-grp">
      <div class="nav-grp-h"><?= $group ?></div>
      <?php foreach ($links as $link):
        $active = ($nav_active === $link['id']) || ($nav_active === 'history' && $link['id'] === 'scanner') || ($nav_active === 'schedule' && $link['id'] === 'scanner');
        $badge = ($link['id'] === 'watchlist' && $notif_count > 0) ? $notif_count : null;
      ?>
      <a href="<?= $link['href'] ?>" class="nav-item<?= $active ? ' active' : '' ?>">
        <span class="ic"><?= $link['icon'] ?></span>
        <span><?= $link['label'] ?></span>
        <?php if ($badge): ?>
          <span class="badge"><?= $badge ?></span>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($_sidebar_trial): ?>
    <div style="padding:8px 14px">
      <a href="/upgrade/" class="hk-nav-upgrade" style="border-color:var(--amber-dim);background:rgba(244,163,34,.08);color:var(--amber)">
        ⚡ TRIAL: <?= $_sidebar_trial_days ?>D LEFT
      </a>
    </div>
    <?php elseif (!is_pro($user)): ?>
    <div style="padding:8px 14px">
      <a href="/upgrade/" class="hk-nav-upgrade">⚡ UPGRADE TO PRO</a>
    </div>
    <?php endif; ?>
  </nav>

  <div class="hk-sidebar-user">
    <div class="id-card">
      <div class="row">
        <span class="l">OPERATOR</span>
        <span class="v"><?= htmlspecialchars($user['username']) ?></span>
      </div>
      <div class="row">
        <span class="l">CLEARANCE</span>
        <span class="v signal">LVL <?= $level ?></span>
      </div>
      <div class="row">
        <span class="l">XP</span>
        <span class="v"><?= number_format((int)$user['xp']) ?> / <?= number_format($xp_data['next_level_xp'] ?? 0) ?></span>
      </div>
      <div class="row">
        <span class="l">UTC</span>
        <span class="v tnum" id="sidebar-utc">--:--:--</span>
      </div>
    </div>
    <a href="/auth/logout.php" class="sidebar-logout">
      <span>SIGN OUT</span>
      <span>→</span>
    </a>
  </div>
</aside>
<script>
(function(){
  function pad(n){ return String(n).padStart(2,'0'); }
  function tick(){
    var d = new Date();
    var el = document.getElementById('sidebar-utc');
    if(el) el.textContent = pad(d.getUTCHours())+':'+pad(d.getUTCMinutes())+':'+pad(d.getUTCSeconds());
  }
  tick(); setInterval(tick, 1000);
  // Mobile sidebar toggle
  var tog = document.getElementById('hk-menu-toggle');
  var sb  = document.querySelector('.hk-sidebar');
  if(tog && sb){
    tog.addEventListener('click', function(){
      sb.classList.toggle('hk-sidebar--open');
      tog.classList.toggle('active');
    });
  }
})();
</script>
