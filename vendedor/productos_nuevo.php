<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');

$storesSt = $pdo->prepare("SELECT id, name, slug, store_type, markup_percent FROM stores WHERE seller_id=? ORDER BY id DESC");
$storesSt->execute([(int)$seller['id']]);
$myStores = $storesSt->fetchAll();

$storeId = (int)($_GET['store_id'] ?? 0);
if (!$storeId && $myStores) $storeId = (int)$myStores[0]['id'];

$currentStore = null;
foreach($myStores as $ms){ if ((int)$ms['id'] === $storeId) $currentStore = $ms; }
if (!$currentStore) { page_header('Productos'); echo "<p>Primero creá una tienda.</p>"; page_footer(); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'create') {
  $title = trim((string)($_POST['title'] ?? ''));
  $sku = trim((string)($_POST['sku'] ?? ''));
  $universalCode = trim((string)($_POST['universal_code'] ?? ''));
  if (!$title) $err="Falta título.";
  elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
  else {
    $pdo->prepare("INSERT INTO store_products(store_id,title,sku,universal_code,description,status,own_stock_qty,own_stock_price,manual_price)
                   VALUES(?,?,?,?,?, 'active',0,NULL,NULL)")
        ->execute([$storeId,$title,$sku?:null,$universalCode?:null,($_POST['description']??'')?:null]);
    $msg="Producto creado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'copy') {
  $ppId = (int)($_POST['provider_product_id'] ?? 0);
  if (!$ppId) $err="Elegí un producto de proveedor.";
  else {
    $pp = $pdo->prepare("SELECT title, description, sku, universal_code FROM provider_products WHERE id=? AND status='active'");
    $pp->execute([$ppId]);
    $row = $pp->fetch();
    if (!$row) $err="Producto proveedor inválido.";
    else {
      $pdo->prepare("INSERT INTO store_products(store_id,title,sku,universal_code,description,status,own_stock_qty,own_stock_price,manual_price)
                     VALUES(?,?,?,?,?, 'active',0,NULL,NULL)")
          ->execute([$storeId,$row['title'],$row['sku']??null,$row['universal_code']??null,$row['description']??null]);
      $spId = (int)$pdo->lastInsertId();
      $pdo->prepare("INSERT IGNORE INTO store_product_sources(store_product_id,provider_product_id,enabled) VALUES(?,?,1)")
          ->execute([$spId,$ppId]);
      $msg="Copiado y vinculado al proveedor.";
    }
  }
}

$providerProducts = $pdo->query("
  SELECT pp.id, pp.title, pp.base_price, p.display_name AS provider_name
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  WHERE pp.status='active' AND p.status='active'
  ORDER BY pp.id DESC LIMIT 200
")->fetchAll();

page_header('Vendedor - Productos');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

require __DIR__.'/partials/productos_header.php';

echo "<h3>Crear desde cero</h3>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='create'>
<p>Título: <input name='title' style='width:520px'></p>
<p>SKU: <input name='sku' style='width:220px'></p>
<p>Código universal (8-14 dígitos): <input name='universal_code' style='width:220px'></p>
<p>Descripción:<br><textarea name='description' rows='3' style='width:90%'></textarea></p>
<button>Crear</button>
</form><hr>";

echo "<h3>Copiar desde proveedor</h3>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='copy'>
<select name='provider_product_id' style='width:780px'>
<option value='0'>-- elegir --</option>";
foreach($providerProducts as $pp){
  echo "<option value='".h((string)$pp['id'])."'>#".h((string)$pp['id'])." ".h($pp['provider_name'])." | ".h($pp['title'])." ($".h((string)$pp['base_price']).")</option>";
}
echo "</select> <button>Copiar</button>
</form><hr>";

page_footer();
