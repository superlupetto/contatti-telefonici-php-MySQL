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
    header("Location: app.php");
    exit();
  } else {
    $error = "Credenziali non valide!";
  }
}
?>
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
          <label for="u">Username</label>
          <input id="u" type="text" name="login_user" placeholder="admin" required autofocus />
        </div>
        <div class="field">
          <label for="p">Password</label>
          <input id="p" type="password" name="login_pass" placeholder="••••••••" required />
        </div>
        <button class="btn" type="submit">Entra</button>
      </form>

      <a class="btnGhost" href="register.php">Registrati</a>
    </div>
  </div>
</body>
</html>

