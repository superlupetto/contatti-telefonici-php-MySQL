<?php
session_start();

// --- 1. PROTEZIONE PASSWORD ---
$password_corretta = "lunabella"; 

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

if (isset($_POST['login_pass'])) {
    if ($_POST['login_pass'] === $password_corretta) {
        $_SESSION['user_auth'] = true;
    } else {
        $error = "Password errata!";
    }
}

if (!isset($_SESSION['user_auth'])): ?>
<!DOCTYPE html>
<html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Login</title>
<style>
    body { font-family: 'Segoe UI', sans-serif; background: #f1f3f4; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
    .login-card { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; width: 300px; }
    input { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
    button { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
</style></head>
<body>
    <div class="login-card">
        <h2 style="margin-top:0; font-weight: 400; color: #3c4043;">Accedi</h2>
        <form method="POST">
            <input type="password" name="login_pass" placeholder="Password" required autofocus>
            <button type="submit">Entra</button>
        </form>
        <?php if(isset($error)) echo "<p style='color:red; font-size:14px;'>$error</p>"; ?>
    </div>
</body></html>
<?php exit(); endif; ?>

<?php
// --- 2. LOGICA GESTIONE CONTATTI ---
$upload_dir = 'uploadslist/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true); 

$json_file = $upload_dir . 'contatti.json';
$contacts = file_exists($json_file) ? json_decode(file_get_contents($json_file), true) : [];

// Salvataggio
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['azione']) && $_POST['azione'] == 'salva') {
    $avatar_path = "";
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
        $avatar_name = time() . "_" . preg_replace("/[^a-zA-Z0-9.]/", "_", $_FILES["avatar"]["name"]);
        if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $upload_dir . $avatar_name)) {
            $avatar_path = $upload_dir . $avatar_name;
        }
    }
    $contacts[] = [
        'id' => uniqid(),
        'nome' => htmlspecialchars($_POST['nome']),
        'cognome' => htmlspecialchars($_POST['cognome']),
        'telefono' => htmlspecialchars($_POST['telefono']),
        'avatar' => $avatar_path,
        'preferito' => false
    ];
    file_put_contents($json_file, json_encode(array_values($contacts), JSON_PRETTY_PRINT));
    header("Location: index.php"); exit();
}

// Azioni (Elimina/Preferito)
if (isset($_GET['action'])) {
    $id = $_GET['id'];
    foreach ($contacts as $k => $v) {
        if ($v['id'] == $id) {
            if ($_GET['action'] == 'delete') {
                if (!empty($v['avatar']) && file_exists($v['avatar'])) unlink($v['avatar']);
                unset($contacts[$k]);
            } elseif ($_GET['action'] == 'toggle_fav') {
                $contacts[$k]['preferito'] = !$contacts[$k]['preferito'];
            }
        }
    }
    file_put_contents($json_file, json_encode(array_values($contacts), JSON_PRETTY_PRINT));
    header("Location: index.php"); exit();
}

usort($contacts, function($a, $b) {
    if ($a['preferito'] != $b['preferito']) return $b['preferito'] - $a['preferito'];
    return strcmp($a['nome'], $b['nome']);
});
?>

<!DOCTYPE html>
<html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Contatti</title>
<style>
    :root { --blue: #1a73e8; --gray: #5f6368; --border: #dfe1e5; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; color: #202124; background: #fff; }
    .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
    .btn-create { color: var(--blue); border: none; background: none; font-size: 16px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 10px; margin: 20px 0; }
    .section-label { font-size: 11px; font-weight: 700; color: var(--gray); margin: 25px 0 10px; text-transform: uppercase; }
    .contact-item { display: flex; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f3f4; }
    .avatar { width: 40px; height: 40px; border-radius: 50%; background: #00796b; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 15px; overflow: hidden; }
    .avatar img { width: 100%; height: 100%; object-fit: cover; }
    .info { flex-grow: 1; }
    .actions { display: flex; gap: 15px; align-items: center; }
    .fav-star { text-decoration: none; font-size: 20px; color: #f4b400; }
    .fav-star.off { color: #ccc; filter: grayscale(1); }
    .del-btn { text-decoration: none; color: var(--gray); font-size: 22px; }
    #modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index: 100; }
    .modal-box { background:white; padding:25px; border-radius:8px; width:90%; max-width:400px; }
    input { width: 100%; padding: 12px; margin: 8px 0; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box; }
</style></head>
<body>
    <div class="header">
        <h1 style="font-size: 22px; font-weight: 400; margin:0;">Contatti</h1>
        <div style="display:flex; gap:15px; align-items:center;">
            <button onclick="toggleS()" style="background:none; border:none; cursor:pointer; font-size:20px;">🔍</button>
            <a href="?logout=1" style="text-decoration:none; font-size:14px; color:var(--gray);">Esci</a>
        </div>
    </div>

    <div id="sDiv" style="display:none; margin-top:15px;"><input type="text" id="sIn" style="width:100%; padding:12px; border:1px solid var(--border); border-radius:8px;" placeholder="Cerca..." onkeyup="filter()"></div>

    <button class="btn-create" onclick="document.getElementById('modal').style.display='flex'"> <span style="font-size: 24px;">+</span> Crea contatto</button>

    <div id="list">
        <?php $lg = null; foreach ($contacts as $c): 
            $g = $c['preferito'] ? 'Preferiti' : 'Contatti';
            if ($lg != $g): echo "<div class='section-label'>$g</div>"; $lg = $g; endif; ?>
            <div class="contact-item" data-n="<?php echo strtolower($c['nome'].' '.$c['cognome']); ?>">
                <div class="avatar" style="background-color: <?php echo '#' . substr(md5($c['nome']), 0, 6); ?>;">
                    <?php if(!empty($c['avatar'])): ?><img src="<?php echo $c['avatar']; ?>"><?php else: echo strtoupper(substr($c['nome'], 0, 1)); endif; ?>
                </div>
                <div class="info"><strong><?php echo $c['nome'].' '.$c['cognome']; ?></strong><br><small><?php echo $c['telefono']; ?></small></div>
                <div class="actions">
                    <a href="?action=toggle_fav&id=<?php echo $c['id']; ?>" class="fav-star <?php echo !$c['preferito'] ? 'off' : ''; ?>">★</a>
                    <a href="?action=delete&id=<?php echo $c['id']; ?>" class="del-btn" onclick="return confirm('Eliminare?')">×</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="modal"><div class="modal-box"><h3>Nuovo Contatto</h3>
        <form action="index.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="azione" value="salva"><input type="text" name="nome" placeholder="Nome" required>
            <input type="text" name="cognome" placeholder="Cognome"><input type="tel" name="telefono" placeholder="Telefono" required>
            <input type="file" name="avatar" accept="image/*">
            <button type="submit" style="background:var(--blue); color:white; border:none; padding:12px; width:100%; border-radius:4px; cursor:pointer;">Salva</button>
            <button type="button" onclick="document.getElementById('modal').style.display='none'" style="background:none; border:none; width:100%; margin-top:10px; cursor:pointer;">Annulla</button>
        </form>
    </div></div>

    <script>
        function toggleS() { let d=document.getElementById('sDiv'); d.style.display=d.style.display==='block'?'none':'block'; document.getElementById('sIn').focus(); }
        function filter() { let v=document.getElementById('sIn').value.toLowerCase(); document.querySelectorAll('.contact-item').forEach(i=>{ i.style.display=i.dataset.n.includes(v)?'flex':'none'; }); }
    </script>
</body></html>
