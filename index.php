<?php
session_start();

// --- 1. PROTEZIONE PASSWORD ---
$password_corretta = "nome"; 

if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit(); }
if (isset($_POST['login_pass'])) {
    if ($_POST['login_pass'] === $password_corretta) { $_SESSION['user_auth'] = true; } 
    else { $error = "Password errata!"; }
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

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['azione']) && $_POST['azione'] == 'salva') {
    $id = !empty($_POST['id']) ? $_POST['id'] : uniqid();
    $avatar_path = $_POST['old_avatar'] ?? "";
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
        $avatar_name = time() . "_" . preg_replace("/[^a-zA-Z0-9.]/", "_", $_FILES["avatar"]["name"]);
        if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $upload_dir . $avatar_name)) {
            if (!empty($_POST['old_avatar']) && file_exists($_POST['old_avatar'])) @unlink($_POST['old_avatar']);
            $avatar_path = $upload_dir . $avatar_name;
        }
    }
    $contact_data = [
        'id' => $id,
        'nome' => htmlspecialchars($_POST['nome']),
        'cognome' => htmlspecialchars($_POST['cognome']),
        'telefono' => htmlspecialchars($_POST['telefono']),
        'email' => htmlspecialchars($_POST['email'] ?? ""),
        'avatar' => $avatar_path,
        'preferito' => (isset($_POST['preferito']) && ($_POST['preferito'] == '1' || $_POST['preferito'] === true))
    ];
    $found = false;
    foreach ($contacts as $index => $c) {
        if ($c['id'] === $id) { $contacts[$index] = $contact_data; $found = true; break; }
    }
    if (!$found) $contacts[] = $contact_data;
    file_put_contents($json_file, json_encode(array_values($contacts), JSON_PRETTY_PRINT));
    header("Location: index.php"); exit();
}

if (isset($_GET['action'])) {
    $id = $_GET['id'];
    foreach ($contacts as $k => $v) {
        if ($v['id'] == $id) {
            if ($_GET['action'] == 'delete') {
                if (!empty($v['avatar']) && file_exists($v['avatar'])) @unlink($v['avatar']);
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
    :root { --blue: #1a73e8; --gray: #5f6368; --bg-view: #f8f9fa; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; color: #202124; background: #fff; }
    
    .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dfe1e5; padding-bottom: 15px; }
    .btn-create { color: var(--blue); border: none; background: none; font-size: 16px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 10px; margin: 20px 0; }
    .section-label { font-size: 11px; font-weight: 700; color: var(--gray); margin: 25px 0 10px; text-transform: uppercase; }

    .contact-item { display: flex; align-items: center; padding: 12px 10px; cursor: pointer; border-radius: 8px; transition: background 0.2s; }
    .contact-item:hover { background: #f1f3f4; }
    .avatar-circle { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; margin-right: 15px; overflow: hidden; flex-shrink: 0; }
    .avatar-circle img { width:100%; height:100%; object-fit:cover; }

    /* SCHEDA DETTAGLIO CONTATTO */
    .view-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:white; z-index: 200; flex-direction: column; }
    .view-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; }
    .view-content { padding: 30px 20px; max-width: 500px; margin: 0 auto; width: 100%; box-sizing: border-box; }
    .big-avatar { width: 120px; height: 120px; border-radius: 50%; color: white; font-size: 48px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .big-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .view-name { font-size: 28px; text-align: center; margin-bottom: 30px; color: #202124; }
    
    .info-card { background: #f0f4f8; border-radius: 16px; padding: 24px; }
    .info-title { font-weight: 500; font-size: 14px; margin-bottom: 20px; color: #202124; }
    .info-row { display: flex; align-items: center; gap: 15px; margin-bottom: 18px; color: #3c4043; font-size: 15px; }
    .info-row:last-child { margin-bottom: 0; }
    
    /* MODALE MODIFICA */
    #editModal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index: 300; justify-content: center; align-items: center; }
    .edit-box { background: white; width: 90%; max-width: 400px; padding: 25px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
    input { width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    
    .icon-btn { background: none; border: none; cursor: pointer; font-size: 20px; padding: 8px; color: var(--gray); border-radius: 50%; }
    .icon-btn:hover { background: #f1f3f4; }
</style>
</head>
<body>

    <div class="header">
        <h1 style="font-size: 22px; font-weight: 400; margin:0;">Contatti</h1>
        <div style="display:flex; align-items:center; gap:5px;">
            <button class="icon-btn" onclick="openEdit()">➕</button>
            <a href="?logout=1" style="text-decoration:none; color:var(--gray); font-size:14px; margin-left:10px;">Esci</a>
        </div>
    </div>

    <div id="list">
        <?php $lg = null; foreach ($contacts as $c): 
            $g = $c['preferito'] ? 'Preferiti' : 'Contatti';
            if ($lg != $g): echo "<div class='section-label'>$g</div>"; $lg = $g; endif; ?>
            <div class="contact-item" onclick='openView(<?php echo json_encode($c); ?>)'>
                <div class="avatar-circle" style="background-color: <?php echo '#' . substr(md5($c['id']), 0, 6); ?>;">
                    <?php if(!empty($c['avatar'])): ?><img src="<?php echo $c['avatar']; ?>"><?php else: echo strtoupper(substr($c['nome'], 0, 1)); endif; ?>
                </div>
                <div style="flex-grow:1;"><strong><?php echo $c['nome'].' '.$c['cognome']; ?></strong></div>
                <div style="color:var(--gray); font-size:14px;"><?php echo $c['telefono']; ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="viewOverlay" class="view-overlay">
        <div class="view-header">
            <button class="icon-btn" onclick="closeView()">←</button>
            <div>
                <button class="icon-btn" id="btnEditMatita">✎</button>
                <button class="icon-btn" id="btnStar">☆</button>
                <button class="icon-btn" id="btnDelete">🗑️</button>
                <button class="icon-btn" onclick="closeView()">✕</button>
            </div>
        </div>
        <div class="view-content">
            <div id="v_avatar" class="big-avatar"></div>
            <div id="v_nome" class="view-name"></div>
            
            <div class="info-card">
                <div class="info-title">Dettagli contatto</div>
                <div class="info-row"><span>📧</span> <span id="v_email"></span></div>
                <div class="info-row"><span>📞</span> <span id="v_tel"></span></div>
            </div>
        </div>
    </div>

    <div id="editModal">
        <div class="edit-box">
            <h3 id="etitle" style="margin-top:0; font-weight:400;">Crea contatto</h3>
            <form action="index.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="azione" value="salva">
                <input type="hidden" name="id" id="e_id">
                <input type="hidden" name="old_avatar" id="e_old_avatar">
                <input type="hidden" name="preferito" id="e_preferito">
                
                <input type="text" name="nome" id="e_nome" placeholder="Nome" required>
                <input type="text" name="cognome" id="e_cognome" placeholder="Cognome">
                <input type="tel" name="telefono" id="e_tel" placeholder="Telefono" required>
                <input type="email" name="email" id="e_email" placeholder="Email">
                <input type="file" name="avatar" accept="image/*">
                
                <div style="display:flex; justify-content: flex-end; gap:10px; margin-top:20px;">
                    <button type="button" onclick="closeEdit()" style="background:none; border:none; cursor:pointer; color:var(--gray);">Annulla</button>
                    <button type="submit" style="background:var(--blue); color:white; border:none; padding:10px 24px; border-radius:4px; cursor:pointer; font-weight:500;">Salva</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openView(c) {
            document.getElementById('v_nome').innerText = c.nome + ' ' + (c.cognome || '');
            document.getElementById('v_tel').innerText = c.telefono;
            document.getElementById('v_email').innerText = c.email || 'Aggiungi email';
            
            const av = document.getElementById('v_avatar');
            av.style.backgroundColor = '#' + Math.floor(Math.random()*16777215).toString(16);
            av.innerHTML = c.avatar ? `<img src="${c.avatar}">` : c.nome.charAt(0).toUpperCase();

            document.getElementById('btnEditMatita').onclick = () => openEdit(c);
            document.getElementById('btnStar').innerText = c.preferito ? '★' : '☆';
            document.getElementById('btnStar').onclick = () => window.location.href = `?action=toggle_fav&id=${c.id}`;
            document.getElementById('btnDelete').onclick = () => { if(confirm('Eliminare?')) window.location.href = `?action=delete&id=${c.id}`; };

            document.getElementById('viewOverlay').style.display = 'flex';
        }

        function closeView() { document.getElementById('viewOverlay').style.display = 'none'; }

        function openEdit(c = null) {
            if(c) {
                document.getElementById('etitle').innerText = "Modifica contatto";
                document.getElementById('e_id').value = c.id;
                document.getElementById('e_nome').value = c.nome;
                document.getElementById('e_cognome').value = c.cognome || '';
                document.getElementById('e_tel').value = c.telefono;
                document.getElementById('e_email').value = c.email || '';
                document.getElementById('e_old_avatar').value = c.avatar || '';
                document.getElementById('e_preferito').value = c.preferito ? '1' : '0';
            } else {
                document.getElementById('etitle').innerText = "Crea contatto";
                document.querySelector('#editModal form').reset();
                document.getElementById('e_id').value = "";
            }
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEdit() { document.getElementById('editModal').style.display = 'none'; }
    </script>
</body></html>
