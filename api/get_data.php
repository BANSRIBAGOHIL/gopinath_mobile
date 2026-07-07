<?php
/**
 * Gopinath_Mobile — Public JSON API
 * ----------------------------------
 * Called by client/js/script.js on page load. Reads the SAME database the
 * admin dashboard (admin/index.php) writes to, and returns everything the
 * client site needs to render itself.
 *
 * SETUP: change the DB_* constants below to match your local MySQL/MariaDB
 * (same values as in admin/index.php).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // same-site by default; loosen only if you host client/api separately

define('DB_HOST', 'localhost');
define('DB_NAME', 'gopinath_mobile');
define('DB_USER', 'root');
define('DB_PASS', '');

// Path from client/index.php to the images stored by the admin dashboard.
// Folder layout assumed: gopinath/admin , gopinath/client , gopinath/api
define('IMG_BASE', '../admin/uploads/');
define('PLACEHOLDER_IMG', 'https://placehold.co/600x400?text=Gopinath_Mobile');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}

function img($filename) {
    return $filename ? IMG_BASE . $filename : PLACEHOLDER_IMG;
}
function val($v, $default = '') {
    return $v !== null && $v !== '' ? $v : $default;
}

$settings = $pdo->query("SELECT * FROM website_settings WHERE id=1")->fetch() ?: [];
$hero     = $pdo->query("SELECT * FROM hero_sections WHERE id=1")->fetch() ?: [];
$about    = $pdo->query("SELECT * FROM about_us WHERE id=1")->fetch() ?: [];

$services = $pdo->query("SELECT icon, title, description FROM services WHERE status='active' ORDER BY id ASC")->fetchAll();
$products = $pdo->query("SELECT product_name, description, image FROM products WHERE status='active' ORDER BY id DESC")->fetchAll();
$accessories = $pdo->query("SELECT name, image FROM accessories WHERE status='active' ORDER BY id DESC")->fetchAll();
$gallery  = $pdo->query("SELECT title, image FROM gallery WHERE status='active' ORDER BY id DESC")->fetchAll();
$whyus    = $pdo->query("SELECT icon, title, description FROM why_choose_us WHERE status='active' ORDER BY id ASC")->fetchAll();

$out = [
    'topbar' => [
        'phone'    => val($settings['phone'] ?? ''),
        'email'    => val($settings['email'] ?? ''),
        'insta'    => val($settings['instagram_url'] ?? ''),
        'facebook' => val($settings['facebook_url'] ?? '#'),
        'linkedin' => val($settings['linkedin_url'] ?? '#'),
    ],
    'hero' => [
        'title'    => val($hero['title'] ?? '', 'Your Trusted Mobile Care Partner'),
        'subtitle' => val($hero['subtitle'] ?? ''),
    ],
    'about' => [
        'text' => val($about['content'] ?? ''),
    ],
    'services' => array_map(function ($s) {
        return ['icon' => val($s['icon'], 'bi-tools'), 'title' => $s['title'], 'desc' => val($s['description'])];
    }, $services),
    'products' => array_map(function ($p) {
        return ['img' => img($p['image']), 'name' => $p['product_name'], 'desc' => val($p['description'])];
    }, $products),
    'accessories' => array_map(function ($a) {
        return ['img' => img($a['image']), 'name' => $a['name']];
    }, $accessories),
    'gallery' => array_map(function ($g) {
        return ['img' => img($g['image']), 'caption' => val($g['title'])];
    }, $gallery),
    'whyus' => array_map(function ($w) {
        return ['icon' => val($w['icon'], 'bi-patch-check-fill'), 'title' => $w['title'], 'desc' => val($w['description'])];
    }, $whyus),
    'contact' => [
        'address'  => val($settings['address'] ?? ''),
        'phone'    => val($settings['phone'] ?? ''),
        'email'    => val($settings['email'] ?? ''),
        'mapEmbed' => val($settings['google_map_embed'] ?? ''),
    ],
];

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
