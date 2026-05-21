<?php
// Required variables (set by the including page before requiring this file):
//   $nav_active (string) — active key for current page

$sidebar_footer ??= null;

// Fetch unread notification count
$notif_count = 0;
try {
    $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $s->execute([$user['id']]);
    $notif_count = (int)$s->fetchColumn();
} catch(Exception $e) {}
$notif_badge_html = $notif_count > 0 ? ' <span class="nav-badge">' . $notif_count . '</span>' : '';

function _nav_item(string $href, string $icon, string $label, string $active_key, string $nav_active, string $extra = ''): string {
    $active = $nav_active === $active_key ? ' active' : '';
    return '<a href="' . $href . '" class="hk-nav-item' . $active . '" title="' . htmlspecialchars($label) . '">'
         . '<span class="nav-icon">' . $icon . '</span>'
         . '<span class="nav-label">' . $label . $extra . '</span>'
         . '</a>';
}
?>
<aside class="hk-sidebar">
  <div class="hk-sidebar-brand">
    <div class="brand-icon">&#9650;</div>
    <div>
      <div class="brand-title">HAK<span style="color:var(--accent2)">DEL</span></div>
      <div class="brand-sub">Command Center</div>
    </div>
  </div>

  <nav class="hk-nav">
    <?php echo _nav_item('/dashboard/', '&#9635;', 'Dashboard', 'dashboard', $nav_active); ?>

    <div class="hk-nav-section">Scanner</div>
    <?php echo _nav_item('/scanner/',             '&#9632;', 'Scanner',   'scanner',  $nav_active); ?>
    <?php echo _nav_item('/scanner/history.php',  '&#9783;', 'History',   'history',  $nav_active); ?>
    <?php echo _nav_item('/scanner/schedule.php', '&#9200;', 'Schedules', 'schedule', $nav_active); ?>

    <div class="hk-nav-section">Workspace</div>
    <?php echo _nav_item('/incidents/',     '&#128203;', 'Incidents',     'incidents',     $nav_active); ?>
    <?php echo _nav_item('/notifications/', '&#128276;', 'Notifications', 'notifications', $nav_active, $notif_badge_html); ?>
    <?php echo _nav_item('/settings/',      '&#9881;',   'Settings',      'settings',      $nav_active); ?>

    <?php
    // Check trial state
    $_sidebar_trial = false;
    $_sidebar_trial_days = 0;
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
    ?>
    <?php if ($_sidebar_trial): ?>
    <div style="padding: 10px 10px 4px;">
      <a href="/upgrade/" class="hk-nav-upgrade" style="border-color:rgba(255,170,0,0.3);background:rgba(255,170,0,0.06);color:#ffaa00;">
        <span style="font-size:12px">&#9651;</span>
        <span>Trial: <?= $_sidebar_trial_days ?>d left</span>
      </a>
    </div>
    <?php elseif (!is_pro($user)): ?>
    <div style="padding: 10px 10px 4px;">
      <a href="/upgrade/" class="hk-nav-upgrade">
        <span style="font-size:13px">&#9651;</span>
        <span>Upgrade to Pro</span>
      </a>
    </div>
    <?php endif; ?>
  </nav>

  <div class="hk-sidebar-user">
    <a href="/profile/" class="hk-user-info <?php echo $nav_active === 'profile' ? 'active' : ''; ?>">
      <div class="hk-user-avatar"><?php echo htmlspecialchars($initials); ?></div>
      <div class="hk-user-meta">
        <div class="hk-user-name"><?php echo htmlspecialchars($user['username']); ?></div>
        <div class="hk-user-role">LVL <?php echo $level; ?> &middot; <?php echo $user['xp']; ?> XP</div>
      </div>
    </a>
    <a href="/auth/logout.php" class="hk-user-logout" title="Logout">&#8594;</a>
  </div>
</aside>
