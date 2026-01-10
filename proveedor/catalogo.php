<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('provider','/proveedor/login.php');

$st = $pdo->prepare("SELECT id, status FROM providers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$p = $st->fetch();
if (!$p) exit('Proveedor inválido');

$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_product = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($p['status'] ?? '') !== 'active') $err="Cuenta pendiente de aprobación.";
  else {
    $title = trim((string)($_POST['title'] ?? ''));
    $price = (float)($_POST['base_price'] ?? 0);
    $sku = trim((string)($_POST['sku'] ?? ''));
    $universalCode = trim((string)($_POST['universal_code'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    if (!$title || $price<=0) $err="Completá título y precio base.";
    elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
    else {
      if ($product_id > 0) {
        $st = $pdo->prepare("SELECT id FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
        $st->execute([$product_id,(int)$p['id']]);
        if ($st->fetch()) {
          $pdo->prepare("UPDATE provider_products SET title=?, sku=?, universal_code=?, description=?, base_price=? WHERE id=? AND provider_id=?")
              ->execute([$title,$sku?:null,$universalCode?:null,$desc?:null,$price,$product_id,(int)$p['id']]);
          $msg="Actualizado.";
          $edit_id = $product_id;
        } else {
          $err="Producto inválido.";
        }
      } else {
        $pdo->prepare("INSERT INTO provider_products(provider_id,title,sku,universal_code,description,base_price,status) VALUES(?,?,?,?,?,?,'active')")
            ->execute([(int)$p['id'],$title,$sku?:null,$universalCode?:null,$desc?:null,$price]);
        $msg="Creado.";
      }
    }
  }
}

if ($edit_id > 0) {
  $st = $pdo->prepare("SELECT id,title,sku,universal_code,description,base_price FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
  $st->execute([$edit_id,(int)$p['id']]);
  $edit_product = $st->fetch();
  if (!$edit_product) {
    $err = $err ?? "Producto inválido.";
    $edit_id = 0;
  }
}

$rows = $pdo->prepare("SELECT id,title,sku,universal_code,base_price FROM provider_products WHERE provider_id=? ORDER BY id DESC");
$rows->execute([(int)$p['id']]);
$list = $rows->fetchAll();

page_header('Proveedor - Catálogo base');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='product_id' value='".h((string)($edit_product['id'] ?? ''))."'>
<p>Título: <input name='title' style='width:520px' value='".h($edit_product['title'] ?? '')."'></p>
<p>SKU: <input name='sku' style='width:220px' value='".h($edit_product['sku'] ?? '')."'></p>
<p>Código universal (8-14 dígitos): <input name='universal_code' style='width:220px' value='".h($edit_product['universal_code'] ?? '')."'></p>
<p>Precio base: <input name='base_price' style='width:160px' value='".h((string)($edit_product['base_price'] ?? ''))."'></p>
<p>Descripción:<br><textarea name='description' rows='3' style='width:90%'>".h($edit_product['description'] ?? '')."</textarea></p>
<button>".($edit_product ? "Guardar cambios" : "Crear")."</button>";
if ($edit_product) {
  echo " <a href='/proveedor/catalogo.php'>Cancelar edición</a>";
}
echo "
</form><hr>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Base</th></tr>";
foreach($list as $r){
  $url = "/proveedor/catalogo.php?id=".h((string)$r['id']);
  echo "<tr><td>".h((string)$r['id'])."</td><td><a href='".$url."'>".h($r['title'])."</a></td><td>".h($r['sku']??'')."</td><td>".h($r['universal_code']??'')."</td><td>".h((string)$r['base_price'])."</td></tr>";
}
echo "</table>";
page_footer();
