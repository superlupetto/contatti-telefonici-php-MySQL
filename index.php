<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

/* =========================
   0) CONFIG DB + UPLOADS
========================= */
$upload_dir = __DIR__ . '/uploadslist/';
$upload_url = 'uploadslist/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);

/** Bootstrap admin (solo prima installazione) */
$bootstrap_admin_user = "admin";
$bootstrap_admin_pass = "nome";

/** DB config */
define('DB_HOST', 'localhost');
define('DB_NAME', 'rubrica');
define('DB_USER', 'root');
define('DB_PASS', 'password'); // <-- metti la tua password DB

if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strpos($haystack, $needle) === 0;
  }
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
  } catch (Throwable $e) {
    http_response_code(500);
    echo "<pre style='padding:14px;background:#111;color:#fff;border-radius:12px;white-space:pre-wrap'>";
    echo "ERRORE CONNESSIONE DB\n\n";
    echo "Host: ".DB_HOST."\nDB: ".DB_NAME."\nUser: ".DB_USER."\n\n";
    echo "Dettaglio:\n".$e->getMessage();
    echo "</pre>";
    exit;
  }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function safe_path_inside_uploads($path, $upload_url) {
  if (!$path) return "";
  $path = str_replace("\\", "/", (string)$path);
  if (strpos($path, $upload_url) !== 0) return "";
  if (strpos($path, "..") !== false) return "";
  return $path;
}

/* =========================
   DB INIT + MIGRATION
========================= */
function init_db(string $admin_user, string $admin_pass): void {
  $pdo = db();

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(50) NOT NULL UNIQUE,
      pass_hash VARCHAR(255) NOT NULL,
      role ENUM('admin','moderator','user') NOT NULL DEFAULT 'user',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS contacts (
      id VARCHAR(64) PRIMARY KEY,
      user_id INT NOT NULL,
      nome VARCHAR(120) NOT NULL,
      cognome VARCHAR(120) NOT NULL DEFAULT '',
      telefono VARCHAR(60) NOT NULL,
      email VARCHAR(190) NOT NULL DEFAULT '',
      avatar VARCHAR(255) NOT NULL DEFAULT '',
      preferito TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_user_id (user_id),
      KEY idx_preferito_nome (preferito, nome),
      KEY idx_tel (telefono),
      KEY idx_email (email)
    ) ENGINE=InnoDB;
  ");

  // Bootstrap admin se non esiste
  $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
  $st->execute([$admin_user]);
  if (!$st->fetchColumn()) {
    $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO users (username, pass_hash, role, is_active) VALUES (?, ?, 'admin', 1)");
    $ins->execute([$admin_user, $hash]);
  }
}

function table_exists(string $table): bool {
  $pdo = db();
  $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=? LIMIT 1");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function column_exists(string $table, string $column): bool {
  $pdo = db();
  $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $st->execute([$table, $column]);
  return (bool)$st->fetchColumn();
}

function migrate_db(): void {
  $pdo = db();

  // USERS.role fix (evita 1265)
  if (table_exists('users') && column_exists('users', 'role')) {
    $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'user'");
    $pdo->exec("UPDATE users SET role = LOWER(role)");
    $pdo->exec("UPDATE users SET role='user' WHERE role IS NULL OR role='' OR role NOT IN ('admin','moderator','user')");
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','moderator','user') NOT NULL DEFAULT 'user'");
  }

  // CONTACTS.user_id migrazione (se avevi tabella vecchia)
  if (table_exists('contacts')) {

    if (!column_exists('contacts', 'user_id')) {
      $pdo->exec("ALTER TABLE contacts ADD COLUMN user_id INT NULL AFTER id");
    }

    $adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($adminId <= 0) {
      $adminId = (int)$pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
    }

    if ($adminId > 0) {
      $st = $pdo->prepare("UPDATE contacts SET user_id=? WHERE user_id IS NULL OR user_id=0");
      $st->execute([$adminId]);
    }

    $pdo->exec("ALTER TABLE contacts MODIFY user_id INT NOT NULL");

    // Indice
    try { $pdo->exec("ALTER TABLE contacts ADD KEY idx_user_id (user_id)"); } catch (Throwable $e) {}

    // FK (non blocca se già esiste o se non si può creare)
    try {
      $pdo->exec("ALTER TABLE contacts
        ADD CONSTRAINT fk_contacts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
      ");
    } catch (Throwable $e) {}
  }
}

init_db($bootstrap_admin_user, $bootstrap_admin_pass);
migrate_db();

/* =========================
   CSRF
========================= */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

/* =========================
   AUTH HELPERS
========================= */
function current_user(): ?array {
  if (empty($_SESSION['uid'])) return null;
  $pdo = db();
  $st = $pdo->prepare("SELECT id, username, role, is_active FROM users WHERE id=? LIMIT 1");
  $st->execute([(int)$_SESSION['uid']]);
  $u = $st->fetch();
  if (!$u || (int)$u['is_active'] !== 1) return null;
  return $u;
}
function require_auth(): array {
  $u = current_user();
  if (!$u) {
    session_destroy();
    header("Location: index.php");
    exit();
  }
  return $u;
}
function is_admin(array $u): bool { return ($u['role'] ?? '') === 'admin'; }
function can_manage_contacts(array $u): bool {
  $r = (string)($u['role'] ?? '');
  return in_array($r, ['admin','moderator','user'], true);
}
function require_admin(array $u): void {
  if (!is_admin($u)) { http_response_code(403); echo "Permesso negato."; exit; }
}

/* =========================
   USERS (ADMIN)
========================= */
function count_admins_active(): int {
  $pdo = db();
  return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
}
function fetch_users(): array {
  $pdo = db();
  return $pdo->query("SELECT id, username, role, is_active, created_at, updated_at FROM users ORDER BY role='admin' DESC, role='moderator' DESC, username ASC")->fetchAll() ?: [];
}
function fetch_users_for_select(): array {
  $pdo = db();
  return $pdo->query("SELECT id, username, role, is_active FROM users WHERE is_active=1 ORDER BY username ASC")->fetchAll() ?: [];
}
function create_user_admin(string $username, string $plain_pass, string $role): void {
  $pdo = db();
  $hash = password_hash($plain_pass, PASSWORD_DEFAULT);
  $st = $pdo->prepare("INSERT INTO users (username, pass_hash, role, is_active) VALUES (?, ?, ?, 1)");
  $st->execute([$username, $hash, $role]);
}
function set_user_role(int $uid, string $role): void {
  $pdo = db();
  $st = $pdo->prepare("UPDATE users SET role=? WHERE id=?");
  $st->execute([$role, $uid]);
}
function set_user_active(int $uid, int $active): void {
  $pdo = db();
  $st = $pdo->prepare("UPDATE users SET is_active=? WHERE id=?");
  $st->execute([$active, $uid]);
}
function delete_user(int $uid): void {
  $pdo = db();
  $st = $pdo->prepare("DELETE FROM users WHERE id=?");
  $st->execute([$uid]);
}

/* =========================
   CONTACTS CRUD (multi-tenant)
========================= */
function contact_belongs_to_user(string $id, int $uid): bool {
  $pdo = db();
  $st = $pdo->prepare("SELECT 1 FROM contacts WHERE id=? AND user_id=? LIMIT 1");
  $st->execute([$id, $uid]);
  return (bool)$st->fetchColumn();
}
function require_contact_access(array $user, string $contact_id): void {
  if (($user['role'] ?? '') === 'admin') return;
  if (!contact_belongs_to_user($contact_id, (int)$user['id'])) {
    http_response_code(403);
    echo "Permesso negato.";
    exit;
  }
}

function fetch_contacts(array $user): array {
  $pdo = db();
  if (($user['role'] ?? '') === 'admin') {
    $rows = $pdo->query("SELECT * FROM contacts ORDER BY preferito DESC, nome ASC")->fetchAll();
  } else {
    $st = $pdo->prepare("SELECT * FROM contacts WHERE user_id=? ORDER BY preferito DESC, nome ASC");
    $st->execute([(int)$user['id']]);
    $rows = $st->fetchAll();
  }
  foreach ($rows as &$r) $r['preferito'] = (bool)((int)($r['preferito'] ?? 0));
  return $rows ?: [];
}

function upsert_contact(array $c): void {
  $pdo = db();
  $sql = "
    INSERT INTO contacts (id, user_id, nome, cognome, telefono, email, avatar, preferito)
    VALUES (:id, :user_id, :nome, :cognome, :telefono, :email, :avatar, :preferito)
    ON DUPLICATE KEY UPDATE
      nome=VALUES(nome),
      cognome=VALUES(cognome),
      telefono=VALUES(telefono),
      email=VALUES(email),
      avatar=VALUES(avatar),
      preferito=VALUES(preferito),
      user_id=VALUES(user_id)
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':id' => $c['id'],
    ':user_id' => (int)$c['user_id'],
    ':nome' => $c['nome'],
    ':cognome' => $c['cognome'],
    ':telefono' => $c['telefono'],
    ':email' => $c['email'],
    ':avatar' => $c['avatar'],
    ':preferito' => $c['preferito'] ? 1 : 0,
  ]);
}

function delete_contact(string $id): ?string {
  $pdo = db();
  $st = $pdo->prepare("SELECT avatar FROM contacts WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $avatar = $st->fetchColumn();
  $del = $pdo->prepare("DELETE FROM contacts WHERE id=?");
  $del->execute([$id]);
  return is_string($avatar) ? $avatar : null;
}

function toggle_fav(string $id): void {
  $pdo = db();
  $st = $pdo->prepare("UPDATE contacts SET preferito = IF(preferito=1,0,1) WHERE id=?");
  $st->execute([$id]);
}

/* =========================
   LOGOUT
========================= */
if (isset($_GET['logout'])) {
  session_destroy();
  header("Location: index.php");
  exit();
}

/* =========================
   LOGIN (username + password)
========================= */
if (isset($_POST['login_user'], $_POST['login_pass'])) {
  $usern = trim((string)($_POST['login_user'] ?? ''));
  $pass = (string)($_POST['login_pass'] ?? '');

  $pdo = db();
  $st = $pdo->prepare("SELECT id, pass_hash, is_active FROM users WHERE username=? LIMIT 1");
  $st->execute([$usern]);
  $row = $st->fetch();

  if ($row && (int)$row['is_active'] === 1 && password_verify($pass, (string)$row['pass_hash'])) {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
  } else {
    $error = "Credenziali non valide!";
  }
}

/* =========================
   LOGIN UI
========================= */
if (!current_user()): ?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Login</title>
  <style>
    :root{
      --bg1:#0b1020; --bg2:#0a1830;
      --glass: rgba(255,255,255,.10); --glass2: rgba(255,255,255,.14);
      --stroke: rgba(255,255,255,.18);
      --text: rgba(255,255,255,.92); --muted: rgba(255,255,255,.70);
      --shadow: 0 20px 60px rgba(0,0,0,.45);
      --radius: 22px;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple Color Emoji","Segoe UI Emoji";
      color:var(--text);
      background:
        radial-gradient(1200px 700px at 20% 10%, rgba(125,211,252,.25), transparent 60%),
        radial-gradient(900px 600px at 85% 20%, rgba(167,139,250,.22), transparent 55%),
        radial-gradient(800px 700px at 50% 95%, rgba(16,185,129,.12), transparent 60%),
        linear-gradient(180deg, var(--bg1), var(--bg2));
      display:flex; align-items:center; justify-content:center; padding:24px;
    }
    .card{
      width:min(420px, 100%);
      border-radius: var(--radius);
      background: linear-gradient(180deg, var(--glass2), rgba(255,255,255,.06));
      border: 1px solid var(--stroke);
      backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
      box-shadow: var(--shadow);
      padding: 22px;
      position:relative; overflow:hidden;
    }
    .card:before{
      content:""; position:absolute; inset:-2px;
      background: radial-gradient(700px 260px at 30% 0%, rgba(125,211,252,.25), transparent 60%),
                  radial-gradient(700px 260px at 70% 0%, rgba(167,139,250,.20), transparent 55%);
      pointer-events:none; filter: blur(10px); opacity:.9;
    }
    .inner{position:relative; z-index:1}
    .brand{display:flex; align-items:center; gap:12px; margin-bottom: 14px;}
    .logo{width:42px; height:42px; border-radius: 14px;
      background: linear-gradient(135deg, rgba(125,211,252,.9), rgba(167,139,250,.85));
      box-shadow: 0 14px 30px rgba(0,0,0,.25);
    }
    h1{margin:0; font-size:20px; font-weight: 650;}
    .sub{margin: 6px 0 0; color: var(--muted); font-size: 13.5px; line-height: 1.35;}
    form{margin-top:16px}
    .field{margin-top: 12px; display:flex; flex-direction:column; gap:8px;}
    label{color: var(--muted); font-size: 12px;}
    input{
      width:100%; padding: 14px 14px; border-radius: 16px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(0,0,0,.16);
      color: var(--text); outline:none; transition: .2s ease;
    }
    input:focus{
      border-color: rgba(125,211,252,.55);
      box-shadow: 0 0 0 4px rgba(125,211,252,.14);
      transform: translateY(-1px);
    }
    .btn{
      margin-top: 14px; width:100%;
      padding: 13px 14px; border-radius: 16px;
      border: 1px solid rgba(255,255,255,.18);
      background: linear-gradient(135deg, rgba(125,211,252,.95), rgba(167,139,250,.90));
      color: rgba(0,0,0,.88);
      font-weight: 750; cursor:pointer; transition: .2s ease;
      box-shadow: 0 18px 40px rgba(0,0,0,.25);
    }
    .btn:hover{ transform: translateY(-1px); filter: brightness(1.02) }
    .btn:active{ transform: translateY(1px); filter: brightness(.98) }
    .error{
      margin-top: 12px; padding: 10px 12px; border-radius: 14px;
      border: 1px solid rgba(251,113,133,.35);
      background: rgba(251,113,133,.12);
      color: rgba(255,255,255,.92); font-size: 13px;
    }
    .foot{margin-top: 14px; color: rgba(255,255,255,.55); font-size: 12px; text-align:center;}
  </style>
</head>
<body>
  <div class="card">
    <div class="inner">
      <div class="brand">
        <div class="logo"></div>
        <div>
          <h1>Accedi</h1>
          <div class="sub">Inserisci username e password.</div>
        </div>
      </div>

      <form method="POST" autocomplete="off">
        <div class="field">
          <label for="u">Username</label>
          <input id="u" type="text" name="login_user" placeholder="admin" required autofocus />
        </div>
        <div class="field">
          <label for="p">Password</label>
          <input id="p" type="password" name="login_pass" placeholder="••••••••" required />
        </div>
        <button class="btn" type="submit">Entra</button>
      </form>

      <?php if (isset($error)) : ?>
        <div class="error"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="foot">Liquid Glass UI ✨</div>
    </div>
  </div>
</body>
</html>
<?php exit(); endif; ?>

<?php
/* =========================
   AUTH OK
========================= */
$user = require_auth();

/* =========================
   TOAST
========================= */
$toast_msg = $_SESSION['toast_msg'] ?? null;
$toast_err = $_SESSION['toast_err'] ?? null;
unset($_SESSION['toast_msg'], $_SESSION['toast_err']);

/* =========================
   ADMIN ACTIONS (users)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && str_starts_with((string)$_POST['azione'], 'admin_')) {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $_SESSION['toast_err'] = "Richiesta non valida (CSRF).";
    header("Location: index.php");
    exit();
  }
  require_admin($user);

  try {
    $azione = (string)$_POST['azione'];

    if ($azione === 'admin_create_user') {
      $new_user = trim((string)($_POST['new_user'] ?? ''));
      $new_pass = (string)($_POST['new_pass'] ?? '');
      $new_pass2 = (string)($_POST['new_pass2'] ?? '');
      $role_in = (string)($_POST['new_role'] ?? 'user');
      $role = in_array($role_in, ['admin','moderator','user'], true) ? $role_in : 'user';

      if ($new_user === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $new_user)) {
        $_SESSION['toast_err'] = "Username non valido (3-50, solo lettere/numeri . _ -).";
      } elseif (strlen($new_pass) < 6) {
        $_SESSION['toast_err'] = "Password troppo corta (min 6).";
      } elseif ($new_pass !== $new_pass2) {
        $_SESSION['toast_err'] = "Le password non coincidono.";
      } else {
        create_user_admin($new_user, $new_pass, $role);
        $_SESSION['toast_msg'] = "Utente creato ✅ (" . $new_user . ")";
      }

    } elseif ($azione === 'admin_set_role') {
      $uid = (int)($_POST['uid'] ?? 0);
      $role_in = (string)($_POST['role'] ?? 'user');
      $role = in_array($role_in, ['admin','moderator','user'], true) ? $role_in : 'user';

      if ($uid === (int)$user['id']) {
        $_SESSION['toast_err'] = "Non puoi cambiare il tuo ruolo.";
      } else {
        set_user_role($uid, $role);
        $_SESSION['toast_msg'] = "Ruolo aggiornato ✅";
      }

    } elseif ($azione === 'admin_toggle_active') {
      $uid = (int)($_POST['uid'] ?? 0);
      $active = (int)($_POST['active'] ?? 1);

      if ($uid === (int)$user['id']) {
        $_SESSION['toast_err'] = "Non puoi disattivare te stesso.";
      } else {
        if ($active === 0) {
          $pdo = db();
          $st = $pdo->prepare("SELECT role, is_active FROM users WHERE id=? LIMIT 1");
          $st->execute([$uid]);
          $t = $st->fetch();
          if ($t && ($t['role'] ?? '') === 'admin' && (int)($t['is_active'] ?? 1) === 1) {
            if (count_admins_active() <= 1) {
              $_SESSION['toast_err'] = "Deve rimanere almeno 1 admin attivo.";
              header("Location: index.php");
              exit();
            }
          }
        }
        set_user_active($uid, $active);
        $_SESSION['toast_msg'] = $active ? "Utente riattivato ✅" : "Utente disattivato ✅";
      }

    } elseif ($azione === 'admin_delete_user') {
      $uid = (int)($_POST['uid'] ?? 0);

      if ($uid === (int)$user['id']) {
        $_SESSION['toast_err'] = "Non puoi eliminare te stesso.";
      } else {
        $pdo = db();
        $st = $pdo->prepare("SELECT role, is_active FROM users WHERE id=? LIMIT 1");
        $st->execute([$uid]);
        $t = $st->fetch();
        if ($t && ($t['role'] ?? '') === 'admin' && (int)($t['is_active'] ?? 1) === 1) {
          if (count_admins_active() <= 1) {
            $_SESSION['toast_err'] = "Deve rimanere almeno 1 admin attivo.";
            header("Location: index.php");
            exit();
          }
        }
        delete_user($uid);
        $_SESSION['toast_msg'] = "Utente eliminato ✅";
      }
    }

  } catch (Throwable $e) {
    $_SESSION['toast_err'] = "Errore DB: " . $e->getMessage();
  }

  header("Location: index.php");
  exit();
}

/* =========================
   CHANGE PASSWORD (utente loggato)
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST['azione'] ?? '') === 'change_pass') {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $_SESSION['toast_err'] = "Richiesta non valida (CSRF).";
    header("Location: index.php");
    exit();
  }

  $old = (string)($_POST['old_pass'] ?? '');
  $new = (string)($_POST['new_pass'] ?? '');
  $new2 = (string)($_POST['new_pass2'] ?? '');

  $pdo = db();
  $st = $pdo->prepare("SELECT pass_hash FROM users WHERE id=? LIMIT 1");
  $st->execute([(int)$user['id']]);
  $hash = $st->fetchColumn();

  if (!$hash || !password_verify($old, (string)$hash)) {
    $_SESSION['toast_err'] = "Password attuale non corretta.";
  } elseif (strlen($new) < 6) {
    $_SESSION['toast_err'] = "La nuova password deve avere almeno 6 caratteri.";
  } elseif ($new !== $new2) {
    $_SESSION['toast_err'] = "Le due password nuove non coincidono.";
  } else {
    $new_hash = password_hash($new, PASSWORD_DEFAULT);
    $up = $pdo->prepare("UPDATE users SET pass_hash=? WHERE id=?");
    $up->execute([$new_hash, (int)$user['id']]);
    $_SESSION['toast_msg'] = "Password aggiornata ✅";
  }

  header("Location: index.php");
  exit();
}

/* =========================
   CRUD CONTATTI
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST['azione'] ?? '') === 'salva') {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $_SESSION['toast_err'] = "Richiesta non valida (CSRF).";
    header("Location: index.php");
    exit();
  }

  if (!can_manage_contacts($user)) {
    http_response_code(403);
    echo "Permesso negato.";
    exit;
  }

  $id = !empty($_POST['id']) ? (string)$_POST['id'] : uniqid("c_", true);

  // se aggiorno un contatto esistente, verifica ownership (non-admin)
  if (!empty($_POST['id'])) {
    require_contact_access($user, (string)$_POST['id']);
  }

  $old_avatar = safe_path_inside_uploads($_POST['old_avatar'] ?? "", $upload_url);
  $avatar_path = $old_avatar;

  if (isset($_FILES['avatar']) && isset($_FILES['avatar']['error']) && $_FILES['avatar']['error'] === 0) {
    $orig = (string)($_FILES["avatar"]["name"] ?? "avatar");
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ["jpg","jpeg","png","webp","gif"];

    if (in_array($ext, $allowed, true)) {
      $base = preg_replace("/[^a-zA-Z0-9._-]/", "_", pathinfo($orig, PATHINFO_FILENAME));
      $avatar_name = time() . "_" . $base . "." . $ext;

      if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $upload_dir . $avatar_name)) {
        if (!empty($old_avatar) && file_exists(__DIR__ . "/" . $old_avatar)) {
          @unlink(__DIR__ . "/" . $old_avatar);
        }
        $avatar_path = $upload_url . $avatar_name;
      }
    }
  }

  $preferito = (isset($_POST['preferito']) && (string)$_POST['preferito'] === '1');

  // owner: per default user loggato; admin può assegnare ad altro utente
  $owner_id = (int)$user['id'];
  if (is_admin($user) && isset($_POST['user_id'])) {
    $owner_id = max(1, (int)$_POST['user_id']);
  }

  $contact_data = [
    'id' => $id,
    'user_id' => $owner_id,
    'nome' => trim((string)($_POST['nome'] ?? "")),
    'cognome' => trim((string)($_POST['cognome'] ?? "")),
    'telefono' => trim((string)($_POST['telefono'] ?? "")),
    'email' => trim((string)($_POST['email'] ?? "")),
    'avatar' => $avatar_path,
    'preferito' => $preferito
  ];

  upsert_contact($contact_data);
  header("Location: index.php");
  exit();
}

if (isset($_GET['action'], $_GET['id'])) {
  $action = (string)$_GET['action'];
  $id = (string)$_GET['id'];

  if (!can_manage_contacts($user)) {
    http_response_code(403);
    echo "Permesso negato.";
    exit;
  }

  require_contact_access($user, $id);

  if ($action === 'delete') {
    $avatar = delete_contact($id);
    $av = safe_path_inside_uploads($avatar ?? "", $upload_url);
    if (!empty($av) && file_exists(__DIR__ . "/" . $av)) @unlink(__DIR__ . "/" . $av);
  } elseif ($action === 'toggle_fav') {
    toggle_fav($id);
  }

  header("Location: index.php");
  exit();
}

/* =========================
   DATA FOR UI
========================= */
$contacts = fetch_contacts($user);
$contacts_json = json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$users = is_admin($user) ? fetch_users() : [];
$users_json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$users_select = is_admin($user) ? fetch_users_for_select() : [];
$users_select_json = json_encode($users_select, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Contatti</title>
  <style>
    :root{
      --bg0:#070b16;
      --bg1:#0b1630;
      --glass: rgba(255,255,255,.10);
      --glass2: rgba(255,255,255,.14);
      --stroke: rgba(255,255,255,.18);
      --text: rgba(255,255,255,.92);
      --muted: rgba(255,255,255,.68);
      --muted2: rgba(255,255,255,.50);
      --shadow: 0 24px 70px rgba(0,0,0,.48);
      --radius: 22px;
      --radius2: 18px;
      --danger:#fb7185;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple Color Emoji","Segoe UI Emoji";
      color: var(--text);
      background:
        radial-gradient(1200px 700px at 15% 5%, rgba(125,211,252,.22), transparent 60%),
        radial-gradient(900px 600px at 88% 10%, rgba(167,139,250,.20), transparent 55%),
        radial-gradient(900px 650px at 55% 98%, rgba(52,211,153,.12), transparent 62%),
        linear-gradient(180deg, var(--bg0), var(--bg1));
      padding-bottom: 40px;
    }
    .topbar{
      position: sticky; top: 0;
      z-index: 50;
      padding: max(14px, env(safe-area-inset-top)) 16px 12px 16px;
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      background: linear-gradient(180deg, rgba(10,15,30,.72), rgba(10,15,30,.35));
      border-bottom: 1px solid rgba(255,255,255,.10);
    }
    .topbar-inner{
      width:min(980px, 100%);
      margin:0 auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
    }
    .title{
      display:flex; align-items:center; gap:12px;
      min-width: 0;
    }
    .appdot{
      width:38px; height:38px;
      border-radius: 14px;
      background: linear-gradient(135deg, rgba(125,211,252,.95), rgba(167,139,250,.90));
      box-shadow: 0 16px 40px rgba(0,0,0,.30);
      flex:0 0 auto;
    }
    h1{
      margin:0;
      font-size: 18px;
      font-weight: 780;
      letter-spacing:.2px;
      line-height:1.1;
    }
    .subtitle{
      margin: 2px 0 0;
      color: var(--muted);
      font-size: 12.5px;
      white-space: nowrap;
      overflow:hidden;
      text-overflow: ellipsis;
      max-width: 56vw;
    }
    .actions{
      display:flex;
      align-items:center;
      gap:10px;
      flex:0 0 auto;
    }
    .iconbtn{
      width: 40px; height: 40px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.16);
      background: rgba(255,255,255,.08);
      color: var(--text);
      cursor:pointer;
      display:grid;
      place-items:center;
      transition:.18s ease;
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      box-shadow: 0 12px 30px rgba(0,0,0,.22);
      user-select:none;
    }
    .iconbtn:hover{ transform: translateY(-1px); background: rgba(255,255,255,.10) }
    .iconbtn:active{ transform: translateY(1px); background: rgba(255,255,255,.07) }
    .logout{
      color: var(--muted);
      text-decoration:none;
      font-size: 13px;
      padding: 10px 12px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transition:.18s ease;
      white-space:nowrap;
    }
    .logout:hover{ color: var(--text); background: rgba(255,255,255,.08) }

    .toolbar{
      width:min(980px, 100%);
      margin: 14px auto 0;
      padding: 0 16px;
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      align-items:center;
    }
    .search{
      flex:1 1 240px;
      display:flex;
      align-items:center;
      gap:10px;
      padding: 12px 14px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.07);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      box-shadow: 0 18px 45px rgba(0,0,0,.20);
    }
    .search span{ opacity:.9 }
    .search input{
      width:100%;
      border:none;
      outline:none;
      background: transparent;
      color: var(--text);
      font-size: 14px;
    }
    .search input::placeholder{ color: rgba(255,255,255,.55) }
    .tabs{
      flex:0 0 auto;
      display:flex;
      gap:8px;
      padding: 6px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
    }
    .tab{
      border:none;
      cursor:pointer;
      padding: 10px 12px;
      border-radius: 14px;
      background: transparent;
      color: var(--muted);
      font-weight: 650;
      font-size: 13px;
      transition:.18s ease;
      user-select:none;
      white-space:nowrap;
    }
    .tab.active{
      color: rgba(0,0,0,.85);
      background: linear-gradient(135deg, rgba(125,211,252,.95), rgba(167,139,250,.90));
      box-shadow: 0 12px 26px rgba(0,0,0,.22);
    }

    .content{
      width:min(980px, 100%);
      margin: 16px auto 0;
      padding: 0 16px;
    }
    .section-label{
      margin: 16px 0 10px;
      font-size: 12px;
      letter-spacing:.18em;
      text-transform: uppercase;
      color: rgba(255,255,255,.62);
      padding-left: 2px;
    }
    .list{ display:flex; flex-direction:column; gap:10px; }
    .item{
      display:flex; align-items:center; gap:12px;
      padding: 12px 12px;
      border-radius: 20px;
      border: 1px solid rgba(255,255,255,.14);
      background: linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.05));
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      box-shadow: 0 18px 50px rgba(0,0,0,.22);
      cursor:pointer;
      transition: .18s ease;
      user-select:none;
    }
    .item:hover{ transform: translateY(-1px); background: linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05)) }
    .item:active{ transform: translateY(1px) }
    .avatar{
      width: 44px; height: 44px;
      border-radius: 18px;
      display:grid; place-items:center;
      color: rgba(255,255,255,.95);
      font-weight: 800;
      overflow:hidden;
      flex:0 0 auto;
      border: 1px solid rgba(255,255,255,.14);
      box-shadow: inset 0 0 0 1px rgba(0,0,0,.18);
    }
    .avatar img{ width:100%; height:100%; object-fit:cover }
    .meta{
      min-width:0; flex:1 1 auto;
      display:flex; flex-direction:column; gap:2px;
    }
    .name{
      font-weight: 780;
      letter-spacing:.1px;
      white-space:nowrap; overflow:hidden; text-overflow: ellipsis;
    }
    .mini{
      color: var(--muted);
      font-size: 13px;
      white-space:nowrap; overflow:hidden; text-overflow: ellipsis;
    }
    .phone{
      flex:0 0 auto;
      color: rgba(255,255,255,.80);
      font-weight: 650;
      font-size: 13px;
      padding: 8px 10px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(0,0,0,.12);
    }
    .badgeStar{ margin-left: 6px; opacity:.9; font-size: 14px; }
    .empty{
      margin-top: 16px;
      padding: 18px;
      border-radius: 22px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.75);
      text-align:center;
    }

    .toast{
      width:min(980px, 100%);
      margin: 10px auto 0;
      padding: 0 16px;
    }
    .msg{
      padding: 10px 12px;
      border-radius: 16px;
      border: 1px solid rgba(52,211,153,.35);
      background: rgba(52,211,153,.12);
      color: rgba(255,255,255,.92);
      font-size: 13px;
    }
    .err{
      padding: 10px 12px;
      border-radius: 16px;
      border: 1px solid rgba(251,113,133,.35);
      background: rgba(251,113,133,.12);
      color: rgba(255,255,255,.92);
      font-size: 13px;
    }

    .overlay{ position:fixed; inset:0; z-index:200; display:none; flex-direction:column; background: rgba(0,0,0,.7); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); }
    .overlayTop{ position:sticky; top:0; z-index:5; padding:max(14px, env(safe-area-inset-top)) 16px 12px 16px; border-bottom:1px solid rgba(255,255,255,.10); background: rgba(12,18,36,.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
    .overlayTopInner{ width:min(980px, 100%); margin:0 auto; display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .overlayBtns{ display:flex; gap:10px; align-items:center; }
    .detail{ width:min(980px, 100%); margin:0 auto; padding:18px 16px 28px; }
    .hero{ margin-top:6px; display:grid; place-items:center; gap:10px; padding:16px 0 10px; }
    .bigAvatar{ width:110px; height:110px; border-radius:36px; display:grid; place-items:center; overflow:hidden; border:1px solid rgba(255,255,255,.18); box-shadow: 0 26px 70px rgba(0,0,0,.35); font-size:42px; font-weight:900; color: rgba(255,255,255,.95); }
    .bigAvatar img{ width:100%; height:100%; object-fit:cover }
    .bigName{ font-size: 26px; font-weight: 900; letter-spacing:.2px; text-align:center; margin:0; }
    .card{ margin-top:14px; border-radius:26px; border:1px solid rgba(255,255,255,.14); background: linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.05)); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); box-shadow: 0 24px 70px rgba(0,0,0,.28); overflow:hidden; }
    .cardHeader{ padding:16px 16px 10px; color: rgba(255,255,255,.75); font-size:12px; letter-spacing:.18em; text-transform: uppercase; }
    .row{ display:flex; gap:12px; align-items:flex-start; padding:14px 16px; border-top:1px solid rgba(255,255,255,.08); }
    .row:first-of-type{ border-top:none; }
    .ico{ width:34px; height:34px; border-radius:14px; display:grid; place-items:center; border:1px solid rgba(255,255,255,.12); background: rgba(0,0,0,.14); flex:0 0 auto; }
    .rowMain{ min-width:0; flex:1 1 auto; }
    .rowLabel{ font-size:12px; color: rgba(255,255,255,.58); margin-bottom:2px; }
    .rowValue{ font-size:14px; color: rgba(255,255,255,.90); white-space:nowrap; overflow:hidden; text-overflow: ellipsis; }
    .quick{ display:flex; gap:10px; padding:14px 16px 16px; border-top:1px solid rgba(255,255,255,.08); }
    .pill{ flex:1 1 0; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:10px; padding:12px 12px; border-radius:18px; border:1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.07); color: rgba(255,255,255,.92); font-weight: 780; transition:.18s ease; }
    .pill:hover{ transform: translateY(-1px); background: rgba(255,255,255,.09) }

    .sheetWrap{ position:fixed; inset:0; z-index:300; display:none; align-items:flex-end; justify-content:center; background: rgba(0,0,0,.48); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding:16px; }
    .sheet{ width:min(560px, 100%); border-radius:28px; border:1px solid rgba(255,255,255,.16); background: linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.06)); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); box-shadow: var(--shadow); overflow:hidden; transform: translateY(10px); animation: pop .22s ease forwards; }
    @keyframes pop { to { transform: translateY(0); } }
    .handle{ width:54px; height:5px; border-radius:999px; background: rgba(255,255,255,.22); margin:10px auto 0; }
    .sheetTop{ padding:14px 16px 10px; display:flex; align-items:center; justify-content:space-between; gap:10px; border-bottom:1px solid rgba(255,255,255,.10); }
    .sheetTitle{ margin:0; font-size: 16px; font-weight: 850; letter-spacing:.2px; }
    .sheetBody{ padding: 12px 16px 16px; }
    .grid{ display:grid; grid-template-columns: 1fr; gap:10px; }
    .f{ display:flex; flex-direction:column; gap:7px; }
    .f label{ font-size:12px; color: rgba(255,255,255,.60); }
    .f input, .f select{
      width:100%; padding:13px 14px; border-radius:18px; border:1px solid rgba(255,255,255,.14);
      background: rgba(0,0,0,.14); color: var(--text); outline:none; transition:.18s ease;
    }
    .f input:focus, .f select:focus{ border-color: rgba(125,211,252,.55); box-shadow: 0 0 0 4px rgba(125,211,252,.14); transform: translateY(-1px); }
    .sheetActions{ display:flex; gap:10px; justify-content:flex-end; padding:12px 16px 16px; border-top:1px solid rgba(255,255,255,.10); }
    .btn{ border:none; cursor:pointer; border-radius:18px; padding:12px 14px; font-weight: 850; transition:.18s ease; user-select:none; }
    .btnGhost{ background: rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14); color: rgba(255,255,255,.85); }
    .btnPrimary{ color: rgba(0,0,0,.85); background: linear-gradient(135deg, rgba(125,211,252,.95), rgba(167,139,250,.90)); border:1px solid rgba(255,255,255,.18); box-shadow: 0 18px 40px rgba(0,0,0,.28); }
    .btnDanger{ background: rgba(251,113,133,.14); border:1px solid rgba(251,113,133,.32); color: rgba(255,255,255,.92); }
  </style>
</head>
<body>

  <div class="topbar">
    <div class="topbar-inner">
      <div class="title">
        <div class="appdot"></div>
        <div style="min-width:0">
          <h1>Contatti</h1>
          <div class="subtitle">
            <?= h($user['username']) ?> (<?= h($user['role']) ?>) · <?= count($contacts) ?> contatti
            <?php if (!is_admin($user)): ?> · solo i tuoi contatti<?php endif; ?>
          </div>
        </div>
      </div>

      <div class="actions">
        <button class="iconbtn" onclick="openEdit(null)" aria-label="Nuovo contatto" title="Nuovo">➕</button>
        <button class="iconbtn" onclick="openPass()" aria-label="Cambia password" title="Cambia password">🔒</button>

        <?php if (is_admin($user)): ?>
          <button class="iconbtn" onclick="openUsersAdmin()" aria-label="Gestione utenti" title="Gestione utenti">👥</button>
        <?php endif; ?>

        <a class="logout" href="?logout=1">Esci</a>
      </div>
    </div>

    <div class="toolbar">
      <div class="search">
        <span>🔎</span>
        <input id="q" type="search" placeholder="Cerca nome, telefono, email…" autocomplete="off" />
      </div>

      <div class="tabs" role="tablist" aria-label="Filtro contatti">
        <button class="tab active" id="tabAll" onclick="setTab('all')" type="button">Tutti</button>
        <button class="tab" id="tabFav" onclick="setTab('fav')" type="button">Preferiti</button>
      </div>
    </div>
  </div>

  <?php if ($toast_msg || $toast_err): ?>
    <div class="toast">
      <?php if ($toast_msg): ?><div class="msg"><?= h($toast_msg) ?></div><?php endif; ?>
      <?php if ($toast_err): ?><div class="err"><?= h($toast_err) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="content">
    <div id="list" class="list"></div>
    <div id="empty" class="empty" style="display:none;">
      Nessun contatto trovato. Prova a cambiare filtro o cerca qualcos’altro ✨
    </div>
  </div>

  <!-- DETAIL OVERLAY -->
  <div id="viewOverlay" class="overlay" aria-hidden="true">
    <div class="overlayTop">
      <div class="overlayTopInner">
        <button class="iconbtn" onclick="closeView()" aria-label="Indietro">←</button>
        <div class="overlayBtns">
          <button class="iconbtn" id="btnEdit" aria-label="Modifica" title="Modifica">✎</button>
          <button class="iconbtn" id="btnStar" aria-label="Preferito" title="Preferito">☆</button>
          <button class="iconbtn" id="btnDelete" aria-label="Elimina" title="Elimina">🗑️</button>
          <button class="iconbtn" onclick="closeView()" aria-label="Chiudi" title="Chiudi">✕</button>
        </div>
      </div>
    </div>

    <div class="detail">
      <div class="hero">
        <div id="v_avatar" class="bigAvatar"></div>
        <p id="v_nome" class="bigName"></p>
      </div>

      <div class="card">
        <div class="cardHeader">Dettagli contatto</div>

        <div class="row">
          <div class="ico">📞</div>
          <div class="rowMain">
            <div class="rowLabel">Telefono</div>
            <div id="v_tel" class="rowValue"></div>
          </div>
        </div>

        <div class="row">
          <div class="ico">📧</div>
          <div class="rowMain">
            <div class="rowLabel">Email</div>
            <div id="v_email" class="rowValue"></div>
          </div>
        </div>

        <div class="quick">
          <a id="callBtn" class="pill" href="#" onclick="return false;">📞 Chiama</a>
          <a id="mailBtn" class="pill" href="#" onclick="return false;">✉️ Email</a>
        </div>
      </div>
    </div>
  </div>

  <!-- EDIT SHEET -->
  <div id="editSheetWrap" class="sheetWrap" onclick="sheetBackdropClose(event)">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="Modifica contatto">
      <div class="handle"></div>
      <div class="sheetTop">
        <div>
          <p id="etitle" class="sheetTitle">Crea contatto</p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;">
            Salvataggio su MySQL · <?= is_admin($user) ? "admin (tutti)" : "solo tuoi contatti" ?>
          </div>
        </div>
        <button class="iconbtn" onclick="closeEdit()" aria-label="Chiudi">✕</button>
      </div>

      <form action="index.php" method="POST" enctype="multipart/form-data">
        <div class="sheetBody">
          <input type="hidden" name="azione" value="salva">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="id" id="e_id">
          <input type="hidden" name="old_avatar" id="e_old_avatar">
          <input type="hidden" name="preferito" id="e_preferito">

          <?php if (is_admin($user)): ?>
            <div class="f" style="margin-bottom:10px;">
              <label for="e_user_id">Assegna a utente</label>
              <select name="user_id" id="e_user_id"></select>
            </div>
          <?php endif; ?>

          <div class="grid">
            <div class="f">
              <label for="e_nome">Nome *</label>
              <input type="text" name="nome" id="e_nome" placeholder="Nome" required>
            </div>
            <div class="f">
              <label for="e_cognome">Cognome</label>
              <input type="text" name="cognome" id="e_cognome" placeholder="Cognome">
            </div>
            <div class="f">
              <label for="e_tel">Telefono *</label>
              <input type="tel" name="telefono" id="e_tel" placeholder="+39 ..." required>
            </div>
            <div class="f">
              <label for="e_email">Email</label>
              <input type="email" name="email" id="e_email" placeholder="nome@dominio.it">
            </div>
            <div class="f">
              <label for="e_avatar">Avatar (immagine)</label>
              <input id="e_avatar" type="file" name="avatar" accept="image/*">
            </div>
          </div>
        </div>

        <div class="sheetActions">
          <button type="button" class="btn btnGhost" onclick="closeEdit()">Annulla</button>
          <button type="submit" class="btn btnPrimary">Salva</button>
        </div>
      </form>
    </div>
  </div>

  <!-- CHANGE PASSWORD SHEET -->
  <div id="passSheetWrap" class="sheetWrap" onclick="passBackdropClose(event)">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="Cambia password">
      <div class="handle"></div>
      <div class="sheetTop">
        <div>
          <p class="sheetTitle">Cambia password</p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;">
            Utente: <b><?= h($user['username']) ?></b>
          </div>
        </div>
        <button class="iconbtn" onclick="closePass()" aria-label="Chiudi">✕</button>
      </div>

      <form action="index.php" method="POST" autocomplete="off">
        <div class="sheetBody">
          <input type="hidden" name="azione" value="change_pass">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

          <div class="grid">
            <div class="f">
              <label for="old_pass">Password attuale *</label>
              <input id="old_pass" type="password" name="old_pass" required>
            </div>

            <div class="f">
              <label for="new_pass">Nuova password *</label>
              <input id="new_pass" type="password" name="new_pass" required>
            </div>

            <div class="f">
              <label for="new_pass2">Ripeti nuova password *</label>
              <input id="new_pass2" type="password" name="new_pass2" required>
            </div>
          </div>
        </div>

        <div class="sheetActions">
          <button type="button" class="btn btnGhost" onclick="closePass()">Annulla</button>
          <button type="submit" class="btn btnPrimary">Aggiorna</button>
        </div>
      </form>
    </div>
  </div>

  <!-- USERS ADMIN SHEET -->
  <?php if (is_admin($user)): ?>
  <div id="usersSheetWrap" class="sheetWrap" onclick="usersBackdropClose(event)">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="Gestione utenti">
      <div class="handle"></div>
      <div class="sheetTop">
        <div>
          <p class="sheetTitle">Gestione utenti</p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;">
            admin / moderator / user · attiva/disattiva · elimina
          </div>
        </div>
        <button class="iconbtn" onclick="closeUsersAdmin()" aria-label="Chiudi">✕</button>
      </div>

      <div class="sheetBody">
        <div class="card" style="margin-top:0;">
          <div class="cardHeader">Crea nuovo utente</div>
          <form action="index.php" method="POST" autocomplete="off" style="padding:14px 16px 16px;">
            <input type="hidden" name="azione" value="admin_create_user">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

            <div class="grid">
              <div class="f">
                <label>Username *</label>
                <input name="new_user" required placeholder="es. mario.rossi">
              </div>
              <div class="f">
                <label>Password *</label>
                <input type="password" name="new_pass" required>
              </div>
              <div class="f">
                <label>Ripeti password *</label>
                <input type="password" name="new_pass2" required>
              </div>
              <div class="f">
                <label>Ruolo</label>
                <select name="new_role">
                  <option value="user">user</option>
                  <option value="moderator">moderator</option>
                  <option value="admin">admin</option>
                </select>
              </div>
            </div>

            <div class="sheetActions" style="padding:12px 0 0;border-top:none;">
              <button type="submit" class="btn btnPrimary">Crea</button>
            </div>
          </form>
        </div>

        <div class="card">
          <div class="cardHeader">Tutti gli utenti</div>
          <div id="usersList" style="padding:10px 10px 14px;"></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

<script>
  const ALL_CONTACTS = <?= $contacts_json ?: "[]" ?>;
  const IS_ADMIN = <?= is_admin($user) ? 'true' : 'false' ?>;

  let currentTab = "all";
  let currentQuery = "";
  let viewing = null;

  const $ = (id) => document.getElementById(id);
  function normalize(s){ return (s||"").toString().toLowerCase().trim(); }

  function contactMatches(c, q){
    if (!q) return true;
    const hay = [c.nome, c.cognome, c.telefono, c.email].map(normalize).join(" ");
    return hay.includes(q);
  }

  function filteredContacts(){
    return ALL_CONTACTS.filter(c => {
      if (currentTab === "fav" && !c.preferito) return false;
      return contactMatches(c, currentQuery);
    });
  }

  function groupContacts(list){
    const fav = list.filter(c => c.preferito);
    const oth = list.filter(c => !c.preferito);
    return { fav, oth };
  }

  function render(){
    const list = filteredContacts();
    const grouped = groupContacts(list);
    const container = $("list");
    container.innerHTML = "";

    const showEmpty = (list.length === 0);
    $("empty").style.display = showEmpty ? "block" : "none";
    if (showEmpty) return;

    const sections = [];
    if (currentTab === "all") {
      if (grouped.fav.length) sections.push({ label: "Preferiti", items: grouped.fav });
      if (grouped.oth.length) sections.push({ label: "Contatti", items: grouped.oth });
    } else {
      sections.push({ label: "Preferiti", items: grouped.fav });
    }

    for (const s of sections) {
      const lab = document.createElement("div");
      lab.className = "section-label";
      lab.textContent = s.label;
      container.appendChild(lab);

      for (const c of s.items) {
        const item = document.createElement("div");
        item.className = "item";
        item.onclick = () => openView(c);

        const av = document.createElement("div");
        av.className = "avatar";
        av.style.background = `linear-gradient(135deg, ${avatarColor(c.id, 0.95)}, ${avatarColor2(c.id, 0.9)})`;

        if (c.avatar) {
          const img = document.createElement("img");
          img.src = c.avatar;
          img.alt = "avatar";
          av.appendChild(img);
        } else {
          av.textContent = (c.nome || "?").charAt(0).toUpperCase();
        }

        const meta = document.createElement("div");
        meta.className = "meta";

        const nm = document.createElement("div");
        nm.className = "name";
        nm.textContent = `${c.nome || ""} ${c.cognome || ""}`.trim() || "Senza nome";

        if (c.preferito) {
          const star = document.createElement("span");
          star.className = "badgeStar";
          star.textContent = "★";
          nm.appendChild(star);
        }

        const mini = document.createElement("div");
        mini.className = "mini";
        mini.textContent = (c.email || "—");

        meta.appendChild(nm);
        meta.appendChild(mini);

        const phone = document.createElement("div");
        phone.className = "phone";
        phone.textContent = c.telefono || "";

        item.appendChild(av);
        item.appendChild(meta);
        item.appendChild(phone);

        container.appendChild(item);
      }
    }
  }

  function hexFromId(id){
    let h = 0;
    const s = (id || "").toString();
    for (let i=0;i<s.length;i++) h = ((h<<5)-h) + s.charCodeAt(i), h |= 0;
    const hex = (h >>> 0).toString(16).padStart(8,'0');
    return hex;
  }
  function avatarColor(id, a=1){
    const hex = hexFromId(id);
    const r = parseInt(hex.slice(0,2),16);
    const g = parseInt(hex.slice(2,4),16);
    const b = parseInt(hex.slice(4,6),16);
    return `rgba(${r},${g},${b},${a})`;
  }
  function avatarColor2(id, a=1){
    const hex = hexFromId(id + "_x");
    const r = parseInt(hex.slice(0,2),16);
    const g = parseInt(hex.slice(2,4),16);
    const b = parseInt(hex.slice(4,6),16);
    return `rgba(${r},${g},${b},${a})`;
  }

  function setTab(t){
    currentTab = t;
    $("tabAll").classList.toggle("active", t === "all");
    $("tabFav").classList.toggle("active", t === "fav");
    render();
  }

  $("q").addEventListener("input", (e) => {
    currentQuery = normalize(e.target.value);
    render();
  });

  function openView(c){
    viewing = c;

    $("v_nome").textContent = `${c.nome || ""} ${c.cognome || ""}`.trim() || "Senza nome";
    $("v_tel").textContent = c.telefono || "—";
    $("v_email").textContent = c.email || "—";

    const av = $("v_avatar");
    av.style.background = `linear-gradient(135deg, ${avatarColor(c.id, 0.95)}, ${avatarColor2(c.id, 0.9)})`;
    av.innerHTML = c.avatar ? `<img src="${c.avatar}" alt="avatar">` : (c.nome||"?").charAt(0).toUpperCase();

    $("btnEdit").onclick = () => openEdit(c);
    $("btnStar").textContent = c.preferito ? "★" : "☆";
    $("btnStar").onclick = () => window.location.href = `?action=toggle_fav&id=${encodeURIComponent(c.id)}`;
    $("btnDelete").onclick = () => {
      if (confirm("Eliminare questo contatto?")) {
        window.location.href = `?action=delete&id=${encodeURIComponent(c.id)}`;
      }
    };

    const tel = (c.telefono||"").replace(/\s+/g,'');
    const email = (c.email||"").trim();

    const callBtn = $("callBtn");
    callBtn.href = tel ? `tel:${tel}` : "#";
    callBtn.style.opacity = tel ? "1" : ".45";
    callBtn.onclick = () => !!tel;

    const mailBtn = $("mailBtn");
    mailBtn.href = email ? `mailto:${email}` : "#";
    mailBtn.style.opacity = email ? "1" : ".45";
    mailBtn.onclick = () => !!email;

    $("viewOverlay").style.display = "flex";
    $("viewOverlay").setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
  }
  function closeView(){
    $("viewOverlay").style.display = "none";
    $("viewOverlay").setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
  }

  // ====== EDIT SHEET ======
  const USERS_SELECT = <?= $users_select_json ?: "[]" ?>;

  function fillUserSelect(selectedId){
    const sel = $("e_user_id");
    if (!sel) return;
    sel.innerHTML = "";
    for (const u of USERS_SELECT) {
      const opt = document.createElement("option");
      opt.value = u.id;
      opt.textContent = `${u.username} (${u.role})`;
      sel.appendChild(opt);
    }
    if (selectedId) sel.value = String(selectedId);
  }

  function openEdit(c=null){
    closeView();

    if (c) {
      $("etitle").textContent = "Modifica contatto";
      $("e_id").value = c.id || "";
      $("e_nome").value = c.nome || "";
      $("e_cognome").value = c.cognome || "";
      $("e_tel").value = c.telefono || "";
      $("e_email").value = c.email || "";
      $("e_old_avatar").value = c.avatar || "";
      $("e_preferito").value = c.preferito ? "1" : "0";
      if (IS_ADMIN) fillUserSelect(c.user_id || "");
    } else {
      $("etitle").textContent = "Crea contatto";
      document.querySelector("#editSheetWrap form").reset();
      $("e_id").value = "";
      $("e_old_avatar").value = "";
      $("e_preferito").value = "0";
      if (IS_ADMIN) fillUserSelect("");
    }

    $("editSheetWrap").style.display = "flex";
    document.body.style.overflow = "hidden";
    setTimeout(() => $("e_nome").focus(), 50);
  }

  function closeEdit(){
    $("editSheetWrap").style.display = "none";
    document.body.style.overflow = "";
  }

  function sheetBackdropClose(e){
    if (e.target && e.target.id === "editSheetWrap") closeEdit();
  }

  // ====== PASS SHEET ======
  function openPass(){
    closeView();
    $("passSheetWrap").style.display = "flex";
    document.body.style.overflow = "hidden";
    setTimeout(() => $("old_pass").focus(), 50);
  }
  function closePass(){
    $("passSheetWrap").style.display = "none";
    document.body.style.overflow = "";
  }
  function passBackdropClose(e){
    if (e.target && e.target.id === "passSheetWrap") closePass();
  }

  // ====== USERS ADMIN ======
  <?php if (is_admin($user)): ?>
    const ALL_USERS = <?= $users_json ?: "[]" ?>;
    const CSRF = <?= json_encode($CSRF) ?>;
    const MY_UID = <?= (int)$user['id'] ?>;

    function openUsersAdmin(){
      closeView();
      renderUsersAdmin();
      $("usersSheetWrap").style.display = "flex";
      document.body.style.overflow = "hidden";
    }
    function closeUsersAdmin(){
      $("usersSheetWrap").style.display = "none";
      document.body.style.overflow = "";
    }
    function usersBackdropClose(e){
      if (e.target && e.target.id === "usersSheetWrap") closeUsersAdmin();
    }

    function postAdmin(action, payload){
      const f = document.createElement("form");
      f.method = "POST";
      f.action = "index.php";
      const add = (k,v) => {
        const i = document.createElement("input");
        i.type = "hidden";
        i.name = k;
        i.value = String(v);
        f.appendChild(i);
      };
      add("azione", action);
      add("csrf", CSRF);
      Object.entries(payload || {}).forEach(([k,v]) => add(k,v));
      document.body.appendChild(f);
      f.submit();
    }

    function renderUsersAdmin(){
      const box = $("usersList");
      box.innerHTML = "";

      for (const u of ALL_USERS) {
        const row = document.createElement("div");
        row.className = "row";
        row.style.alignItems = "center";

        const left = document.createElement("div");
        left.className = "rowMain";

        const label = document.createElement("div");
        label.className = "rowLabel";
        label.textContent = `ID: ${u.id} · ${u.is_active ? "attivo" : "disattivo"}`;

        const val = document.createElement("div");
        val.className = "rowValue";
        val.textContent = `${u.username} (${u.role})`;

        left.appendChild(label);
        left.appendChild(val);

        const acts = document.createElement("div");
        acts.style.display = "flex";
        acts.style.gap = "8px";
        acts.style.flex = "0 0 auto";

        const isMe = (parseInt(u.id) === MY_UID);

        const roleBtn = document.createElement("button");
        roleBtn.type = "button";
        roleBtn.className = "btn btnGhost";
        roleBtn.style.padding = "10px 12px";

        const nextRole = (u.role === "user") ? "moderator" : (u.role === "moderator") ? "admin" : "user";
        roleBtn.textContent = `Ruolo: → ${nextRole}`;
        roleBtn.disabled = isMe;
        roleBtn.onclick = () => postAdmin("admin_set_role", { uid: u.id, role: nextRole });

        const actBtn = document.createElement("button");
        actBtn.type = "button";
        actBtn.className = "btn btnGhost";
        actBtn.style.padding = "10px 12px";
        actBtn.textContent = u.is_active ? "Disattiva" : "Attiva";
        actBtn.disabled = isMe;
        actBtn.onclick = () => postAdmin("admin_toggle_active", { uid: u.id, active: u.is_active ? 0 : 1 });

        const delBtn = document.createElement("button");
        delBtn.type = "button";
        delBtn.className = "btn btnDanger";
        delBtn.style.padding = "10px 12px";
        delBtn.textContent = "Elimina";
        delBtn.disabled = isMe;
        delBtn.onclick = () => {
          if (confirm(`Eliminare utente "${u.username}"?`)) {
            postAdmin("admin_delete_user", { uid: u.id });
          }
        };

        acts.appendChild(roleBtn);
        acts.appendChild(actBtn);
        acts.appendChild(delBtn);

        row.appendChild(left);
        row.appendChild(acts);

        box.appendChild(row);
      }
    }
  <?php endif; ?>

  window.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      if ($("usersSheetWrap")?.style.display === "flex") closeUsersAdmin?.();
      if ($("passSheetWrap")?.style.display === "flex") closePass();
      if ($("editSheetWrap")?.style.display === "flex") closeEdit();
      if ($("viewOverlay")?.style.display === "flex") closeView();
    }
  });

  render();
</script>

</body>
</html>
