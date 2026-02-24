<?php
require __DIR__ . '/config.php';

// Se già loggato vai direttamente all'app
if (current_user()) {
  header("Location: app.php");
  exit();
}

$toast_msg = $_SESSION['toast_msg'] ?? null;
$toast_err = $_SESSION['toast_err'] ?? null;
unset($_SESSION['toast_msg'], $_SESSION['toast_err']);

$saved_username = $_SESSION['saved_username'] ?? '';
$save_username_checked = !empty($saved_username);

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
    if (!empty($_POST['save_username'])) {
      $_SESSION['saved_username'] = $usern;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    header("Location: app.php");
    exit();
  } else {
    if (!empty($_POST['save_username']) && $usern !== '') {
      $_SESSION['saved_username'] = $usern;
    }
    $error = t('error_invalid_credentials');
    $save_username_checked = !empty($_POST['save_username']);
    $saved_username = $usern;
  }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($CURRENT_LANG) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title><?= t('login_title') ?></title>
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
    .checkRow{margin-top:10px;display:flex;align-items:center;gap:10px;cursor:pointer;}
    .checkRow input[type="checkbox"]{width:18px;height:18px;accent-color:rgba(125,211,252,.9);cursor:pointer;}
    .checkRow span{color:var(--muted);font-size:13px;user-select:none;}
    .btnGhost{
      margin-top: 10px;
      width:100%;
      display:block;
      text-align:center;
      text-decoration:none;
      padding: 13px 14px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.90);
      font-weight: 750;
      transition: .2s ease;
      box-shadow: 0 18px 40px rgba(0,0,0,.18);
    }
    .btnGhost:hover{ transform: translateY(-1px); background: rgba(255,255,255,.08) }
    .btnGhost:active{ transform: translateY(1px); background: rgba(255,255,255,.05) }

    .alerts{ margin-top: 12px; }
    .msg{
      margin: 0 0 10px; padding: 12px 14px; border-radius: 16px;
      border: 1px solid rgba(52,211,153,.35);
      background: rgba(52,211,153,.12);
      color: rgba(255,255,255,.92); font-size: 15.5px; line-height: 1.35;
    }
    .error{
      margin: 0 0 10px; padding: 12px 14px; border-radius: 16px;
      border: 1px solid rgba(251,113,133,.35);
      background: rgba(251,113,133,.12);
      color: rgba(255,255,255,.92); font-size: 15.5px; line-height: 1.35;
    }
    .alerts > :last-child{ margin-bottom: 0; }
    .foot{margin-top: 14px; color: rgba(255,255,255,.55); font-size: 12px; text-align:center;}
    .btnLang{position:fixed;top:16px;right:16px;z-index:100;padding:8px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);color:rgba(255,255,255,.9);text-decoration:none;font-size:13px;font-weight:600;cursor:pointer;transition:.18s ease;display:inline-flex;align-items:center;gap:8px;}
    .btnLang:hover{background:rgba(255,255,255,.14);transform:translateY(-1px)}
    .sheetWrap{position:fixed;inset:0;z-index:200;display:none;align-items:flex-end;justify-content:center;background:rgba(0,0,0,.48);backdrop-filter:blur(8px);padding:16px;}
    .sheetWrap.show{display:flex;}
    .sheet{width:min(360px,100%);border-radius:24px;border:1px solid rgba(255,255,255,.16);background:linear-gradient(180deg,rgba(255,255,255,.12),rgba(255,255,255,.06));backdrop-filter:blur(18px);box-shadow:0 24px 60px rgba(0,0,.5);overflow:hidden;animation:sheetPop .22s ease forwards;}
    @keyframes sheetPop{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
    .sheetTop{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.1);}
    .sheetTitle{margin:0;font-size:16px;font-weight:700;}
    .sheetBody{padding:12px 16px 16px;}
    .langItem{display:flex;align-items:center;padding:12px 14px;border-radius:14px;text-decoration:none;color:rgba(255,255,255,.9);font-weight:600;transition:.15s ease;margin-bottom:6px;}
    .langItem:hover{background:rgba(255,255,255,.1)}
    .langItem.active{background:linear-gradient(135deg,rgba(125,211,252,.3),rgba(167,139,250,.3));border:1px solid rgba(255,255,255,.2);}
    .iconbtn{width:36px;height:36px;border-radius:12px;border:none;background:rgba(255,255,255,.08);color:rgba(255,255,255,.9);cursor:pointer;display:grid;place-items:center;font-size:18px;}
    .iconbtn:hover{background:rgba(255,255,255,.12)}
  </style>
</head>
<body>
  <button type="button" class="btnLang" onclick="document.getElementById('langSheetWrap').classList.add('show')" aria-label="<?= t('label_language') ?>" aria-haspopup="dialog">🌐 <?= t('label_language') ?></button>

  <div id="langSheetWrap" class="sheetWrap" role="dialog" aria-modal="true" aria-label="<?= t('sheet_language') ?>" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="sheet" onclick="event.stopPropagation()">
      <div class="sheetTop">
        <h2 class="sheetTitle"><?= t('sheet_language') ?></h2>
        <button type="button" class="iconbtn" onclick="document.getElementById('langSheetWrap').classList.remove('show')" aria-label="<?= t('btn_close') ?>">✕</button>
      </div>
      <div class="sheetBody">
        <?php foreach ($AVAILABLE_LANGS as $code => $label): ?>
          <a href="?lang=<?= $code ?>" class="langItem <?= $code === $CURRENT_LANG ? 'active' : '' ?>" hreflang="<?= $code ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="inner">
      <div class="brand">
        <div class="logo"></div>
        <div>
          <h1><?= t('login_title') ?></h1>
          <div class="sub"><?= t('login_subtitle') ?></div>
        </div>
      </div>

      <div class="alerts" aria-live="polite" aria-atomic="true">
        <?php if ($toast_msg): ?>
          <div class="msg"><?= h($toast_msg) ?></div>
        <?php endif; ?>
        <?php if ($toast_err): ?>
          <div class="error"><?= h($toast_err) ?></div>
        <?php endif; ?>
        <?php if (isset($error)) : ?>
          <div class="error"><?= h($error) ?></div>
        <?php endif; ?>
      </div>

      <form method="POST" autocomplete="off">
        <div class="field">
          <label for="u"><?= t('label_username') ?></label>
          <input id="u" type="text" name="login_user" placeholder="<?= t('placeholder_username') ?>" value="<?= h($saved_username) ?>" required autofocus />
        </div>
        <div class="field">
          <label for="p"><?= t('label_password') ?></label>
          <input id="p" type="password" name="login_pass" placeholder="<?= t('placeholder_password') ?>" required />
        </div>
        <label class="checkRow">
          <input type="checkbox" name="save_username" value="1" <?= $save_username_checked ? 'checked' : '' ?> />
          <span><?= t('btn_save_username') ?></span>
        </label>
        <button class="btn" type="submit"><?= t('btn_login') ?></button>
      </form>

      <a class="btnGhost" href="register.php"><?= t('link_no_account') ?></a>
    </div>
  </div>
  <script>
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') document.getElementById('langSheetWrap').classList.remove('show');
    });
  </script>
</body>
</html>

