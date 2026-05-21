<?php
require_once __DIR__ . '/config/app.php';
if (current_user()) {
    redirect('/dashboard/');
} else {
    redirect('/auth/login.php');
}
