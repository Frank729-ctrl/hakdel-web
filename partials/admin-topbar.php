<header class="hk-topbar">
  <div class="hk-topbar-left">
    <span class="logo-dot" style="background:var(--danger);box-shadow:0 0 8px var(--danger)"></span>
    <span class="logo-text">HAK<span class="logo-accent">DEL</span> <span style="color:var(--danger);font-size:11px;letter-spacing:2px">ADMIN</span></span>
  </div>
  <div class="hk-topbar-right">
    <span style="font-family:var(--mono);font-size:12px;color:var(--text2)"><?php echo h($admin['username']); ?></span>
    <a href="logout.php" style="font-family:var(--mono);font-size:11px;color:var(--danger);text-decoration:none;">Logout</a>
  </div>
</header>
