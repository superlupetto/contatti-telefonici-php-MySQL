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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $toast_err = "Richiesta non valida (CSRF). Riprova.";
  } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $pass1 = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
      $toast_err = "Username non valido (3-50, solo lettere/numeri . _ -).";
    } elseif (strlen($pass1) < 6) {
      $toast_err = "Password troppo corta (min 6).";
    } elseif ($pass1 !== $pass2) {
      $toast_err = "Le password non coincidono.";
    } else {
      try {
        $pdo = db();
        $hash = password_hash($pass1, PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO users (username, pass_hash, role, is_active) VALUES (?, ?, 'user', 1)");
        $st->execute([$username, $hash]);

        $_SESSION['toast_msg'] = "Account creato ✅ Ora puoi accedere.";
        header("Location: login.php");
        exit();
      } catch (PDOException $e) {
        // 23000 = integrity constraint violation (es. username già usato)
        if ((string)$e->getCode() === '23000') {
          $toast_err = "Username già esistente. Scegline un altro.";
        } else {
          $toast_err = "Errore durante la registrazione: " . $e->getMessage();
        }
      } catch (Throwable $e) {
        $toast_err = "Errore durante la registrazione: " . $e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Registrati</title>
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
          <h1>Registrati</h1>
          <div class="sub">Crea un nuovo account.</div>
        </div>
      </div>

      <div class="alerts" aria-live="polite" aria-atomic="true">
        <?php if ($toast_msg): ?>
          <div class="msg"><?= h($toast_msg) ?></div>
        <?php endif; ?>
        <?php if ($toast_err): ?>
          <div class="error"><?= h($toast_err) ?></div>
        <?php endif; ?>
      </div>

      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

        <div class="field">
          <label for="u">Username</label>
          <input id="u" type="text" name="username" placeholder="es. mario.rossi" required autofocus />
        </div>
        <div class="field">
          <label for="p1">Password</label>
          <input id="p1" type="password" name="password" placeholder="Min 6 caratteri" required />
        </div>
        <div class="field">
          <label for="p2">Ripeti password</label>
          <input id="p2" type="password" name="password2" placeholder="Ripeti password" required />
        </div>

        <button class="btn" type="submit">Crea account</button>
      </form>

      <a class="btnGhost" href="login.php">Hai già un account? Accedi</a>
    </div>
  </div>
</body>
</html>

