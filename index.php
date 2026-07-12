<?php
/**
 * index.php — broker landing page. Lists the apps this broker can sign in.
 * Also the place a curious user lands if they visit oauth.wosa.link directly.
 */
require __DIR__ . '/common.php';

$apps = array();
foreach (glob($GLOBALS['APPS_PATH'] . '/*/config.php') as $file) {
    $name = basename(dirname($file));
    if ($name === '_example') {
        continue;
    }
    $cfg = include $file;
    if (is_array($cfg)) {
        $apps[$name] = isset($cfg['title']) ? $cfg['title'] : $name;
    }
}

$list = '';
if ($apps) {
    $list .= '<p>This helper signs legacy webOS apps into modern services. '
           . 'Open it from the link your app shows you, or pick your app:</p><ul>';
    foreach ($apps as $name => $title) {
        $list .= '<li><a href="' . htmlspecialchars(activateUrl($name)) . '">'
               . htmlspecialchars($title) . '</a></li>';
    }
    $list .= '</ul>';
} else {
    $list = '<p>No apps are configured yet.</p>';
}
$list .= '<p><small><a href="https://github.com/webosarchive">webOS Archive</a> · '
       . 'open-source OAuth bridge for webOS</small></p>';

renderPage('webOS OAuth Helper', $list, null);
