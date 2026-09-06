<?php
require_once __DIR__ . '/includes/auth.php';

logout();
set_flash('success', t('auth.signed_out'));
redirect('login.php');
