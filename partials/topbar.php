<?php
// Consume any pending XP notification (set by submit.php / login.php / etc.)
$_xp_notify = null;
if (!empty($_SESSION['pending_xp_notify'])) {
    $_xp_notify = $_SESSION['pending_xp_notify'];
    unset($_SESSION['pending_xp_notify']);
}
// Optional: $topbar_title — page title shown in centre of topbar
?>
<header class="hk-topbar">
  <div class="hk-topbar-left">
    <button class="hk-menu-toggle" id="hk-menu-toggle" aria-label="Toggle menu">
      <span></span><span></span><span></span>
    </button>
    <span class="logo-text hk-topbar-logo">HAK<span class="logo-accent">DEL</span></span>

    <!-- Nav dropdowns -->
    <nav class="hk-topnav">

      <div class="hk-topnav-item" id="topnav-tools">
        <button class="hk-topnav-btn">Tools <span class="hk-topnav-caret">&#9660;</span></button>
        <div class="hk-topnav-dropdown">
          <div class="hk-topnav-section">Security Tools</div>
          <a href="/tools/ip_check.php"   class="hk-topnav-link">&#127760; IP Checker</a>
          <a href="/tools/hash_check.php" class="hk-topnav-link">&#128273; Hash Lookup</a>
          <a href="/tools/cve_check.php"  class="hk-topnav-link">&#9888; CVE Lookup</a>
          <a href="/tools/watchlist.php"  class="hk-topnav-link">&#128204; Watchlist</a>
          <a href="/tools/port_scan.php"  class="hk-topnav-link">&#128268; Port Scanner</a>
          <a href="/tools/network.php"    class="hk-topnav-link">&#127756; Network Tools</a>
          <div class="hk-topnav-divider"></div>
          <div class="hk-topnav-section">OSINT</div>
          <a href="/tools/domain.php"      class="hk-topnav-link">&#127760; Domain Intel</a>
          <a href="/tools/headers.php"     class="hk-topnav-link">&#128737; Headers Analyser</a>
          <a href="/tools/url_check.php"   class="hk-topnav-link">&#128279; URL / Phishing</a>
          <a href="/tools/email_check.php" class="hk-topnav-link">&#9993; Email Investigator</a>
          <div class="hk-topnav-divider"></div>
          <a href="/tools/" class="hk-topnav-link hk-topnav-link--all">All Tools &rarr;</a>
        </div>
      </div>

      <div class="hk-topnav-item" id="topnav-learn">
        <button class="hk-topnav-btn">Learn <span class="hk-topnav-caret">&#9660;</span></button>
        <div class="hk-topnav-dropdown">
          <a href="/labs/"        class="hk-topnav-link">&#9878; Labs</a>
          <a href="/quiz/"        class="hk-topnav-link">&#10067; Quiz</a>
          <a href="/leaderboard/" class="hk-topnav-link">&#127942; Leaderboard</a>
        </div>
      </div>

    </nav>

    <?php if (!empty($topbar_title)): ?>
    <span class="hk-topbar-page"><?php echo htmlspecialchars($topbar_title); ?></span>
    <?php endif; ?>
  </div>

  <div class="hk-topbar-center"></div>

  <div class="hk-topbar-right">
    <div class="xp-bar-wrap">
      <span class="xp-label" id="topbar-xp-label">LVL <?php echo $level; ?> &nbsp;&middot;&nbsp; <span id="topbar-xp-num"><?php echo $user['xp']; ?></span> XP</span>
      <div class="xp-track"><div class="xp-fill" id="topbar-xp-fill" style="width:<?php echo $xp_data['progress']; ?>%"></div></div>
    </div>

    <div class="hk-notif-wrap">
      <button class="hk-notif-btn" id="hk-notif-btn" aria-label="Notifications">
        <span id="notif-icon">&#128276;</span>
        <span class="hk-notif-badge" id="hk-notif-badge" style="display:none">0</span>
      </button>
      <div class="hk-notif-dropdown" id="hk-notif-dropdown">
        <div class="hk-notif-header">
          <span>Notifications</span>
          <button onclick="markAllRead()" class="hk-notif-mark-all">Mark all read</button>
        </div>
        <div id="hk-notif-list"><div class="hk-notif-empty">No notifications</div></div>
      </div>
    </div>

    <!-- Avatar + dropdown -->
    <div class="hk-topbar-user">
      <button class="hk-avatar" id="hk-avatar-btn" aria-label="User menu">
        <?php echo htmlspecialchars($initials); ?>
      </button>
      <div class="hk-user-dropdown" id="hk-user-dropdown">
        <div class="hk-dropdown-header">
          <div class="hk-dropdown-name"><?php echo htmlspecialchars($user['username']); ?></div>
          <div class="hk-dropdown-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
        </div>
        <a href="/profile/" class="hk-dropdown-item">&#9898; Profile</a>
        <a href="/about/" class="hk-dropdown-item">&#9633; About</a>
        <a href="/legal/terms.php" class="hk-dropdown-item" style="font-size:12px;color:var(--text3)">Terms &amp; Privacy</a>
        <?php if (!is_pro($user)): ?>
        <div class="hk-dropdown-divider"></div>
        <a href="/upgrade/" class="hk-dropdown-item" style="color:var(--accent);font-weight:700">&#9651; Upgrade to Pro</a>
        <?php endif; ?>
        <div class="hk-dropdown-divider"></div>
        <a href="/auth/logout.php" class="hk-dropdown-item hk-dropdown-logout">&#8594; Logout</a>
      </div>
    </div>
  </div>
</header>
<?php if ($_xp_notify): ?>
<script>window.PENDING_XP_NOTIFY = <?php echo json_encode($_xp_notify, JSON_UNESCAPED_UNICODE); ?>;</script>
<?php endif; ?>
<script src="/assets/xp_notify.js" defer></script>
<script>
// Notifications
(function(){
  var notifBtn = document.getElementById('hk-notif-btn');
  var notifDD  = document.getElementById('hk-notif-dropdown');
  var notifBadge = document.getElementById('hk-notif-badge');
  var notifList  = document.getElementById('hk-notif-list');

  function timeAgo(dtStr) {
    var d = new Date(dtStr.replace(' ', 'T') + 'Z');
    var diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
  }

  function loadNotifs() {
    fetch('/api/notifications.php')
      .then(function(r){ return r.json(); })
      .then(function(data) {
        var count = data.count || 0;
        if (count > 0) {
          notifBadge.textContent = count > 99 ? '99+' : count;
          notifBadge.style.display = 'flex';
        } else {
          notifBadge.style.display = 'none';
        }
        if (!data.items || data.items.length === 0) {
          notifList.innerHTML = '<div class="hk-notif-empty">No notifications</div>';
          return;
        }
        var html = '';
        data.items.forEach(function(n) {
          var unread = !parseInt(n.is_read);
          html += '<div class="hk-notif-item' + (unread ? ' unread' : '') + '" onclick="notifClick('+n.id+',\'' + (n.link||'').replace(/'/g,"\\'") + '\')">'
               + '<div class="hk-notif-dot' + (unread ? '' : ' read') + '"></div>'
               + '<div style="flex:1;min-width:0">'
               + '<div class="hk-notif-title">' + (n.title||'').replace(/</g,'&lt;') + '</div>'
               + (n.message ? '<div class="hk-notif-msg">' + (n.message||'').replace(/</g,'&lt;').substring(0,80) + '</div>' : '')
               + '</div>'
               + '<div class="hk-notif-time">' + timeAgo(n.created_at) + '</div>'
               + '</div>';
        });
        html += '<div style="padding:8px 14px;text-align:center;border-top:1px solid var(--border)">'
              + '<a href="/notifications/" style="font-size:11px;color:var(--accent);font-family:var(--mono)">View all</a></div>';
        notifList.innerHTML = html;
      }).catch(function(){});
  }

  window.notifClick = function(id, link) {
    var fd = new FormData();
    fd.append('action', 'mark_read');
    fd.append('id', id);
    fetch('/api/notifications.php', {method:'POST', body:fd}).finally(function(){
      if (link) window.location.href = link;
      else loadNotifs();
    });
  };

  window.markAllRead = function() {
    var fd = new FormData();
    fd.append('action', 'mark_all_read');
    fetch('/api/notifications.php', {method:'POST', body:fd}).then(function(){ loadNotifs(); });
    notifDD.classList.remove('open');
  };

  if (notifBtn && notifDD) {
    notifBtn.addEventListener('click', function(e){
      e.stopPropagation();
      notifDD.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
      if (!notifDD.contains(e.target) && e.target !== notifBtn) notifDD.classList.remove('open');
    });
  }

  loadNotifs();
  setInterval(loadNotifs, 60000);
})();

// Avatar dropdown toggle
(function(){
  var btn = document.getElementById('hk-avatar-btn');
  var dd  = document.getElementById('hk-user-dropdown');
  if (!btn || !dd) return;
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    dd.classList.toggle('open');
  });
  document.addEventListener('click', function(){ dd.classList.remove('open'); });

  // Mobile sidebar toggle
  var tog = document.getElementById('hk-menu-toggle');
  var sb  = document.querySelector('.hk-sidebar');
  if (tog && sb) {
    tog.addEventListener('click', function(){
      sb.classList.toggle('hk-sidebar--open');
      tog.classList.toggle('active');
    });
  }
})();

// Topnav dropdowns
(function(){
  var items = document.querySelectorAll('.hk-topnav-item');
  items.forEach(function(item) {
    var btn = item.querySelector('.hk-topnav-btn');
    if (!btn) return;
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      var wasOpen = item.classList.contains('open');
      // close all
      items.forEach(function(i){ i.classList.remove('open'); });
      if (!wasOpen) item.classList.add('open');
    });
  });
  document.addEventListener('click', function(){
    items.forEach(function(i){ i.classList.remove('open'); });
  });
})();
</script>
