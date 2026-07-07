<?php
/**
 * Gopinath_Mobile — Admin Dashboard (single file)
 * ------------------------------------------------
 * Handles: auth, CSRF protection, prepared-statement DB access,
 * image upload validation, and full CRUD for every section in the sidebar.
 *
 * SETUP: change the DB_* constants below to match your local MySQL/MariaDB.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0'); // keep off in production; DB errors are shown via flash messages instead

/* ============================================================
   1) DATABASE CONFIG  (edit these 4 lines only)
   ============================================================ */
define('DB_HOST', 'localhost');
define('DB_NAME', 'gopinath_mobile');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed. Please check DB_HOST / DB_NAME / DB_USER / DB_PASS at the top of index.php.');
}

/* Make sure a table exists for "Why Choose Us" — the SQL dump you sent has
   no table for this sidebar section, so it is created automatically here
   the first time the file runs (safe / idempotent). */
$pdo->exec("CREATE TABLE IF NOT EXISTS `why_choose_us` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `icon` VARCHAR(100) DEFAULT NULL,
    `title` VARCHAR(255) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('active','inactive') DEFAULT 'active',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Make sure website_settings / hero_sections / about_us have exactly one row
   to work with (id = 1), so forms always have something to load/save. */
$pdo->exec("INSERT INTO website_settings (id) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM website_settings WHERE id = 1)");
$pdo->exec("INSERT INTO hero_sections (id) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM hero_sections WHERE id = 1)");
$pdo->exec("INSERT INTO about_us (id) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM about_us WHERE id = 1)");

/* ============================================================
   2) SECURITY HELPERS
   ============================================================ */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_check() {
    if (!isset($_POST['csrf']) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        flash('error', 'Security check failed. Please try again.');
        redirect('index.php');
    }
}
function is_logged_in() {
    return !empty($_SESSION['admin_id']);
}
function require_login() {
    if (!is_logged_in()) {
        redirect('index.php');
    }
}
function flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function redirect($url) {
    header('Location: ' . $url);
    exit;
}
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/* Simple login throttling (per session) to slow down brute force. */
if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['login_lock_until'])) $_SESSION['login_lock_until'] = 0;

/* ============================================================
   3) IMAGE UPLOAD HELPER
   ============================================================ */
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

/**
 * Validates & stores an uploaded image. Returns new filename, or false if
 * no file was submitted (field left empty = keep whatever old value existed),
 * or null on validation failure (bad type / too large).
 */
function handle_upload($field) {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return false; // nothing uploaded, caller should keep old image
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp  = $_FILES[$field]['tmp_name'];
    $size = $_FILES[$field]['size'];

    if ($size > 3 * 1024 * 1024) { // 3MB limit
        flash('error', 'Image too large. Max 3MB allowed.');
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmp);
    finfo_close($finfo);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        flash('error', 'Invalid image type. Only JPG, PNG, WEBP, GIF allowed.');
        return null;
    }

    $ext      = $allowed[$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!move_uploaded_file($tmp, UPLOAD_DIR . $filename)) {
        flash('error', 'Failed to save uploaded image.');
        return null;
    }
    return $filename;
}
function delete_old_image($filename) {
    if ($filename && is_file(UPLOAD_DIR . $filename)) {
        @unlink(UPLOAD_DIR . $filename);
    }
}

/* ============================================================
   4) HANDLE ALL POST ACTIONS (before any HTML is echoed)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['form_action'] ?? '';

    /* ---------- LOGIN (does not require prior login) ---------- */
    if ($action === 'login') {
        csrf_check();

        if (time() < $_SESSION['login_lock_until']) {
            flash('error', 'Too many attempts. Please wait a minute and try again.');
            redirect('index.php');
        }

        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([$u]);
        $admin = $stmt->fetch();

        $ok = false;
        if ($admin) {
            if (password_verify($p, $admin['password'])) {
                $ok = true;
            } elseif (hash_equals((string)$admin['password'], $p)) {
                // Legacy plain-text password from the SQL dump — accept once,
                // then transparently upgrade it to a proper bcrypt hash.
                $ok = true;
                $newHash = password_hash($p, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
                $upd->execute([$newHash, $admin['id']]);
            }
        }

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_user'] = $admin['username'];
            $_SESSION['login_attempts'] = 0;
            redirect('index.php');
        } else {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_lock_until'] = time() + 60;
                $_SESSION['login_attempts'] = 0;
            }
            flash('error', 'Invalid username or password.');
            redirect('index.php');
        }
    }

    /* ---------- everything below requires login ---------- */
    if (in_array($action, [
        'save_settings', 'save_topbar', 'save_hero', 'save_contact',
        'service_add', 'service_update', 'service_delete',
        'category_add', 'category_delete',
        'product_add', 'product_update', 'product_delete',
        'accessory_add', 'accessory_update', 'accessory_delete',
        'gallery_add', 'gallery_update', 'gallery_delete',
        'whyus_add', 'whyus_update', 'whyus_delete',
    ])) {
        require_login();
        csrf_check();

        switch ($action) {

            case 'save_settings':
                $stmt = $pdo->prepare("UPDATE website_settings SET shop_name=?, whatsapp=? WHERE id=1");
                $stmt->execute([trim($_POST['shop_name']), trim($_POST['whatsapp'])]);
                flash('success', 'Website settings saved.');
                redirect('index.php?tab=tab-settings');
                break;

            case 'save_topbar':
                $stmt = $pdo->prepare("UPDATE website_settings SET phone=?, email=?, instagram_url=?, facebook_url=?, linkedin_url=? WHERE id=1");
                $stmt->execute([
                    trim($_POST['phone']), trim($_POST['email']),
                    trim($_POST['insta']), trim($_POST['fb']), trim($_POST['linkedin']),
                ]);
                flash('success', 'Top bar settings saved.');
                redirect('index.php?tab=tab-topbar');
                break;

            case 'save_hero':
                $old = $pdo->query("SELECT hero_image FROM hero_sections WHERE id=1")->fetch();
                $img = handle_upload('hero_image');
                if ($img === null) $img = $old['hero_image']; // upload failed -> keep old
                if ($img !== false) { if ($img !== $old['hero_image']) delete_old_image($old['hero_image']); }
                else { $img = $old['hero_image']; }

                $stmt = $pdo->prepare("UPDATE hero_sections SET title=?, subtitle=?, hero_image=? WHERE id=1");
                $stmt->execute([trim($_POST['hero_title']), trim($_POST['hero_sub']), $img]);

                $oldAbout = $pdo->query("SELECT image FROM about_us WHERE id=1")->fetch();
                $aimg = handle_upload('about_image');
                if ($aimg === null) $aimg = $oldAbout['image'];
                if ($aimg !== false) { if ($aimg !== $oldAbout['image']) delete_old_image($oldAbout['image']); }
                else { $aimg = $oldAbout['image']; }

                $stmt2 = $pdo->prepare("UPDATE about_us SET content=?, image=? WHERE id=1");
                $stmt2->execute([trim($_POST['about_text']), $aimg]);

                flash('success', 'Hero & About saved.');
                redirect('index.php?tab=tab-hero');
                break;

            case 'save_contact':
                $stmt = $pdo->prepare("UPDATE website_settings SET address=?, google_map_embed=? WHERE id=1");
                $stmt->execute([trim($_POST['address']), trim($_POST['map_embed'])]);
                flash('success', 'Contact info saved.');
                redirect('index.php?tab=tab-contact');
                break;

            /* ---------------- SERVICES ---------------- */
            case 'service_add':
                $stmt = $pdo->prepare("INSERT INTO services (icon, title, description, status) VALUES (?,?,?,?)");
                $stmt->execute([trim($_POST['icon']), trim($_POST['title']), trim($_POST['description']), $_POST['status']]);
                flash('success', 'Service added.');
                redirect('index.php?tab=tab-services');
                break;

            case 'service_update':
                $stmt = $pdo->prepare("UPDATE services SET icon=?, title=?, description=?, status=? WHERE id=?");
                $stmt->execute([trim($_POST['icon']), trim($_POST['title']), trim($_POST['description']), $_POST['status'], (int)$_POST['id']]);
                flash('success', 'Service updated.');
                redirect('index.php?tab=tab-services');
                break;

            case 'service_delete':
                $stmt = $pdo->prepare("DELETE FROM services WHERE id=?");
                $stmt->execute([(int)$_POST['id']]);
                flash('success', 'Service deleted.');
                redirect('index.php?tab=tab-services');
                break;

            /* ---------------- CATEGORIES (used by Products) ---------------- */
            case 'category_add':
                $stmt = $pdo->prepare("INSERT INTO categories (category_name, status) VALUES (?, 'active')");
                $stmt->execute([trim($_POST['category_name'])]);
                flash('success', 'Category added.');
                redirect('index.php?tab=tab-products');
                break;

            case 'category_delete':
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id=?");
                $stmt->execute([(int)$_POST['id']]);
                flash('success', 'Category deleted.');
                redirect('index.php?tab=tab-products');
                break;

            /* ---------------- PRODUCTS ---------------- */
            case 'product_add':
                $img = handle_upload('image');
                if ($img === null) $img = null;
                if ($img === false) $img = null;
                $stmt = $pdo->prepare("INSERT INTO products (category_id, product_name, description, image, price, status) VALUES (?,?,?,?,?,?)");
                $stmt->execute([
                    $_POST['category_id'] ?: null, trim($_POST['product_name']),
                    trim($_POST['description']), $img,
                    $_POST['price'] !== '' ? $_POST['price'] : null, $_POST['status'],
                ]);
                flash('success', 'Product added.');
                redirect('index.php?tab=tab-products');
                break;

            case 'product_update':
                $old = $pdo->prepare("SELECT image FROM products WHERE id=?");
                $old->execute([(int)$_POST['id']]);
                $oldRow = $old->fetch();
                $img = handle_upload('image');
                if ($img === null) $img = $oldRow['image'];
                elseif ($img === false) $img = $oldRow['image'];
                else delete_old_image($oldRow['image']);

                $stmt = $pdo->prepare("UPDATE products SET category_id=?, product_name=?, description=?, image=?, price=?, status=? WHERE id=?");
                $stmt->execute([
                    $_POST['category_id'] ?: null, trim($_POST['product_name']),
                    trim($_POST['description']), $img,
                    $_POST['price'] !== '' ? $_POST['price'] : null, $_POST['status'],
                    (int)$_POST['id'],
                ]);
                flash('success', 'Product updated.');
                redirect('index.php?tab=tab-products');
                break;

            case 'product_delete':
                $old = $pdo->prepare("SELECT image FROM products WHERE id=?");
                $old->execute([(int)$_POST['id']]);
                if ($row = $old->fetch()) delete_old_image($row['image']);
                $stmt = $pdo->prepare("DELETE FROM products WHERE id=?");
                $stmt->execute([(int)$_POST['id']]);
                flash('success', 'Product deleted.');
                redirect('index.php?tab=tab-products');
                break;

            /* ---------------- ACCESSORIES ---------------- */
            case 'accessory_add':
                $img = handle_upload('image');
                if ($img === null || $img === false) $img = null;
                $stmt = $pdo->prepare("INSERT INTO accessories (name, image, description, status) VALUES (?,?,?,?)");
                $stmt->execute([trim($_POST['name']), $img, trim($_POST['description']), $_POST['status']]);
                flash('success', 'Accessory added.');
                redirect('index.php?tab=tab-accessories');
                break;

            case 'accessory_update':
                $old = $pdo->prepare("SELECT image FROM accessories WHERE id=?");
                $old->execute([(int)$_POST['id']]);
                $oldRow = $old->fetch();
                $img = handle_upload('image');
                if ($img === null || $img === false) $img = $oldRow['image'];
                else delete_old_image($oldRow['image']);

                $stmt = $pdo->prepare("UPDATE accessories SET name=?, image=?, description=?, status=? WHERE id=?");
                $stmt->execute([trim($_POST['name']), $img, trim($_POST['description']), $_POST['status'], (int)$_POST['id']]);
                flash('success', 'Accessory updated.');
                redirect('index.php?tab=tab-accessories');
                break;

            case 'accessory_delete':
                $old = $pdo->prepare("SELECT image FROM accessories WHERE id=?");
                $old->execute([(int)$_POST['id']]);
                if ($row = $old->fetch()) delete_old_image($row['image']);
                $stmt = $pdo->prepare("DELETE FROM accessories WHERE id=?");
                $stmt->execute([(int)$_POST['id']]);
                flash('success', 'Accessory deleted.');
                redirect('index.php?tab=tab-accessories');
                break;

            /* ---------------- GALLERY ---------------- */
            case 'gallery_add':
                $img = handle_upload('image');
                if ($img === null || $img === false) $img = null;
                $stmt = $pdo->prepare("INSERT INTO gallery (title, image, gallery_type, status) VALUES (?,?,?,?)");
                $stmt->execute([trim($_POST['title']), $img, $_POST['gallery_type'], $_POST['status']]);
                flash('success', 'Gallery image added.');
                redirect('index.php?tab=tab-gallery');
                break;

            case 'gallery_update':
                $old = $pdo->prepare("SELECT image FROM gallery WHERE id=?");
                $old->execute([(int)$_POST['id']]);
                $oldRow = $old->fetch();
                $img = handle_upload('image');
                if ($img === null || $img === false) $img = $oldRow['image'];
                else delete_old_image($oldRow['image']);

                $stmt = $pdo->prepare("UPDATE gallery SET title=?, image=?, gallery_type=?, status=? WHERE id=?");
                $stmt->execute([trim($_POST['title']), $img, $_POST['gallery_type'], $_POST['status'], (int)$_POST['id']]);
                flash('success', 'Gallery image updated.');
                redirect('index.php?tab=tab-gallery');
                break;

            case 'gallery_delete':
                $old = $pdo->prepare("SELECT image FROM gallery WHERE id=?");
                $old->execute([(int)$_POST['id']]);
                if ($row = $old->fetch()) delete_old_image($row['image']);
                $stmt = $pdo->prepare("DELETE FROM gallery WHERE id=?");
                $stmt->execute([(int)$_POST['id']]);
                flash('success', 'Gallery image deleted.');
                redirect('index.php?tab=tab-gallery');
                break;

            /* ---------------- WHY CHOOSE US ---------------- */
            case 'whyus_add':
                $stmt = $pdo->prepare("INSERT INTO why_choose_us (icon, title, description, status) VALUES (?,?,?,?)");
                $stmt->execute([trim($_POST['icon']), trim($_POST['title']), trim($_POST['description']), $_POST['status']]);
                flash('success', 'Item added.');
                redirect('index.php?tab=tab-whyus');
                break;

            case 'whyus_update':
                $stmt = $pdo->prepare("UPDATE why_choose_us SET icon=?, title=?, description=?, status=? WHERE id=?");
                $stmt->execute([trim($_POST['icon']), trim($_POST['title']), trim($_POST['description']), $_POST['status'], (int)$_POST['id']]);
                flash('success', 'Item updated.');
                redirect('index.php?tab=tab-whyus');
                break;

            case 'whyus_delete':
                $stmt = $pdo->prepare("DELETE FROM why_choose_us WHERE id=?");
                $stmt->execute([(int)$_POST['id']]);
                flash('success', 'Item deleted.');
                redirect('index.php?tab=tab-whyus');
                break;
        }
    }
}

/* ---------- LOGOUT ---------- */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
}

/* ============================================================
   5) LOAD DATA FOR RENDERING (only if logged in)
   ============================================================ */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$activeTab = $_GET['tab'] ?? 'tab-topbar';

if (is_logged_in()) {
    $settings   = $pdo->query("SELECT * FROM website_settings WHERE id=1")->fetch();
    $hero       = $pdo->query("SELECT * FROM hero_sections WHERE id=1")->fetch();
    $about      = $pdo->query("SELECT * FROM about_us WHERE id=1")->fetch();
    $services   = $pdo->query("SELECT * FROM services ORDER BY id DESC")->fetchAll();
    $categories = $pdo->query("SELECT * FROM categories ORDER BY category_name ASC")->fetchAll();
    $products   = $pdo->query("SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.id DESC")->fetchAll();
    $accessories= $pdo->query("SELECT * FROM accessories ORDER BY id DESC")->fetchAll();
    $gallery    = $pdo->query("SELECT * FROM gallery ORDER BY id DESC")->fetchAll();
    $whyus      = $pdo->query("SELECT * FROM why_choose_us ORDER BY id DESC")->fetchAll();
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Gopinath_Mobile</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="css/admin.css">
</head>
<body>

<?php if (!is_logged_in()): ?>
<!-- ===== LOGIN SCREEN ===== -->
<div id="loginScreen">
  <div class="login-card">
    <div class="login-logo">Gopinath<span>_Mobile</span></div>
    <p class="text-muted mb-4">Dashboard Login</p>
    <form method="post">
      <input type="hidden" name="form_action" value="login">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="mb-3 text-start">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required>
      </div>
      <div class="mb-3 text-start">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <?php if ($flash && $flash['type'] === 'error'): ?>
        <div class="alert alert-danger"><?= e($flash['msg']) ?></div>
      <?php endif; ?>
      <button class="btn btn-primary w-100" type="submit">Login</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ===== DASHBOARD ===== -->
<div id="dashboardApp">
<div class="d-flex">
  <!-- SIDEBAR -->
  <div class="sidebar">
    <div class="sidebar-logo">Gopinath<span>_Mobile</span><small>Dashboard</small></div>
    <ul class="nav flex-column sidebar-nav">
      <li><a href="?tab=tab-settings" class="nav-link <?= $activeTab==='tab-settings'?'active':'' ?>"><i class="bi bi-gear"></i> Website Settings</a></li>
      <li><a href="?tab=tab-topbar" class="nav-link <?= $activeTab==='tab-topbar'?'active':'' ?>"><i class="bi bi-sliders"></i> Top Bar</a></li>
      <li><a href="?tab=tab-hero" class="nav-link <?= $activeTab==='tab-hero'?'active':'' ?>"><i class="bi bi-image"></i> Hero & About</a></li>
      <li><a href="?tab=tab-services" class="nav-link <?= $activeTab==='tab-services'?'active':'' ?>"><i class="bi bi-tools"></i> Services</a></li>
      <li><a href="?tab=tab-products" class="nav-link <?= $activeTab==='tab-products'?'active':'' ?>"><i class="bi bi-bag"></i> Products</a></li>
      <li><a href="?tab=tab-accessories" class="nav-link <?= $activeTab==='tab-accessories'?'active':'' ?>"><i class="bi bi-usb-plug"></i> Accessories</a></li>
      <li><a href="?tab=tab-gallery" class="nav-link <?= $activeTab==='tab-gallery'?'active':'' ?>"><i class="bi bi-images"></i> Gallery</a></li>
      <li><a href="?tab=tab-whyus" class="nav-link <?= $activeTab==='tab-whyus'?'active':'' ?>"><i class="bi bi-patch-check"></i> Why Choose Us</a></li>
      <li><a href="?tab=tab-contact" class="nav-link <?= $activeTab==='tab-contact'?'active':'' ?>"><i class="bi bi-telephone"></i> Contact Info</a></li>
      <li><a href="../client/index.html" target="_blank" class="nav-link"><i class="bi bi-box-arrow-up-right"></i> View Website</a></li>
      <li><a href="?logout=1" class="nav-link text-danger"><i class="bi bi-box-arrow-left"></i> Logout</a></li>
    </ul>
  </div>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <div class="topbar-admin d-flex justify-content-between align-items-center">
      <h4><?php
        $titles = ['tab-settings'=>'Website Settings','tab-topbar'=>'Top Bar Settings','tab-hero'=>'Hero & About',
                   'tab-services'=>'Services','tab-products'=>'Products','tab-accessories'=>'Accessories',
                   'tab-gallery'=>'Gallery','tab-whyus'=>'Why Choose Us','tab-contact'=>'Contact Info'];
        echo e($titles[$activeTab] ?? 'Dashboard');
      ?></h4>
      <span class="text-muted small">Logged in as <?= e($_SESSION['admin_user']) ?></span>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type']==='success' ? 'alert-success' : 'alert-danger' ?>">
        <i class="bi bi-check-circle-fill me-2"></i><?= e($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- WEBSITE SETTINGS -->
    <div class="tab-pane <?= $activeTab==='tab-settings'?'active':'' ?>" id="tab-settings">
      <div class="card-box">
        <form method="post">
          <input type="hidden" name="form_action" value="save_settings">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Shop Name</label>
              <input type="text" class="form-control" name="shop_name" value="<?= e($settings['shop_name']) ?>"></div>
            <div class="col-md-6"><label class="form-label">WhatsApp Number</label>
              <input type="text" class="form-control" name="whatsapp" value="<?= e($settings['whatsapp']) ?>"></div>
          </div>
          <button class="btn btn-primary mt-4" type="submit">Save Changes</button>
        </form>
      </div>
    </div>

    <!-- TOP BAR -->
    <div class="tab-pane <?= $activeTab==='tab-topbar'?'active':'' ?>" id="tab-topbar">
      <div class="card-box">
        <form method="post">
          <input type="hidden" name="form_action" value="save_topbar">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Mobile Number</label>
              <input type="text" class="form-control" name="phone" value="<?= e($settings['phone']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Email Address</label>
              <input type="text" class="form-control" name="email" value="<?= e($settings['email']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Instagram Username</label>
              <input type="text" class="form-control" name="insta" value="<?= e($settings['instagram_url']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Facebook Link</label>
              <input type="text" class="form-control" name="fb" value="<?= e($settings['facebook_url']) ?>"></div>
            <div class="col-md-4"><label class="form-label">LinkedIn Link</label>
              <input type="text" class="form-control" name="linkedin" value="<?= e($settings['linkedin_url']) ?>"></div>
          </div>
          <button class="btn btn-primary mt-4" type="submit">Save Changes</button>
        </form>
      </div>
    </div>

    <!-- HERO + ABOUT -->
    <div class="tab-pane <?= $activeTab==='tab-hero'?'active':'' ?>" id="tab-hero">
      <div class="card-box">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="form_action" value="save_hero">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

          <h6 class="fw-bold mb-3">Hero Banner</h6>
          <div class="mb-3"><label class="form-label">Hero Title</label>
            <input type="text" class="form-control" name="hero_title" value="<?= e($hero['title']) ?>"></div>
          <div class="mb-3"><label class="form-label">Hero Subtitle</label>
            <textarea class="form-control" name="hero_sub" rows="2"><?= e($hero['subtitle']) ?></textarea></div>
          <div class="mb-3">
            <label class="form-label">Hero Image</label>
            <?php if ($hero['hero_image']): ?><img class="item-thumb mb-2" style="max-width:160px" src="<?= UPLOAD_URL . e($hero['hero_image']) ?>"><?php endif; ?>
            <input type="file" class="form-control" name="hero_image" accept="image/*">
          </div>

          <hr>
          <h6 class="fw-bold mb-3 mt-4">About Shop</h6>
          <div class="mb-3"><label class="form-label">About Text</label>
            <textarea class="form-control" name="about_text" rows="4"><?= e($about['content']) ?></textarea></div>
          <div class="mb-3">
            <label class="form-label">About Image</label>
            <?php if ($about['image']): ?><img class="item-thumb mb-2" style="max-width:160px" src="<?= UPLOAD_URL . e($about['image']) ?>"><?php endif; ?>
            <input type="file" class="form-control" name="about_image" accept="image/*">
          </div>

          <button class="btn btn-primary mt-2" type="submit">Save Changes</button>
        </form>
      </div>
    </div>

    <!-- SERVICES -->
    <div class="tab-pane <?= $activeTab==='tab-services'?'active':'' ?>" id="tab-services">
      <div class="card-box mb-3">
        <h6 class="fw-bold mb-3">Add Service</h6>
        <form method="post" class="row g-3 align-items-end">
          <input type="hidden" name="form_action" value="service_add">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="col-md-2"><label class="form-label">Icon (bootstrap-icons class)</label>
            <input type="text" class="form-control" name="icon" placeholder="bi-tools"></div>
          <div class="col-md-3"><label class="form-label">Title</label>
            <input type="text" class="form-control" name="title" required></div>
          <div class="col-md-4"><label class="form-label">Description</label>
            <input type="text" class="form-control" name="description"></div>
          <div class="col-md-2"><label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
          <div class="col-md-1"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-plus-lg"></i></button></div>
        </form>
      </div>

      <div class="card-box">
        <h6 class="fw-bold mb-3">Services List</h6>
        <?php foreach ($services as $s): ?>
        <div class="item-card">
          <form method="post" class="inline-form">
            <input type="hidden" name="form_action" value="service_update">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <div class="row g-3 align-items-center">
              <div class="col-md-2"><input type="text" class="form-control" name="icon" value="<?= e($s['icon']) ?>"></div>
              <div class="col-md-3"><input type="text" class="form-control" name="title" value="<?= e($s['title']) ?>" required></div>
              <div class="col-md-4"><input type="text" class="form-control" name="description" value="<?= e($s['description']) ?>"></div>
              <div class="col-md-2">
                <select class="form-select" name="status">
                  <option value="active" <?= $s['status']==='active'?'selected':'' ?>>Active</option>
                  <option value="inactive" <?= $s['status']==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
              </div>
              <div class="col-md-1 text-end"><button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-check-lg"></i></button></div>
            </div>
          </form>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete this service?');">
            <input type="hidden" name="form_action" value="service_delete">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <div class="text-end mt-2"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button></div>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$services): ?><p class="text-muted mb-0">No services yet.</p><?php endif; ?>
      </div>
    </div>

    <!-- PRODUCTS -->
    <div class="tab-pane <?= $activeTab==='tab-products'?'active':'' ?>" id="tab-products">

      <div class="card-box mb-3">
        <h6 class="fw-bold mb-3">Categories</h6>
        <form method="post" class="d-flex gap-2 mb-3">
          <input type="hidden" name="form_action" value="category_add">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="text" class="form-control" name="category_name" placeholder="New category name" required style="max-width:280px">
          <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add Category</button>
        </form>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($categories as $c): ?>
            <form method="post" class="inline-form" onsubmit="return confirm('Delete category &quot;<?= e($c['category_name']) ?>&quot;?');">
              <input type="hidden" name="form_action" value="category_delete">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <span class="badge rounded-pill text-bg-light border d-inline-flex align-items-center gap-2 p-2">
                <?= e($c['category_name']) ?>
                <button class="btn btn-sm btn-link text-danger p-0" type="submit" title="Delete"><i class="bi bi-x-circle"></i></button>
              </span>
            </form>
          <?php endforeach; ?>
          <?php if (!$categories): ?><span class="text-muted">No categories yet — add one above.</span><?php endif; ?>
        </div>
      </div>

      <div class="card-box mb-3">
        <h6 class="fw-bold mb-3">Add Product</h6>
        <form method="post" enctype="multipart/form-data" class="row g-3">
          <input type="hidden" name="form_action" value="product_add">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="col-md-4"><label class="form-label">Product Name</label><input type="text" class="form-control" name="product_name" required></div>
          <div class="col-md-3"><label class="form-label">Category</label>
            <select class="form-select" name="category_id">
              <option value="">— None —</option>
              <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['category_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><label class="form-label">Price (₹)</label><input type="number" step="0.01" class="form-control" name="price"></div>
          <div class="col-md-3"><label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
          <div class="col-md-8"><label class="form-label">Description</label><input type="text" class="form-control" name="description"></div>
          <div class="col-md-4"><label class="form-label">Image</label><input type="file" class="form-control" name="image" accept="image/*"></div>
          <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add Product</button></div>
        </form>
      </div>

      <div class="card-box">
        <h6 class="fw-bold mb-3">Products List</h6>
        <?php foreach ($products as $p): ?>
        <div class="item-card">
          <div class="row g-3 align-items-start">
            <div class="col-md-3"><img class="item-thumb" src="<?= $p['image'] ? UPLOAD_URL.e($p['image']) : '' ?>" alt=""></div>
            <div class="col-md-9">
              <form method="post" enctype="multipart/form-data" class="inline-form">
                <input type="hidden" name="form_action" value="product_update">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <div class="row g-2">
                  <div class="col-md-4"><input type="text" class="form-control" name="product_name" value="<?= e($p['product_name']) ?>" required></div>
                  <div class="col-md-3">
                    <select class="form-select" name="category_id">
                      <option value="">— None —</option>
                      <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $p['category_id']==$c['id']?'selected':'' ?>><?= e($c['category_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2"><input type="number" step="0.01" class="form-control" name="price" value="<?= e($p['price']) ?>"></div>
                  <div class="col-md-3">
                    <select class="form-select" name="status">
                      <option value="active" <?= $p['status']==='active'?'selected':'' ?>>Active</option>
                      <option value="inactive" <?= $p['status']==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                  </div>
                  <div class="col-md-8"><input type="text" class="form-control" name="description" value="<?= e($p['description']) ?>" placeholder="Description"></div>
                  <div class="col-md-3"><input type="file" class="form-control" name="image" accept="image/*"></div>
                  <div class="col-md-1"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-check-lg"></i></button></div>
                </div>
              </form>
            </div>
          </div>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete this product?');">
            <input type="hidden" name="form_action" value="product_delete">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <div class="text-end mt-2"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button></div>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$products): ?><p class="text-muted mb-0">No products yet.</p><?php endif; ?>
      </div>
    </div>

    <!-- ACCESSORIES -->
    <div class="tab-pane <?= $activeTab==='tab-accessories'?'active':'' ?>" id="tab-accessories">
      <div class="card-box mb-3">
        <h6 class="fw-bold mb-3">Add Accessory</h6>
        <form method="post" enctype="multipart/form-data" class="row g-3">
          <input type="hidden" name="form_action" value="accessory_add">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="col-md-4"><label class="form-label">Name</label><input type="text" class="form-control" name="name" required></div>
          <div class="col-md-4"><label class="form-label">Description</label><input type="text" class="form-control" name="description"></div>
          <div class="col-md-2"><label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
          <div class="col-md-2"><label class="form-label">Image</label><input type="file" class="form-control" name="image" accept="image/*"></div>
          <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add Accessory</button></div>
        </form>
      </div>

      <div class="card-box">
        <h6 class="fw-bold mb-3">Accessories List</h6>
        <?php foreach ($accessories as $a): ?>
        <div class="item-card">
          <div class="row g-3 align-items-start">
            <div class="col-md-3"><img class="item-thumb" src="<?= $a['image'] ? UPLOAD_URL.e($a['image']) : '' ?>" alt=""></div>
            <div class="col-md-9">
              <form method="post" enctype="multipart/form-data" class="inline-form">
                <input type="hidden" name="form_action" value="accessory_update">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <div class="row g-2">
                  <div class="col-md-4"><input type="text" class="form-control" name="name" value="<?= e($a['name']) ?>" required></div>
                  <div class="col-md-4"><input type="text" class="form-control" name="description" value="<?= e($a['description']) ?>"></div>
                  <div class="col-md-2">
                    <select class="form-select" name="status">
                      <option value="active" <?= $a['status']==='active'?'selected':'' ?>>Active</option>
                      <option value="inactive" <?= $a['status']==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                  </div>
                  <div class="col-md-1"><input type="file" class="form-control" name="image" accept="image/*"></div>
                  <div class="col-md-1"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-check-lg"></i></button></div>
                </div>
              </form>
            </div>
          </div>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete this accessory?');">
            <input type="hidden" name="form_action" value="accessory_delete">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <div class="text-end mt-2"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button></div>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$accessories): ?><p class="text-muted mb-0">No accessories yet.</p><?php endif; ?>
      </div>
    </div>

    <!-- GALLERY -->
    <div class="tab-pane <?= $activeTab==='tab-gallery'?'active':'' ?>" id="tab-gallery">
      <div class="card-box mb-3">
        <h6 class="fw-bold mb-3">Add Gallery Image</h6>
        <form method="post" enctype="multipart/form-data" class="row g-3">
          <input type="hidden" name="form_action" value="gallery_add">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="col-md-4"><label class="form-label">Title</label><input type="text" class="form-control" name="title"></div>
          <div class="col-md-3"><label class="form-label">Type</label>
            <select class="form-select" name="gallery_type"><option value="photo">Photo</option><option value="video">Video</option></select></div>
          <div class="col-md-2"><label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
          <div class="col-md-3"><label class="form-label">Image</label><input type="file" class="form-control" name="image" accept="image/*"></div>
          <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add Image</button></div>
        </form>
      </div>

      <div class="card-box">
        <h6 class="fw-bold mb-3">Gallery Images</h6>
        <?php foreach ($gallery as $g): ?>
        <div class="item-card">
          <div class="row g-3 align-items-start">
            <div class="col-md-3"><img class="item-thumb" src="<?= $g['image'] ? UPLOAD_URL.e($g['image']) : '' ?>" alt=""></div>
            <div class="col-md-9">
              <form method="post" enctype="multipart/form-data" class="inline-form">
                <input type="hidden" name="form_action" value="gallery_update">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <div class="row g-2">
                  <div class="col-md-4"><input type="text" class="form-control" name="title" value="<?= e($g['title']) ?>"></div>
                  <div class="col-md-3">
                    <select class="form-select" name="gallery_type">
                      <option value="photo" <?= $g['gallery_type']==='photo'?'selected':'' ?>>Photo</option>
                      <option value="video" <?= $g['gallery_type']==='video'?'selected':'' ?>>Video</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <select class="form-select" name="status">
                      <option value="active" <?= $g['status']==='active'?'selected':'' ?>>Active</option>
                      <option value="inactive" <?= $g['status']==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                  </div>
                  <div class="col-md-2"><input type="file" class="form-control" name="image" accept="image/*"></div>
                  <div class="col-md-1"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-check-lg"></i></button></div>
                </div>
              </form>
            </div>
          </div>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete this image?');">
            <input type="hidden" name="form_action" value="gallery_delete">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
            <div class="text-end mt-2"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button></div>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$gallery): ?><p class="text-muted mb-0">No gallery images yet.</p><?php endif; ?>
      </div>
    </div>

    <!-- WHY US -->
    <div class="tab-pane <?= $activeTab==='tab-whyus'?'active':'' ?>" id="tab-whyus">
      <div class="card-box mb-3">
        <h6 class="fw-bold mb-3">Add Item</h6>
        <form method="post" class="row g-3 align-items-end">
          <input type="hidden" name="form_action" value="whyus_add">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="col-md-2"><label class="form-label">Icon</label><input type="text" class="form-control" name="icon" placeholder="bi-patch-check"></div>
          <div class="col-md-3"><label class="form-label">Title</label><input type="text" class="form-control" name="title" required></div>
          <div class="col-md-4"><label class="form-label">Description</label><input type="text" class="form-control" name="description"></div>
          <div class="col-md-2"><label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
          <div class="col-md-1"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-plus-lg"></i></button></div>
        </form>
      </div>

      <div class="card-box">
        <h6 class="fw-bold mb-3">Why Choose Us List</h6>
        <?php foreach ($whyus as $w): ?>
        <div class="item-card">
          <form method="post" class="inline-form">
            <input type="hidden" name="form_action" value="whyus_update">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <div class="row g-3 align-items-center">
              <div class="col-md-2"><input type="text" class="form-control" name="icon" value="<?= e($w['icon']) ?>"></div>
              <div class="col-md-3"><input type="text" class="form-control" name="title" value="<?= e($w['title']) ?>" required></div>
              <div class="col-md-4"><input type="text" class="form-control" name="description" value="<?= e($w['description']) ?>"></div>
              <div class="col-md-2">
                <select class="form-select" name="status">
                  <option value="active" <?= $w['status']==='active'?'selected':'' ?>>Active</option>
                  <option value="inactive" <?= $w['status']==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
              </div>
              <div class="col-md-1 text-end"><button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-check-lg"></i></button></div>
            </div>
          </form>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete this item?');">
            <input type="hidden" name="form_action" value="whyus_delete">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <div class="text-end mt-2"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button></div>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$whyus): ?><p class="text-muted mb-0">No items yet.</p><?php endif; ?>
      </div>
    </div>

    <!-- CONTACT -->
    <div class="tab-pane <?= $activeTab==='tab-contact'?'active':'' ?>" id="tab-contact">
      <div class="card-box">
        <form method="post">
          <input type="hidden" name="form_action" value="save_contact">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="mb-3"><label class="form-label">Address</label>
            <input type="text" class="form-control" name="address" value="<?= e($settings['address']) ?>"></div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Phone</label>
              <input type="text" class="form-control" value="<?= e($settings['phone']) ?>" disabled>
              <div class="form-text">Edit phone from the "Top Bar" tab.</div></div>
            <div class="col-md-6"><label class="form-label">Email</label>
              <input type="text" class="form-control" value="<?= e($settings['email']) ?>" disabled>
              <div class="form-text">Edit email from the "Top Bar" tab.</div></div>
          </div>
          <div class="mb-3 mt-3"><label class="form-label">Google Map Embed URL</label>
            <input type="text" class="form-control" name="map_embed" value="<?= e($settings['google_map_embed']) ?>"></div>
          <button class="btn btn-primary mt-2" type="submit">Save Changes</button>
        </form>
      </div>
    </div>

  </div>
</div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tab visibility is driven server-side via ?tab= query string (see .active class
// added to .tab-pane / .nav-link above), so no client JS is required for that.
// Auto-hide the flash message after a few seconds.
setTimeout(function () {
  var box = document.querySelector('.alert.alert-success, .alert.alert-danger');
  if (box) box.style.display = 'none';
}, 4000);
</script>
</body>
</html>
