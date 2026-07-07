<?php
/**
 * Gopinath_Mobile — Contact Form Handler
 * Saves enquiries from the client site's Contact Us form into `contact_messages`.
 */

header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'localhost');
define('DB_NAME', 'gopinath_mobile');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// contact_messages (from the SQL dump) has no `email` column — add it once, safely.
try {
    $pdo->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL AFTER mobile");
} catch (PDOException $e) { /* ignore if unsupported / already exists */ }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$name    = trim($_POST['name'] ?? '');
$mobile  = trim($_POST['mobile'] ?? '');
$email   = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $mobile === '' || $message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please fill in all required fields.']);
    exit;
}

$stmt = $pdo->prepare("INSERT INTO contact_messages (name, mobile, email, message) VALUES (?,?,?,?)");
$stmt->execute([$name, $mobile, $email, $message]);

echo json_encode(['ok' => true]);
