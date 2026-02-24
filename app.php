<?php
require __DIR__ . '/config.php';

/* =========================
   AUTH OK
========================= */
$user = require_auth();

/* =========================
   LOGOUT
========================= */
if (isset($_GET['logout'])) {
  session_destroy();
  header("Location: login.php");
  exit();
}

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
    $_SESSION['toast_err'] = t('error_csrf_req');
    header("Location: app.php");
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
      $role = in_array($role_in, ['admin','user'], true) ? $role_in : 'user';

      if ($new_user === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $new_user)) {
        $_SESSION['toast_err'] = t('error_username_invalid');
      } elseif (strlen($new_pass) < 6) {
        $_SESSION['toast_err'] = t('error_password_short');
      } elseif ($new_pass !== $new_pass2) {
        $_SESSION['toast_err'] = t('error_password_mismatch');
      } else {
        create_user_admin($new_user, $new_pass, $role);
        $_SESSION['toast_msg'] = t('msg_user_created') . $new_user . ")";
      }

    } elseif ($azione === 'admin_set_role') {
      $uid = (int)($_POST['uid'] ?? 0);
      $role_in = (string)($_POST['role'] ?? 'user');
      $role = in_array($role_in, ['admin','user'], true) ? $role_in : 'user';

      if ($uid <= 0) {
        $_SESSION['toast_err'] = t('error_invalid_user');
      } else {
        $pdo = db();
        $st = $pdo->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
        $st->execute([$uid]);
        $uname = (string)$st->fetchColumn();

        if ($uname === 'admin') {
          $_SESSION['toast_err'] = t('error_admin_no_role');
        } elseif ($uid === (int)$user['id']) {
          $_SESSION['toast_err'] = t('error_cant_change_self_role');
        } else {
          set_user_role($uid, $role);
          $_SESSION['toast_msg'] = t('msg_role_updated');
        }
      }

    } elseif ($azione === 'admin_toggle_active') {
      $uid = (int)($_POST['uid'] ?? 0);
      $active = (int)($_POST['active'] ?? 1);

      if ($uid === (int)$user['id']) {
        $_SESSION['toast_err'] = t('error_cant_deactivate_self');
      } else {
        if ($active === 0) {
          $pdo = db();
          $st = $pdo->prepare("SELECT username, role, is_active FROM users WHERE id=? LIMIT 1");
          $st->execute([$uid]);
          $t = $st->fetch();
          if ($t && ($t['username'] ?? '') === 'admin') {
            $_SESSION['toast_err'] = t('error_admin_no_deactivate');
            header("Location: app.php");
            exit();
          }
          if ($t && ($t['role'] ?? '') === 'admin' && (int)($t['is_active'] ?? 1) === 1) {
            if (count_admins_active() <= 1) {
              $_SESSION['toast_err'] = t('error_min_one_admin');
              header("Location: app.php");
              exit();
            }
          }
        }
        set_user_active($uid, $active);
        $_SESSION['toast_msg'] = $active ? t('msg_user_reactivated') : t('msg_user_deactivated');
      }

    } elseif ($azione === 'admin_delete_user') {
      $uid = (int)($_POST['uid'] ?? 0);

      if ($uid === (int)$user['id']) {
        $_SESSION['toast_err'] = t('error_cant_delete_self');
      } else {
        $pdo = db();
        $st = $pdo->prepare("SELECT username, role, is_active FROM users WHERE id=? LIMIT 1");
        $st->execute([$uid]);
        $t = $st->fetch();
        if ($t && ($t['username'] ?? '') === 'admin') {
          $_SESSION['toast_err'] = t('error_admin_no_delete');
          header("Location: app.php");
          exit();
        }
        if ($t && ($t['role'] ?? '') === 'admin' && (int)($t['is_active'] ?? 1) === 1) {
          if (count_admins_active() <= 1) {
            $_SESSION['toast_err'] = t('error_min_one_admin');
            header("Location: app.php");
            exit();
          }
        }
        delete_user($uid);
        $_SESSION['toast_msg'] = t('msg_user_deleted');
      }

    } elseif ($azione === 'admin_set_password') {
      $uid = (int)($_POST['uid'] ?? 0);
      $p1 = (string)($_POST['new_pass'] ?? '');
      $p2 = (string)($_POST['new_pass2'] ?? '');

      if ($uid <= 0) {
        $_SESSION['toast_err'] = t('error_invalid_user');
      } elseif ($uid === (int)$user['id']) {
        $_SESSION['toast_err'] = t('error_use_change_pass');
      } elseif (strlen($p1) < 6) {
        $_SESSION['toast_err'] = t('error_password_short');
      } elseif ($p1 !== $p2) {
        $_SESSION['toast_err'] = t('error_password_mismatch');
      } else {
        set_user_password($uid, $p1);
        $_SESSION['toast_msg'] = t('msg_password_updated');
      }
    }

  } catch (Throwable $e) {
    $_SESSION['toast_err'] = "Errore DB: " . $e->getMessage();
  }

  header("Location: app.php");
  exit();
}

/* =========================
   CHANGE PASSWORD (utente loggato)
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST['azione'] ?? '') === 'change_pass') {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $_SESSION['toast_err'] = t('error_csrf_req');
    header("Location: app.php");
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
    $_SESSION['toast_err'] = t('error_wrong_password');
  } elseif (strlen($new) < 6) {
    $_SESSION['toast_err'] = t('error_new_password_short');
  } elseif ($new !== $new2) {
    $_SESSION['toast_err'] = t('error_new_password_mismatch');
  } else {
    $new_hash = password_hash($new, PASSWORD_DEFAULT);
    $up = $pdo->prepare("UPDATE users SET pass_hash=? WHERE id=?");
    $up->execute([$new_hash, (int)$user['id']]);
    $_SESSION['toast_msg'] = t('msg_password_updated');
  }

  header("Location: app.php");
  exit();
}

/* =========================
   VIEW USER ID (solo admin dal pannello utenti)
========================= */
$view_user_id = null;
$view_user_info = null;
if (is_admin($user) && isset($_GET['view_user_id'])) {
  $vid = (int)$_GET['view_user_id'];
  if ($vid > 0) {
    $pdo = db();
    $st = $pdo->prepare("SELECT id, username FROM users WHERE id=? AND is_active=1 LIMIT 1");
    $st->execute([$vid]);
    $view_user_info = $st->fetch();
    if ($view_user_info) {
      $view_user_id = $vid;
    }
  }
}

/* =========================
   CRUD CONTATTI
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST['azione'] ?? '') === 'salva') {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $_SESSION['toast_err'] = t('error_csrf_req');
    header("Location: app.php");
    exit();
  }

  if (!can_manage_contacts($user)) {
    http_response_code(403);
    echo "Permesso negato.";
    exit;
  }

  $id = !empty($_POST['id']) ? (string)$_POST['id'] : uniqid("c_", true);

  // verifica ownership del contatto
  if (!empty($_POST['id'])) {
    require_contact_access($user, (string)$_POST['id'], $view_user_id);
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

  // owner: se admin in modalità "visualizza utente X" usa X, altrimenti usa proprio id
  $owner_id = (int)$user['id'];
  if ($view_user_id !== null) {
    $owner_id = $view_user_id;
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
  $redirect = $view_user_id ? "app.php?view_user_id=" . $view_user_id : "app.php";
  header("Location: " . $redirect);
  exit();
}

/* =========================
   IMPORT DA SIM (VCF / CSV)
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST['azione'] ?? '') === 'import_sim') {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $_SESSION['toast_err'] = t('error_csrf_req');
    header("Location: app.php" . ($view_user_id ? "?view_user_id=" . $view_user_id : ""));
    exit();
  }
  if (!can_manage_contacts($user)) {
    $_SESSION['toast_err'] = t('error_csrf_req');
    header("Location: app.php" . ($view_user_id ? "?view_user_id=" . $view_user_id : ""));
    exit();
  }

  $owner_id = (int)$user['id'];
  if ($view_user_id !== null) $owner_id = $view_user_id;

  $imported = 0;
  $err_msg = null;

  if (!empty($_FILES['sim_file']['tmp_name']) && is_uploaded_file($_FILES['sim_file']['tmp_name'])) {
    $tmp = $_FILES['sim_file']['tmp_name'];
    $name = strtolower((string)($_FILES['sim_file']['name'] ?? ''));
    $content = @file_get_contents($tmp);
    if ($content === false) $content = '';

    $contacts_to_import = [];
    if (substr($name, -4) === '.vcf' || strpos($content, 'BEGIN:VCARD') !== false) {
      // Parse VCF (vCard)
      $blocks = preg_split('/\nBEGIN:VCARD\n/i', "\n" . $content);
      foreach ($blocks as $card) {
        $card = trim($card);
        if ($card === '') continue;
        if (!preg_match('/^BEGIN:VCARD/i', $card)) $card = "BEGIN:VCARD\r\n" . $card;
        $card = preg_replace('/\r\n\s/', '', $card);
        $n = $fn = $tel = $email = '';
        if (preg_match('/N:([^\r\n]*)/i', $card, $m)) $n = trim($m[1]);
        if (preg_match('/FN:([^\r\n]*)/i', $card, $m)) $fn = trim($m[1]);
        if (preg_match('/TEL[^:]*:([^\r\n]*)/i', $card, $m)) $tel = preg_replace('/[^\d+]/', '', $m[1]);
        if (preg_match('/EMAIL[^:]*:([^\r\n]*)/i', $card, $m)) $email = trim($m[1]);
        if ($tel !== '' || $fn !== '' || $n !== '') {
          $nome = $cognome = '';
          if ($n) {
            $parts = array_map('trim', explode(';', $n));
            $cognome = $parts[0] ?? '';
            $nome = $parts[1] ?? '';
            if ($nome === '' && $cognome !== '') { $nome = $cognome; $cognome = ''; }
          }
          if ($nome === '') $nome = $fn;
          $nome = $nome ?: t('no_name');
          $contacts_to_import[] = [
            'nome' => $nome,
            'cognome' => $cognome,
            'telefono' => $tel ?: ' ',
            'email' => $email,
          ];
        }
      }
    } elseif (substr($name, -4) === '.csv' || strpos($content, ',') !== false || strpos($content, ';') !== false) {
      // Parse CSV (supporta virgola e punto e virgola)
      $lines = preg_split('/\r?\n/', $content);
      if (count($lines) < 2) $lines = [];
      $delim = (substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',')) ? ';' : ',';
      $header = array_map('trim', str_getcsv($lines[0] ?? '', $delim, '"'));
      $header_lower = array_map('strtolower', $header);
      $idx_name = $idx_nome = $idx_cognome = $idx_tel = $idx_phone = $idx_email = -1;
      foreach ($header_lower as $i => $h) {
        if (in_array($h, ['name', 'nome', 'display name', 'display name 1'], true)) $idx_name = $i;
        elseif (in_array($h, ['first name', 'first', 'given'], true)) $idx_nome = $i;
        elseif (in_array($h, ['last name', 'last', 'family', 'cognome'], true)) $idx_cognome = $i;
        elseif (in_array($h, ['tel', 'telefono', 'phone', 'mobile', 'cell'], true)) { $idx_tel = $i; if ($idx_phone < 0) $idx_phone = $i; }
        elseif (in_array($h, ['email', 'e-mail', 'mail'], true)) $idx_email = $i;
      }
      if ($idx_tel < 0) { foreach ($header_lower as $i => $h) { if (strpos($h, 'tel') !== false || strpos($h, 'phone') !== false) { $idx_tel = $i; break; } } }
      if ($idx_email < 0) { foreach ($header_lower as $i => $h) { if (strpos($h, 'email') !== false || strpos($h, 'mail') !== false) { $idx_email = $i; break; } } }
      for ($i = 1; $i < count($lines); $i++) {
        $row = str_getcsv($lines[$i], $delim, '"');
        $nome = trim($row[$idx_name] ?? $row[$idx_nome] ?? '');
        $cognome = trim($row[$idx_cognome] ?? '');
        $tel = preg_replace('/[^\d+]/', '', $row[$idx_tel] ?? $row[$idx_phone] ?? '');
        $email = trim($row[$idx_email] ?? '');
        if ($nome === '' && $cognome === '') $nome = trim($row[0] ?? '');
        if ($tel === '' && isset($row[1])) $tel = preg_replace('/[^\d+]/', '', $row[1]);
        if ($email === '' && isset($row[2])) $email = trim($row[2]);
        if ($tel !== '' || $nome !== '' || $email !== '') {
          $contacts_to_import[] = [
            'nome' => $nome ?: t('no_name'),
            'cognome' => $cognome,
            'telefono' => $tel ?: ' ',
            'email' => $email,
          ];
        }
      }
    } else {
      $err_msg = t('import_sim_invalid_format');
    }

    foreach ($contacts_to_import as $c) {
      $id = 'c_' . uniqid('', true);
      $contact_data = [
        'id' => $id,
        'user_id' => $owner_id,
        'nome' => $c['nome'],
        'cognome' => $c['cognome'],
        'telefono' => $c['telefono'],
        'email' => $c['email'],
        'avatar' => '',
        'preferito' => false,
      ];
      upsert_contact($contact_data);
      $imported++;
    }
  } else {
    $err_msg = t('import_sim_no_file');
  }

  $redirect = $view_user_id ? "app.php?view_user_id=" . $view_user_id : "app.php";
  if ($err_msg) {
    $_SESSION['toast_err'] = $err_msg;
  } elseif ($imported > 0) {
    $_SESSION['toast_msg'] = str_replace('{n}', (string)$imported, t('import_sim_success'));
  }
  header("Location: " . $redirect);
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

  require_contact_access($user, $id, $view_user_id);

  if ($action === 'delete') {
    $avatar = delete_contact($id);
    $av = safe_path_inside_uploads($avatar ?? "", $upload_url);
    if (!empty($av) && file_exists(__DIR__ . "/" . $av)) @unlink(__DIR__ . "/" . $av);
  } elseif ($action === 'toggle_fav') {
    toggle_fav($id);
  }

  $redirect = $view_user_id ? "app.php?view_user_id=" . $view_user_id : "app.php";
  header("Location: " . $redirect);
  exit();
}

/* =========================
   DATA FOR UI
========================= */
$contacts = fetch_contacts($user, $view_user_id);
$contacts_json = json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$users = is_admin($user) ? fetch_users() : [];
$users_json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="<?= htmlspecialchars($CURRENT_LANG) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title><?= t('page_contacts') ?></title>
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
    .profileDropdown{ position:relative; }
    .profileBtn{
      width: 42px; height: 42px;
      border-radius: 50%;
      border: 1px solid rgba(255,255,255,.2);
      background: linear-gradient(135deg, rgba(125,211,252,.5), rgba(167,139,250,.5));
      color: var(--text);
      font-size: 20px;
      cursor: pointer;
      display: grid; place-items: center;
      transition: .18s ease;
      backdrop-filter: blur(12px);
      box-shadow: 0 12px 30px rgba(0,0,0,.22);
    }
    .profileBtn:hover{ transform: scale(1.05); background: linear-gradient(135deg, rgba(125,211,252,.7), rgba(167,139,250,.7)); }
    .profileMenu{
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      min-width: 200px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.18);
      background: rgba(12,20,40,.95);
      backdrop-filter: blur(20px);
      box-shadow: 0 24px 60px rgba(0,0,0,.5);
      padding: 8px;
      display: none;
      z-index: 100;
    }
    .profileMenu.open{ display: block; animation: dropdownPop .2s ease; }
    @keyframes dropdownPop{ from{ opacity:0; transform: translateY(-8px); } to{ opacity:1; transform: translateY(0); } }
    .profileMenu a, .profileMenu button{
      display: flex; align-items: center; gap: 12px;
      width: 100%;
      padding: 12px 14px;
      border: none;
      border-radius: 14px;
      background: transparent;
      color: var(--text);
      font-size: 14px;
      text-align: left;
      cursor: pointer;
      text-decoration: none;
      transition: .15s ease;
    }
    .profileMenu a:hover, .profileMenu button:hover{
      background: rgba(255,255,255,.1);
    }
    .profileMenu .ico{ font-size: 18px; opacity: .9; }
    .langItem{display:flex;align-items:center;padding:12px 14px;border-radius:14px;text-decoration:none;color:rgba(255,255,255,.9);font-weight:600;transition:.15s ease;margin-bottom:6px;}
    .langItem:hover{background:rgba(255,255,255,.1)}
    .langItem.active{background:rgba(125,211,252,.2);border:1px solid rgba(125,211,252,.4);}
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
    .usersToolbar{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-bottom:10px;
    }
    .usersSearch{
      flex:1 1 200px;
      display:flex;
      align-items:center;
      gap:8px;
      padding:9px 11px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(0,0,0,.16);
    }
    .usersSearch span{ opacity:.9; }
    .usersSearch input{
      width:100%;
      border:none;
      outline:none;
      background:transparent;
      color:var(--text);
      font-size:13px;
    }
    .usersSearch input::placeholder{ color:rgba(255,255,255,.55); }
    .usersFilter{
      min-width:140px;
      padding:9px 11px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(0,0,0,.16);
      color:var(--text);
      font-size:13px;
      outline:none;
    }
    .userFilterChip{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:8px 10px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.16);
      background:rgba(255,255,255,.06);
      color:var(--muted);
      font-size:12px;
    }
    .fab{
      position: fixed;
      bottom: max(24px, env(safe-area-inset-bottom));
      right: max(20px, env(safe-area-inset-right));
      width: min(72px, 18vw);
      height: min(72px, 18vw);
      min-width: 60px;
      min-height: 60px;
      border-radius: 50%;
      border: none;
      background: linear-gradient(135deg, rgba(125,211,252,.95), rgba(167,139,250,.90));
      color: rgba(0,0,0,.88);
      font-size: 32px;
      cursor: pointer;
      display: grid;
      place-items: center;
      box-shadow: 0 12px 40px rgba(0,0,0,.4), 0 0 0 1px rgba(255,255,255,.15);
      transition: .2s ease;
      z-index: 90;
    }
    .fab:hover{
      transform: scale(1.08);
      box-shadow: 0 16px 50px rgba(0,0,0,.45);
    }
    .fab:active{ transform: scale(0.98); }
    .fab.hidden{ opacity: 0; pointer-events: none; transform: scale(0.8); }
  </style>
</head>
<body>

  <div class="topbar">
    <div class="topbar-inner">
      <div class="title">
        <div class="appdot"></div>
        <div style="min-width:0">
          <h1><?= t('page_contacts') ?></h1>
          <div class="subtitle">
            <?php if ($view_user_id && $view_user_info): ?>
              <?= t('viewing_contacts_of') ?> <strong><?= h($view_user_info['username']) ?></strong>
              <a href="app.php" style="margin-left:8px;color:rgba(125,211,252,.9);"><?= t('back_to_my_contacts') ?></a>
            <?php else: ?>
              <?= h($user['username']) ?><?php if (is_admin($user)): ?> (<?= h($user['role']) ?>)<?php endif; ?> · <?= count($contacts) ?> <?= t('contacts_subtitle') ?>
              · <?= t('contacts_yours_only') ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="actions">
        <div class="profileDropdown">
          <button type="button" class="profileBtn" onclick="toggleProfileMenu()" aria-label="Menu profilo" aria-expanded="false" aria-haspopup="true" id="profileBtn">👤</button>
          <div class="profileMenu" id="profileMenu" role="menu">
            <button type="button" role="menuitem" onclick="closeProfileMenu(); openLanguage();">
              <span class="ico">🌐</span><?= t('label_language') ?>
            </button>
            <button type="button" role="menuitem" onclick="closeProfileMenu(); openImportSIM();">
              <span class="ico">📲</span><?= t('btn_import_from_sim') ?>
            </button>
            <button type="button" role="menuitem" onclick="closeProfileMenu(); openExport();">
              <span class="ico">📤</span><?= t('btn_export') ?>
            </button>
            <button type="button" role="menuitem" onclick="closeProfileMenu(); openPass();">
              <span class="ico">🔒</span><?= t('btn_change_password') ?>
            </button>
            <?php if (is_admin($user)): ?>
              <button type="button" role="menuitem" onclick="closeProfileMenu(); openUsersAdmin();">
                <span class="ico">👥</span><?= t('btn_users_admin') ?>
              </button>
            <?php endif; ?>
            <a href="app.php?logout=1" role="menuitem">
              <span class="ico">🚪</span><?= t('btn_logout') ?>
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="toolbar">
      <div class="search">
        <span>🔎</span>
        <input id="q" type="search" placeholder="<?= t('search_placeholder') ?>" autocomplete="off" />
      </div>

      <div style="display:flex;align-items:center;gap:10px;flex:0 0 auto;">
        <div class="tabs" role="tablist" aria-label="Filtro contatti">
          <button class="tab active" id="tabAll" onclick="setTab('all')" type="button" data-t="tab_all"><?= t('tab_all') ?></button>
          <button class="tab" id="tabFav" onclick="setTab('fav')" type="button" data-t="tab_favorites"><?= t('tab_favorites') ?></button>
        </div>
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
      <?= t('empty_contacts') ?>
    </div>
  </div>

  <!-- DETAIL OVERLAY -->
  <div id="viewOverlay" class="overlay" aria-hidden="true">
    <div class="overlayTop">
      <div class="overlayTopInner">
        <button class="iconbtn" onclick="closeView()" aria-label="<?= t('btn_back') ?>">←</button>
        <div class="overlayBtns">
          <button class="iconbtn" id="btnEdit" aria-label="<?= t('btn_edit') ?>" title="<?= t('btn_edit') ?>">✎</button>
          <button class="iconbtn" id="btnStar" aria-label="<?= t('btn_favorite') ?>" title="<?= t('btn_favorite') ?>">☆</button>
          <button class="iconbtn" id="btnDelete" aria-label="<?= t('btn_delete') ?>" title="<?= t('btn_delete') ?>">🗑️</button>
          <button class="iconbtn" onclick="closeView()" aria-label="<?= t('btn_close') ?>" title="<?= t('btn_close') ?>">✕</button>
        </div>
      </div>
    </div>

    <div class="detail">
      <div class="hero">
        <div id="v_avatar" class="bigAvatar"></div>
        <p id="v_nome" class="bigName"></p>
      </div>

      <div class="card">
        <div class="cardHeader"><?= t('details_contact') ?></div>

        <div class="row">
          <div class="ico">📞</div>
          <div class="rowMain">
            <div class="rowLabel"><?= t('label_phone') ?></div>
            <div id="v_tel" class="rowValue"></div>
          </div>
        </div>

        <div class="row">
          <div class="ico">📧</div>
          <div class="rowMain">
            <div class="rowLabel"><?= t('label_email') ?></div>
            <div id="v_email" class="rowValue"></div>
          </div>
        </div>

        <div class="quick">
          <a id="callBtn" class="pill" href="#" onclick="return false;">📞 <?= t('btn_call') ?></a>
          <a id="mailBtn" class="pill" href="#" onclick="return false;">✉️ <?= t('btn_email') ?></a>
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
          <p id="etitle" class="sheetTitle"><?= t('sheet_create_contact') ?></p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;">
            <?= t('sheet_save_info') ?><?= ($view_user_id && $view_user_info) ? h($view_user_info['username']) : t('sheet_your_contacts') ?>
          </div>
        </div>
        <button class="iconbtn" onclick="closeEdit()" aria-label="<?= t('btn_close') ?>">✕</button>
      </div>

      <form action="app.php<?= $view_user_id ? '?view_user_id='.$view_user_id : '' ?>" method="POST" enctype="multipart/form-data">
        <div class="sheetBody">
          <input type="hidden" name="azione" value="salva">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="id" id="e_id">
          <input type="hidden" name="old_avatar" id="e_old_avatar">
          <input type="hidden" name="preferito" id="e_preferito">

          <div class="grid">
            <div class="f">
              <label for="e_nome"><?= t('label_name') ?> *</label>
              <input type="text" name="nome" id="e_nome" placeholder="<?= t('placeholder_name') ?>" required>
            </div>
            <div class="f">
              <label for="e_cognome"><?= t('label_surname') ?></label>
              <input type="text" name="cognome" id="e_cognome" placeholder="<?= t('placeholder_surname') ?>">
            </div>
            <div class="f">
              <label for="e_tel"><?= t('label_phone') ?> *</label>
              <input type="tel" name="telefono" id="e_tel" placeholder="<?= t('placeholder_phone') ?>" required>
            </div>
            <div class="f">
              <label for="e_email"><?= t('label_email') ?></label>
              <input type="email" name="email" id="e_email" placeholder="<?= t('placeholder_email') ?>">
            </div>
            <div class="f">
              <label for="e_avatar"><?= t('label_avatar') ?></label>
              <input id="e_avatar" type="file" name="avatar" accept="image/*">
            </div>
          </div>
        </div>

        <div class="sheetActions">
          <button type="button" class="btn btnGhost" onclick="closeEdit()"><?= t('btn_cancel') ?></button>
          <button type="submit" class="btn btnPrimary"><?= t('btn_save') ?></button>
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
          <p class="sheetTitle"><?= t('sheet_change_password') ?></p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;">
            <?= t('label_user') ?>: <b><?= h($user['username']) ?></b>
          </div>
        </div>
        <button class="iconbtn" onclick="closePass()" aria-label="Chiudi">✕</button>
      </div>

      <form action="app.php" method="POST" autocomplete="off">
        <div class="sheetBody">
          <input type="hidden" name="azione" value="change_pass">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

          <div class="grid">
            <div class="f">
              <label for="old_pass"><?= t('label_current_password') ?></label>
              <input id="old_pass" type="password" name="old_pass" required>
            </div>

            <div class="f">
              <label for="new_pass"><?= t('label_new_password') ?></label>
              <input id="new_pass" type="password" name="new_pass" required>
            </div>

            <div class="f">
              <label for="new_pass2"><?= t('label_repeat_new_password') ?></label>
              <input id="new_pass2" type="password" name="new_pass2" required>
            </div>
          </div>
        </div>

        <div class="sheetActions">
          <button type="button" class="btn btnGhost" onclick="closePass()"><?= t('btn_cancel') ?></button>
          <button type="submit" class="btn btnPrimary"><?= t('btn_update') ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- LINGUA SHEET -->
  <div id="langSheetWrap" class="sheetWrap" onclick="langSheetBackdropClose(event)">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="<?= t('sheet_language') ?>">
      <div class="handle"></div>
      <div class="sheetTop">
        <p class="sheetTitle"><?= t('sheet_language') ?></p>
        <button class="iconbtn" onclick="closeLanguage()" aria-label="<?= t('btn_close') ?>">✕</button>
      </div>
      <div class="sheetBody">
        <?php foreach ($AVAILABLE_LANGS as $code => $label): ?>
          <a href="app.php?lang=<?= $code ?><?= $view_user_id ? '&view_user_id='.$view_user_id : '' ?>" class="langItem <?= $code === $CURRENT_LANG ? 'active' : '' ?>" hreflang="<?= $code ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- IMPORT DA SIM SHEET -->
  <div id="importSIMSheetWrap" class="sheetWrap" onclick="importSIMBackdropClose(event)">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="<?= t('sheet_import_sim') ?>">
      <div class="handle"></div>
      <div class="sheetTop">
        <div>
          <p class="sheetTitle"><?= t('sheet_import_sim') ?></p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;">
            <?= t('import_sim_formats') ?>
            <ul style="margin:6px 0 0 14px; padding:0;">
              <li><strong>.VCF</strong> (vCard) — <?= t('format_vcf_desc') ?></li>
              <li><strong>.CSV</strong> — <?= t('format_csv_desc') ?></li>
            </ul>
          </div>
        </div>
        <button class="iconbtn" onclick="closeImportSIM()" aria-label="<?= t('btn_close') ?>">✕</button>
      </div>

      <form action="app.php<?= $view_user_id ? '?view_user_id='.$view_user_id : '' ?>" method="POST" enctype="multipart/form-data">
        <div class="sheetBody">
          <input type="hidden" name="azione" value="import_sim">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

          <div class="f">
            <label for="import_sim_file"><?= t('import_sim_select_file') ?></label>
            <input id="import_sim_file" type="file" name="sim_file" accept=".vcf,.csv,text/vcard,text/csv,text/x-vcard,application/vnd.ms-excel" required>
          </div>
        </div>

        <div class="sheetActions">
          <button type="button" class="btn btnGhost" onclick="closeImportSIM()"><?= t('btn_cancel') ?></button>
          <button type="submit" class="btn btnPrimary"><?= t('btn_import') ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- EXPORT SHEET -->
  <div id="exportSheetWrap" class="sheetWrap" onclick="exportBackdropClose(event)">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="<?= t('sheet_export') ?>">
      <div class="handle"></div>
      <div class="sheetTop">
        <div>
          <p class="sheetTitle"><?= t('sheet_export') ?></p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;">
            <?= t('export_formats_desc') ?>
            <ul style="margin:6px 0 0 14px; padding:0;">
              <li><strong>.VCF</strong> (vCard) — <?= t('format_vcf_desc') ?></li>
              <li><strong>.CSV</strong> — <?= t('format_csv_desc') ?></li>
            </ul>
          </div>
        </div>
        <button class="iconbtn" onclick="closeExport()" aria-label="<?= t('btn_close') ?>">✕</button>
      </div>
      <div class="sheetBody">
        <div class="grid" style="gap:12px;">
          <button type="button" class="btn btnPrimary" style="width:100%;display:flex;align-items:center;justify-content:center;gap:10px;" onclick="downloadExport('vcf')">
            <span>📇</span><?= t('btn_export_vcf') ?>
          </button>
          <button type="button" class="btn btnPrimary" style="width:100%;display:flex;align-items:center;justify-content:center;gap:10px;" onclick="downloadExport('csv')">
            <span>📊</span><?= t('btn_export_csv') ?>
          </button>
        </div>
      </div>
      <div class="sheetActions">
        <button type="button" class="btn btnGhost" onclick="closeExport()"><?= t('btn_cancel') ?></button>
      </div>
    </div>
  </div>

  <!-- USERS ADMIN SHEET -->
  <?php if (is_admin($user)): ?>
  <div id="usersSheetWrap" class="sheetWrap" onclick="usersBackdropClose(event)">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="Gestione utenti">
      <div class="handle"></div>
      <div class="sheetTop">
        <div>
          <p class="sheetTitle"><?= t('sheet_users_admin') ?></p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;">
            <?= t('sheet_users_admin_sub') ?>
          </div>
        </div>
        <button class="iconbtn" onclick="closeUsersAdmin()" aria-label="Chiudi">✕</button>
      </div>

      <div class="sheetBody">
        <div class="usersToolbar">
          <div class="usersSearch">
            <span>🔎</span>
            <input id="usersSearch" type="search" placeholder="<?= t('search_users_placeholder') ?>" autocomplete="off" />
          </div>
          <select id="usersFilterRole" class="usersFilter">
            <option value="all"><?= t('filter_all_roles') ?></option>
            <option value="admin"><?= t('filter_admin_only') ?></option>
            <option value="user"><?= t('filter_user_only') ?></option>
          </select>
          <select id="usersFilterActive" class="usersFilter">
            <option value="all"><?= t('filter_active_inactive') ?></option>
            <option value="active"><?= t('filter_active_only') ?></option>
            <option value="inactive"><?= t('filter_inactive_only') ?></option>
          </select>
        </div>

        <div class="card">
          <div class="cardHeader"><?= t('card_all_users') ?></div>
          <div id="usersList" style="padding:10px 10px 14px;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Cambia password (admin per altro utente) -->
  <div id="adminPassSheetWrap" class="sheetWrap" style="display:none" onclick="adminPassBackdropClose(event)">
    <div class="sheet" role="dialog" aria-modal="true" aria-label="Cambia password utente">
      <div class="sheetTop">
        <div>
          <p class="sheetTitle">Cambia password</p>
          <div style="color:rgba(255,255,255,.55); font-size:12px; margin-top:4px;" id="adminPassSubtitle"></div>
        </div>
        <button class="iconbtn" onclick="closeAdminPass()" aria-label="Chiudi">✕</button>
      </div>

      <div class="sheetBody">
        <form action="app.php" method="POST" autocomplete="off" class="card" style="margin-top:0; padding:14px 16px 16px;">
          <input type="hidden" name="azione" value="admin_set_password">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="uid" id="adminPassUid" value="">

          <div class="grid">
            <div class="f">
              <label>Nuova password *</label>
              <input type="password" name="new_pass" id="adminNewPass" required minlength="6" placeholder="Min 6 caratteri">
            </div>
            <div class="f">
              <label>Ripeti password *</label>
              <input type="password" name="new_pass2" id="adminNewPass2" required minlength="6">
            </div>
          </div>

          <div class="sheetActions" style="padding:12px 0 0;border-top:none;">
            <button type="button" class="btn btnGhost" onclick="closeAdminPass()">Annulla</button>
            <button type="submit" class="btn btnPrimary">Salva</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <button type="button" class="fab" id="fabAdd" onclick="openEdit(null)" aria-label="<?= t('btn_new_contact') ?>" title="<?= t('btn_new_contact') ?>">➕</button>

<script>
  const ALL_CONTACTS = <?= $contacts_json ?: "[]" ?>;
  const IS_ADMIN = <?= is_admin($user) ? 'true' : 'false' ?>;
  const VIEW_USER_ID = <?= $view_user_id ? (int)$view_user_id : 'null' ?>;
  const T = <?= json_encode([
    'no_name' => t('no_name'),
    'export_empty' => t('export_empty'),
    'section_favorites' => t('section_favorites'),
    'section_contacts' => t('section_contacts'),
    'sheet_create_contact' => t('sheet_create_contact'),
    'sheet_edit_contact' => t('sheet_edit_contact'),
    'confirm_delete_contact' => t('confirm_delete_contact'),
    'confirm_delete_user' => t('confirm_delete_user'),
    'empty_users' => t('empty_users'),
    'status_active' => t('status_active'),
    'status_inactive' => t('status_inactive'),
  ], JSON_UNESCAPED_UNICODE) ?>;

  let currentTab = "all";
  let currentQuery = "";
  let viewing = null;

  const $ = (id) => document.getElementById(id);
  function normalize(s){ return (s||"").toString().toLowerCase().trim(); }

  function toggleProfileMenu(){
    const m = $("profileMenu");
    const b = $("profileBtn");
    if (!m || !b) return;
    m.classList.toggle("open");
    b.setAttribute("aria-expanded", m.classList.contains("open"));
  }
  function closeProfileMenu(){
    const m = $("profileMenu");
    const b = $("profileBtn");
    if (m) m.classList.remove("open");
    if (b) b.setAttribute("aria-expanded", "false");
  }
  document.addEventListener("click", (e) => {
    if ($("profileMenu")?.classList.contains("open") && !e.target.closest(".profileDropdown")) {
      closeProfileMenu();
    }
  });

  function contactMatches(c, q){
    if (!q) return true;
    const hay = [c.nome, c.cognome, c.telefono, c.email].map(normalize).join(" ");
    return hay.includes(q);
  }

  function baseUrl(){ return VIEW_USER_ID ? "app.php?view_user_id=" + VIEW_USER_ID : "app.php"; }

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
      if (grouped.fav.length) sections.push({ label: T.section_favorites, items: grouped.fav });
      if (grouped.oth.length) sections.push({ label: T.section_contacts, items: grouped.oth });
    } else {
      sections.push({ label: T.section_favorites, items: grouped.fav });
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
        nm.textContent = `${c.nome || ""} ${c.cognome || ""}`.trim() || T.no_name;

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

    $("v_nome").textContent = `${c.nome || ""} ${c.cognome || ""}`.trim() || T.no_name;
    $("v_tel").textContent = c.telefono || "—";
    $("v_email").textContent = c.email || "—";

    const av = $("v_avatar");
    av.style.background = `linear-gradient(135deg, ${avatarColor(c.id, 0.95)}, ${avatarColor2(c.id, 0.9)})`;
    av.innerHTML = c.avatar ? `<img src="${c.avatar}" alt="avatar">` : (c.nome||"?").charAt(0).toUpperCase();

    $("btnEdit").onclick = () => openEdit(c);
    $("btnStar").textContent = c.preferito ? "★" : "☆";
    $("btnStar").onclick = () => window.location.href = baseUrl() + (VIEW_USER_ID ? "&" : "?") + "action=toggle_fav&id=" + encodeURIComponent(c.id);
    $("btnDelete").onclick = () => {
      if (confirm(T.confirm_delete_contact)) {
        window.location.href = baseUrl() + (VIEW_USER_ID ? "&" : "?") + "action=delete&id=" + encodeURIComponent(c.id);
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
    $("fabAdd")?.classList.add("hidden");
  }
  function closeView(){
    $("viewOverlay").style.display = "none";
    $("viewOverlay").setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
    $("fabAdd")?.classList.remove("hidden");
  }

  // ====== EDIT SHEET ======
  function openEdit(c=null){
    closeView();
    $("fabAdd")?.classList.add("hidden");

    if (c) {
      $("etitle").textContent = T.sheet_edit_contact;
      $("e_id").value = c.id || "";
      $("e_nome").value = c.nome || "";
      $("e_cognome").value = c.cognome || "";
      $("e_tel").value = c.telefono || "";
      $("e_email").value = c.email || "";
      $("e_old_avatar").value = c.avatar || "";
      $("e_preferito").value = c.preferito ? "1" : "0";
    } else {
      $("etitle").textContent = T.sheet_create_contact;
      document.querySelector("#editSheetWrap form").reset();
      $("e_id").value = "";
      $("e_old_avatar").value = "";
      $("e_preferito").value = "0";
    }

    $("editSheetWrap").style.display = "flex";
    document.body.style.overflow = "hidden";
    setTimeout(() => $("e_nome").focus(), 50);
  }

  function closeEdit(){
    $("editSheetWrap").style.display = "none";
    document.body.style.overflow = "";
    $("fabAdd")?.classList.remove("hidden");
  }

  function sheetBackdropClose(e){
    if (e.target && e.target.id === "editSheetWrap") closeEdit();
  }

  // ====== PASS SHEET ======
  function openPass(){
    closeView();
    $("fabAdd")?.classList.add("hidden");
    $("passSheetWrap").style.display = "flex";
    document.body.style.overflow = "hidden";
    setTimeout(() => $("old_pass").focus(), 50);
  }
  function closePass(){
    $("passSheetWrap").style.display = "none";
    document.body.style.overflow = "";
    $("fabAdd")?.classList.remove("hidden");
  }
  function passBackdropClose(e){
    if (e.target && e.target.id === "passSheetWrap") closePass();
  }

  function openLanguage(){
    closeView();
    $("fabAdd")?.classList.add("hidden");
    $("langSheetWrap").style.display = "flex";
    document.body.style.overflow = "hidden";
  }
  function closeLanguage(){
    $("langSheetWrap").style.display = "none";
    document.body.style.overflow = "";
    $("fabAdd")?.classList.remove("hidden");
  }
  function langSheetBackdropClose(e){
    if (e.target && e.target.id === "langSheetWrap") closeLanguage();
  }

  function openImportSIM(){
    closeView();
    $("fabAdd")?.classList.add("hidden");
    $("importSIMSheetWrap").style.display = "flex";
    document.body.style.overflow = "hidden";
  }
  function closeImportSIM(){
    $("importSIMSheetWrap").style.display = "none";
    document.body.style.overflow = "";
    $("fabAdd")?.classList.remove("hidden");
    $("import_sim_file").value = "";
  }
  function importSIMBackdropClose(e){
    if (e.target && e.target.id === "importSIMSheetWrap") closeImportSIM();
  }

  function openExport(){
    closeView();
    $("fabAdd")?.classList.add("hidden");
    $("exportSheetWrap").style.display = "flex";
    document.body.style.overflow = "hidden";
  }
  function closeExport(){
    $("exportSheetWrap").style.display = "none";
    document.body.style.overflow = "";
    $("fabAdd")?.classList.remove("hidden");
  }
  function exportBackdropClose(e){
    if (e.target && e.target.id === "exportSheetWrap") closeExport();
  }

  function downloadExport(format){
    const list = filteredContacts();
    if (!list.length) {
      alert(T.export_empty || "Nessun contatto da esportare.");
      return;
    }
    const filename = "contatti_" + new Date().toISOString().slice(0,10) + "." + format;
    if (format === "vcf") {
      let vcf = "";
      for (const c of list) {
        const fn = `${c.nome || ""} ${c.cognome || ""}`.trim() || T.no_name;
        const n = `${c.cognome || ""};${c.nome || ""};;;`;
        vcf += "BEGIN:VCARD\r\nVERSION:3.0\r\n";
        vcf += "N:" + n + "\r\n";
        vcf += "FN:" + fn + "\r\n";
        if (c.telefono) vcf += "TEL;TYPE=CELL:" + c.telefono.replace(/[^\d+]/g, "") + "\r\n";
        if (c.email) vcf += "EMAIL:" + c.email + "\r\n";
        vcf += "END:VCARD\r\n";
      }
      downloadBlob(vcf, filename, "text/vcard");
    } else {
      const BOM = "\uFEFF";
      let csv = BOM + "Nome;Cognome;Telefono;Email\r\n";
      for (const c of list) {
        const nome = escapeCsv(c.nome || "");
        const cognome = escapeCsv(c.cognome || "");
        const tel = escapeCsv(c.telefono || "");
        const email = escapeCsv(c.email || "");
        csv += nome + ";" + cognome + ";" + tel + ";" + email + "\r\n";
      }
      downloadBlob(csv, filename, "text/csv;charset=utf-8");
    }
  }
  function escapeCsv(s){
    const str = String(s ?? "");
    if (str.includes(";") || str.includes('"') || str.includes("\n") || str.includes("\r")) {
      return '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
  }
  function downloadBlob(content, filename, mime){
    const blob = new Blob([content], { type: mime });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
  }

  // ====== USERS ADMIN ======
  <?php if (is_admin($user)): ?>
    const ALL_USERS = <?= $users_json ?: "[]" ?>;
    const CSRF = <?= json_encode($CSRF) ?>;
    const MY_UID = <?= (int)$user['id'] ?>;
    let usersQuery = "";
    let usersFilterRole = "all";
    let usersFilterActive = "all";

    function openUsersAdmin(){
      closeView();
      $("fabAdd")?.classList.add("hidden");
      renderUsersAdmin();
      $("usersSheetWrap").style.display = "flex";
      document.body.style.overflow = "hidden";
    }
    function closeUsersAdmin(){
      $("usersSheetWrap").style.display = "none";
      document.body.style.overflow = "";
      $("fabAdd")?.classList.remove("hidden");
    }
    function usersBackdropClose(e){
      if (e.target && e.target.id === "usersSheetWrap") closeUsersAdmin();
    }

    const usersSearchInput = $("usersSearch");
    const usersFilterRoleEl = $("usersFilterRole");
    const usersFilterActiveEl = $("usersFilterActive");

    if (usersSearchInput){
      usersSearchInput.addEventListener("input", (e) => {
        usersQuery = normalize(e.target.value);
        renderUsersAdmin();
      });
    }
    if (usersFilterRoleEl){
      usersFilterRoleEl.addEventListener("change", (e) => {
        usersFilterRole = e.target.value || "all";
        renderUsersAdmin();
      });
    }
    if (usersFilterActiveEl){
      usersFilterActiveEl.addEventListener("change", (e) => {
        usersFilterActive = e.target.value || "all";
        renderUsersAdmin();
      });
    }

    function postAdmin(action, payload){
      const f = document.createElement("form");
      f.method = "POST";
      f.action = "app.php";
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

    // ====== ADMIN: cambia password altro utente ======
    function openAdminPass(u){
      if (!u) return;
      $("adminPassUid").value = String(u.id);
      $("adminPassSubtitle").textContent = `Utente: ${u.username} · ID: ${u.id}`;
      $("adminNewPass").value = "";
      $("adminNewPass2").value = "";
      $("adminPassSheetWrap").style.display = "flex";
      document.body.style.overflow = "hidden";
      setTimeout(() => $("adminNewPass").focus(), 50);
    }
    function closeAdminPass(){
      $("adminPassSheetWrap").style.display = "none";
      document.body.style.overflow = "";
    }
    function adminPassBackdropClose(e){
      if (e.target && e.target.id === "adminPassSheetWrap") closeAdminPass();
    }

    function renderUsersAdmin(){
      const box = $("usersList");
      box.innerHTML = "";

      const list = ALL_USERS.filter(u => {
        const role = String(u.role || "").toLowerCase();
        if (usersFilterRole === "admin" && role !== "admin") return false;
        if (usersFilterRole === "user" && role !== "user") return false;
        if (usersFilterActive === "active" && !u.is_active) return false;
        if (usersFilterActive === "inactive" && u.is_active) return false;
        if (usersQuery) {
          const hay = `${u.username} ${u.id} ${u.role}`.toLowerCase();
          if (!hay.includes(usersQuery)) return false;
        }
        return true;
      });

      if (!list.length){
        const empty = document.createElement("div");
        empty.style.padding = "10px 12px";
        empty.style.color = "rgba(255,255,255,.75)";
        empty.style.fontSize = "13px";
        empty.textContent = T.empty_users;
        box.appendChild(empty);
        return;
      }

      const iconBtn = (icon, label, cls, onClick, disabled) => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = cls || "btn btnGhost";
        b.style.padding = "8px 10px";
        b.style.fontSize = "16px";
        b.style.lineHeight = "1";
        b.innerHTML = icon;
        b.setAttribute("aria-label", label);
        b.title = label;
        b.disabled = disabled;
        b.onclick = onClick;
        return b;
      };

      for (const u of list) {
        const row = document.createElement("div");
        row.className = "row";
        row.style.alignItems = "center";
        row.style.display = "flex";
        row.style.gap = "12px";
        row.style.padding = "12px 10px";
        row.style.borderBottom = "1px solid rgba(255,255,255,.08)";
        row.style.flexWrap = "wrap";

        const left = document.createElement("div");
        left.className = "rowMain";
        left.style.flex = "1 1 180px";
        left.style.minWidth = "0";

        const val = document.createElement("div");
        val.className = "rowValue";
        val.style.fontSize = "15px";
        val.style.fontWeight = "600";
        val.style.marginBottom = "4px";
        const roleShown = (String(u.role).toLowerCase() === 'admin') ? 'Admin' : 'User';
        val.textContent = u.username;

        const label = document.createElement("div");
        label.className = "rowLabel";
        label.style.fontSize = "12px";
        label.style.color = "rgba(255,255,255,.55)";
        label.textContent = `ID: ${u.id} · ${roleShown} · ${u.is_active ? T.status_active : T.status_inactive}`;

        left.appendChild(val);
        left.appendChild(label);

        const acts = document.createElement("div");
        acts.style.display = "flex";
        acts.style.gap = "6px";
        acts.style.flex = "0 0 auto";
        acts.style.flexWrap = "wrap";

        const isMe = (parseInt(u.id) === MY_UID);
        const isSupreme = String(u.username || "").toLowerCase() === "admin";

        const nextRole = (String(u.role).toLowerCase() === "user") ? "admin" : "user";
        const roleBtn = iconBtn(
          nextRole === 'admin' ? '👑' : '👤',
          nextRole === 'admin' ? 'Imposta admin' : 'Imposta user',
          "btn btnGhost",
          () => postAdmin("admin_set_role", { uid: u.id, role: nextRole }),
          isMe || isSupreme
        );

        const actBtn = iconBtn(
          u.is_active ? '⏸' : '▶',
          u.is_active ? 'Disattiva' : 'Attiva',
          "btn btnGhost",
          () => postAdmin("admin_toggle_active", { uid: u.id, active: u.is_active ? 0 : 1 }),
          isMe || isSupreme
        );

        const passBtn = iconBtn(
          '🔒',
          'Cambia password',
          "btn btnGhost",
          () => openAdminPass(u),
          isMe || isSupreme
        );

        const delBtn = iconBtn(
          '🗑',
          'Elimina',
          "btn btnDanger",
          () => {
            if (confirm(T.confirm_delete_user + u.username + '"?')) {
              postAdmin("admin_delete_user", { uid: u.id });
            }
          },
          isMe || isSupreme
        );

        const contactsBtn = iconBtn(
          '📋',
          'Visualizza contatti',
          "btn btnPrimary",
          () => viewUserContacts(u),
          false
        );

        acts.appendChild(contactsBtn);
        acts.appendChild(roleBtn);
        acts.appendChild(actBtn);
        acts.appendChild(passBtn);
        acts.appendChild(delBtn);

        row.appendChild(left);
        row.appendChild(acts);

        box.appendChild(row);
      }
    }

    function viewUserContacts(u){
      if (!u) return;
      closeUsersAdmin();
      window.location.href = "app.php?view_user_id=" + u.id;
    }
  <?php endif; ?>

  window.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      if ($("profileMenu")?.classList.contains("open")) closeProfileMenu();
      else if ($("langSheetWrap")?.style.display === "flex") closeLanguage();
      else if ($("usersSheetWrap")?.style.display === "flex") closeUsersAdmin?.();
      else if ($("adminPassSheetWrap")?.style.display === "flex") closeAdminPass?.();
      else if ($("passSheetWrap")?.style.display === "flex") closePass();
      else if ($("importSIMSheetWrap")?.style.display === "flex") closeImportSIM();
      else if ($("exportSheetWrap")?.style.display === "flex") closeExport();
      else if ($("editSheetWrap")?.style.display === "flex") closeEdit();
      else if ($("viewOverlay")?.style.display === "flex") closeView();
    }
  });

  render();
</script>

</body>
</html>

