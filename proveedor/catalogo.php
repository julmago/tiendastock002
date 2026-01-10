<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('provider','/proveedor/login.php');

$st = $pdo->prepare("SELECT id, status FROM providers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$p = $st->fetch();
if (!$p) exit('Proveedor inválido');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($p['status'] ?? '') !== 'active') $err="Cuenta pendiente de aprobación.";
  else {
    $title = trim((string)($_POST['title'] ?? ''));
    $price = (float)($_POST['base_price'] ?? 0);
    $sku = trim((string)($_POST['sku'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    if (!$title || $price<=0) $err="Completá título y precio base.";
    else {
      $pdo->prepare("INSERT INTO provider_products(provider_id,title,sku,description,base_price,status) VALUES(?,?,?,?,?,'active')")
          ->execute([(int)$p['id'],$title,$sku?:null,$desc?:null,$price]);
      $msg="Creado.";
    }
  }
}

$rows = $pdo->prepare("SELECT id,title,sku,base_price FROM provider_products WHERE provider_id=? ORDER BY id DESC");
$rows->execute([(int)$p['id']]);
$list = $rows->fetchAll();

page_header('Proveedor - Catálogo base');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Título: <input name='title' style='width:520px'></p>
<p>SKU: <input name='sku' style='width:220px'></p>
<p>Precio base: <input name='base_price' style='width:160px'></p>
<p>Descripción:<br><textarea name='description' rows='3' style='width:90%'></textarea></p>
<button>Crear</button>
</form><hr>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Título</th><th>SKU</th><th>Base</th></tr>";
foreach($list as $r){
  echo "<tr><td>".h((string)$r['id'])."</td><td>".h($r['title'])."</td><td>".h($r['sku']??'')."</td><td>".h((string)$r['base_price'])."</td></tr>";
}
echo "</table>";
page_footer();
