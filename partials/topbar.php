<?php
$_xp_notify = null;
if (!empty($_SESSION['pending_xp_notify'])) {
    $_xp_notify = $_SESSION['pending_xp_notify'];
    unset($_SESSION['pending_xp_notify']);
}
$topbar_title ??= 'Briefing';

// Breadcrumb map
$_breadcrumb_map = [
    'dashboard'   => 'Daily Briefing',
    'scanner'     => 'Active Surveillance',
    'history'     => 'Surveillance / History',
    'schedule'    => 'Surveillance / Schedules',
    'labs'        => 'Field Range',
    'lab'         => 'Field Range',
    'quiz'        => 'Drills',
    'leaderboard' => 'Ranks',
    'profile'     => 'Operator Dossier',
    'settings'    => 'Configuration',
    'watchlist'   => 'Watchlist',
    'tools'       => 'Toolkit',
    'incidents'   => 'Incidents',
    'notifications'=> 'Alerts',
    'about'       => 'About',
];
$_crumb = $_breadcrumb_map[$nav_active ?? ''] ?? htmlspecialchars($topbar_title);

// Live stats
$_scan_count = 0; $_findings_open = 0;
try {
    $s = db()->prepare("SELECT COUNT(*) FROM scans WHERE user_id = ? AND scanned_at >= NOW() - INTERVAL '24 hours'");
    $s->execute([$user['id']]);
    $_scan_count = (int)$s->fetchColumn();

    $s = db()->prepare("SELECT COUNT(*) FROM scan_findings sf JOIN scans sc ON sf.scan_id=sc.id WHERE sc.user_id=? AND sf.status='fail'");
    $s->execute([$user['id']]);
    $_findings_open = (int)$s->fetchColumn();
} catch(Exception $e) {}
?>
<header class="hk-topbar">
  <button class="hk-menu-toggle" id="hk-menu-toggle" aria-label="Toggle menu">
    <span></span><span></span><span></span>
  </button>

  <div class="hk-topbar-left">
    <div class="hk-topbar-crumbs">
      <span class="hk-topbar-logo">HAKDEL</span>
      <span style="color:var(--ink-ghost)">/</span>
      <span class="cur"><?= $_crumb ?></span>
    </div>
  </div>

  <div class="hk-topbar-right">
    <div class="hk-meta-tile">
      <span class="l">SCANS / 24H</span>
      <span class="v signal tnum"><?= $_scan_count ?></span>
    </div>
    <div class="hk-meta-tile">
      <span class="l">FINDINGS OPEN</span>
      <span class="v tnum"><?= $_findings_open ?></span>
    </div>
    <div class="hk-meta-tile">
      <span class="l">XP</span>
      <span class="v signal tnum"><?= number_format((int)$user['xp']) ?> <span style="color:var(--ink-faint);font-size:10px">/ <?= number_format($xp_data['next_level_xp'] ?? 0) ?></span></span>
    </div>

    <div class="hk-notif-wrap">
      <button class="hk-notif-btn" id="hk-notif-btn" aria-label="Notifications">
        ⏻
        <span class="hk-notif-badge" id="hk-notif-badge" style="display:none">0</span>
      </button>
      <div class="hk-notif-dropdown" id="hk-notif-dropdown">
        <div class="hk-notif-header">
          <span>ALERTS</span>
          <button onclick="markAllRead()" class="hk-notif-mark-all">MARK ALL READ</button>
        </div>
        <div id="hk-notif-list"><div class="hk-notif-empty">NO ALERTS</div></div>
      </div>
    </div>

    <a href="/scanner/" class="hk-icon-btn" title="New Scan">⌕</a>

    <div class="hk-topbar-user">
      <button class="hk-avatar" id="hk-avatar-btn" aria-label="User menu">
        <?= htmlspecialchars($initials) ?>
      </button>
      <div class="hk-user-dropdown" id="hk-user-dropdown">
        <div class="hk-dropdown-header">
          <div class="hk-dropdown-name"><?= htmlspecialchars($user['username']) ?></div>
          <div class="hk-dropdown-email"><?= htmlspecialchars($user['email'] ?? '') ?></div>
        </div>
        <a href="/profile/"    class="hk-dropdown-item">⌥ Dossier</a>
        <a href="/settings/"   class="hk-dropdown-item">⚙ Config</a>
        <a href="/about/"      class="hk-dropdown-item">◈ About</a>
        <?php if (!is_pro($user)): ?>
        <div class="hk-dropdown-divider"></div>
        <a href="/upgrade/" class="hk-dropdown-item" style="color:var(--signal)">⚡ Upgrade to Pro</a>
        <?php endif; ?>
        <div class="hk-dropdown-divider"></div>
        <a href="/auth/logout.php" class="hk-dropdown-item hk-dropdown-logout">→ Sign Out</a>
      </div>
    </div>
  </div>
</header>

<?php if ($_xp_notify): ?>
<script>window.PENDING_XP_NOTIFY = <?= json_encode($_xp_notify, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<script src="/assets/xp_notify.js" defer></script>
<script>
// Avatar dropdown
(function(){
  var btn = document.getElementById('hk-avatar-btn');
  var dd  = document.getElementById('hk-user-dropdown');
  if(!btn||!dd) return;
  btn.addEventListener('click', function(e){ e.stopPropagation(); dd.classList.toggle('open'); });
  document.addEventListener('click', function(){ dd.classList.remove('open'); });
})();

// Notifications
(function(){
  var btn   = document.getElementById('hk-notif-btn');
  var dd    = document.getElementById('hk-notif-dropdown');
  var badge = document.getElementById('hk-notif-badge');
  var list  = document.getElementById('hk-notif-list');

  function timeAgo(dtStr){
    var d = new Date(dtStr.replace(' ','T')+'Z');
    var diff = Math.floor((Date.now()-d.getTime())/1000);
    if(diff<60) return 'JUST NOW';
    if(diff<3600) return Math.floor(diff/60)+'M AGO';
    if(diff<86400) return Math.floor(diff/3600)+'H AGO';
    return Math.floor(diff/86400)+'D AGO';
  }

  function loadNotifs(){
    fetch('/api/notifications.php').then(function(r){ return r.json(); }).then(function(data){
      var count = data.count||0;
      if(count>0){ badge.textContent=count>99?'99+':count; badge.style.display='flex'; }
      else { badge.style.display='none'; }
      if(!data.items||!data.items.length){ list.innerHTML='<div class="hk-notif-empty">NO ALERTS</div>'; return; }
      var html='';
      data.items.forEach(function(n){
        var unread=!parseInt(n.is_read);
        html+='<div class="hk-notif-item'+(unread?' unread':'')+'" onclick="notifClick('+n.id+',\''+(n.link||'').replace(/'/g,"\\'")+'\''+')">'
          +'<div class="hk-notif-dot'+(unread?'':' read')+'"></div>'
          +'<div style="flex:1;min-width:0">'
          +'<div class="hk-notif-title">'+(n.title||'').replace(/</g,'&lt;')+'</div>'
          +(n.message?'<div class="hk-notif-msg">'+(n.message||'').replace(/</g,'&lt;').substring(0,80)+'</div>':'')
          +'</div>'
          +'<div class="hk-notif-time">'+timeAgo(n.created_at)+'</div>'
          +'</div>';
      });
      html+='<div style="padding:8px 14px;text-align:center;border-top:1px solid var(--line)">'
        +'<a href="/notifications/" style="font-size:10px;color:var(--signal);letter-spacing:.1em">VIEW ALL →</a></div>';
      list.innerHTML=html;
    }).catch(function(){});
  }

  window.notifClick = function(id, link){
    var fd = new FormData(); fd.append('action','mark_read'); fd.append('id',id);
    fetch('/api/notifications.php',{method:'POST',body:fd}).finally(function(){
      if(link) window.location.href=link; else loadNotifs();
    });
  };
  window.markAllRead = function(){
    var fd=new FormData(); fd.append('action','mark_all_read');
    fetch('/api/notifications.php',{method:'POST',body:fd}).then(function(){ loadNotifs(); });
    dd.classList.remove('open');
  };

  if(btn&&dd){
    btn.addEventListener('click', function(e){ e.stopPropagation(); dd.classList.toggle('open'); });
    document.addEventListener('click', function(e){ if(!dd.contains(e.target)&&e.target!==btn) dd.classList.remove('open'); });
  }
  loadNotifs(); setInterval(loadNotifs, 60000);
})();
</script>
