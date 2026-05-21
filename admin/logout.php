<?php
require_once __DIR__ . '/../config/app.php';
admin_logout();
redirect('/admin/index.php');