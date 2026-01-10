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

$providerProducts = $pdo->query("
  SELECT pp.id, pp.title, pp.base_price, p.display_name AS provider_name
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  WHERE pp.status='active' AND p.status='active'
  ORDER BY pp.id DESC LIMIT 200
")->fetchAll();

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

$stp = $pdo->prepare("SELECT * FROM store_products WHERE store_id=? ORDER BY id DESC");
$stp->execute([$storeId]);
$storeProducts = $stp->fetchAll();

page_header('Vendedor - Productos');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<form method='get'>
<select name='store_id'>";
foreach($myStores as $ms){
  $sel = ((int)$ms['id']===$storeId) ? "selected" : "";
  echo "<option value='".h((string)$ms['id'])."' $sel>".h($ms['name'])." (".h($ms['store_type']).")</option>";
}
echo "</select> <button>Ver</button>
</form><hr>";

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

echo "<h3>Listado</h3>";
if (!$storeProducts) { echo "<p>Sin productos.</p>"; page_footer(); exit; }

echo "<table border='1' cellpadding='6' cellspacing='0'>
<tr><th>ID</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Stock prov</th><th>Own qty</th><th>Own $</th><th>Manual $</th><th>Precio actual</th></tr>";
foreach($storeProducts as $sp){
  $provStock = provider_stock_sum($pdo, (int)$sp['id']);
  $sell = current_sell_price($pdo, $currentStore, $sp);
  $stockTotal = $provStock + (int)$sp['own_stock_qty'];
  $sellTxt = ($sell>0) ? '$'.number_format($sell,2,',','.') : 'Sin stock';

  $editUrl = "producto.php?id=".h((string)$sp['id'])."&store_id=".h((string)$storeId);
  echo "<tr>
    <td>".h((string)$sp['id'])."</td>
    <td><a href='".$editUrl."'>".h($sp['title'])."</a></td>
    <td>".h($sp['sku']??'')."</td>
    <td>".h($sp['universal_code']??'')."</td>
    <td>".h((string)$provStock)."</td>
    <td>".h((string)$sp['own_stock_qty'])."</td>
    <td>".h((string)($sp['own_stock_price']??''))."</td>
    <td>".h((string)($sp['manual_price']??''))."</td>
    <td>".h($sellTxt)." (total: ".h((string)$stockTotal).")</td>
  </tr>";
}
echo "</table>";
page_footer();
