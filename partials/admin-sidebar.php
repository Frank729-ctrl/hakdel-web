<?php
// Required: $section — 'stats' | 'labs' | 'quiz' | 'categories' | 'users' | 'logs'
?>
<aside class="hk-sidebar">
  <div class="hk-sidebar-brand">
    <div class="brand-icon" style="background:rgba(255,77,109,0.08);border-color:rgba(255,77,109,0.2);color:var(--danger)">&#9650;</div>
    <div>
      <div class="brand-title" style="color:var(--danger)">Admin Panel</div>
      <div class="brand-sub">HakDel Control</div>
    </div>
  </div>
  <nav class="hk-nav">
    <div class="hk-nav-section">Stats</div>
    <a href="?section=stats"      class="hk-nav-item <?php echo $section === 'stats'      ? 'active' : ''; ?>"><span class="nav-icon">&#9632;</span> Dashboard</a>
    <div class="hk-nav-section">Content</div>
    <a href="?section=labs"       class="hk-nav-item <?php echo $section === 'labs'       ? 'active' : ''; ?>"><span class="nav-icon">&#9670;</span> Labs</a>
    <a href="?section=quiz"       class="hk-nav-item <?php echo $section === 'quiz'       ? 'active' : ''; ?>"><span class="nav-icon">&#9671;</span> Quiz Questions</a>
    <a href="?section=categories" class="hk-nav-item <?php echo $section === 'categories' ? 'active' : ''; ?>"><span class="nav-icon">&#9674;</span> Categories</a>
    <div class="hk-nav-section">Users</div>
    <a href="?section=users"      class="hk-nav-item <?php echo $section === 'users'      ? 'active' : ''; ?>"><span class="nav-icon">&#9898;</span> Users</a>
    <div class="hk-nav-section">Platform</div>
    <a href="/scanner/" class="hk-nav-item" target="_blank"><span class="nav-icon">&#8599;</span> View Site</a>
    <a href="?section=logs"       class="hk-nav-item <?php echo $section === 'logs'       ? 'active' : ''; ?>"><span class="nav-icon">&#9654;</span> Admin Logs</a>
    <a href="logout.php"          class="hk-nav-item" style="color:var(--danger)"><span class="nav-icon">&#10005;</span> Logout</a>
  </nav>
</aside>
