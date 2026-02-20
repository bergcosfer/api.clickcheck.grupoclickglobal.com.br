<?php
// Callback alternativo para Google OAuth
// Redireciona internamente para auth.php
$_GET['action'] = 'google-callback';
require_once __DIR__ . '/auth.php';
