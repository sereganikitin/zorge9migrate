<?php
// /main/ serves a static snapshot of the legacy Wolf CMS homepage that used to
// live at /. Since the old site has two completely separate templates for
// desktop and mobile (assets/* vs assets/m/*), we keep two snapshots here
// and switch on the User-Agent the same way the old CMS did.
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = (bool) preg_match(
    '/Mobile|Android|iPhone|iPad|iPod|BlackBerry|Opera Mini|IEMobile|Windows Phone/i',
    $ua
);
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/' . ($isMobile ? 'mobile.html' : 'desktop.html'));
