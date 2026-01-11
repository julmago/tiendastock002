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

$productId = (int)($_GET['id'] ?? 0);
if (!$productId) { page_header('Producto'); echo "<p>Producto inválido.</p>"; page_footer(); exit; }

$productSt = $pdo->prepare("SELECT sp.*, s.name AS store_name, s.store_type, s.markup_percent, s.id AS store_id
  FROM store_products sp
  JOIN stores s ON s.id=sp.store_id
  WHERE sp.id=? AND s.seller_id=? LIMIT 1");
$productSt->execute([$productId,(int)$seller['id']]);
$product = $productSt->fetch();
if (!$product) { page_header('Producto'); echo "<p>Producto inválido.</p>"; page_footer(); exit; }

$storeId = (int)$product['store_id'];

$providerProducts = $pdo->query("
  SELECT pp.id, pp.title, pp.base_price, p.display_name AS provider_name
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  WHERE pp.status='active' AND p.status='active'
  ORDER BY pp.id DESC LIMIT 200
")->fetchAll();

$linkedSt = $pdo->prepare("
  SELECT pp.id, pp.title, pp.sku, p.display_name AS provider_name,
         COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS stock
  FROM store_product_sources sps
  JOIN provider_products pp ON pp.id = sps.provider_product_id
  LEFT JOIN providers p ON p.id = pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  WHERE sps.store_product_id = ? AND sps.enabled=1
  GROUP BY pp.id, pp.title, pp.sku, p.display_name
  ORDER BY pp.id DESC
");
$linkedSt->execute([$productId]);
$linkedProducts = $linkedSt->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_info') {
  $title = trim((string)($_POST['title'] ?? ''));
  $sku = trim((string)($_POST['sku'] ?? ''));
  $universalCode = trim((string)($_POST['universal_code'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));

  if (!$title) $err = "Falta título.";
  elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
  else {
    $pdo->prepare("UPDATE store_products SET title=?, sku=?, universal_code=?, description=? WHERE id=? AND store_id=?")
        ->execute([$title, $sku?:null, $universalCode?:null, $description?:null, $productId, $storeId]);
    $msg = "Producto actualizado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_stock') {
  $ownQty = (int)($_POST['own_stock_qty'] ?? 0);
  $ownPrice = (float)($_POST['own_stock_price'] ?? 0);
  $manual = trim((string)($_POST['manual_price'] ?? ''));
  $manualVal = ($manual === '') ? null : (float)$manual;

  $pdo->prepare("UPDATE store_products SET own_stock_qty=?, own_stock_price=?, manual_price=? WHERE id=? AND store_id=?")
      ->execute([$ownQty, $ownPrice>0?$ownPrice:null, $manualVal, $productId, $storeId]);
  $msg = "Stock actualizado.";
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'toggle_source') {
  $ppId = (int)($_POST['provider_product_id'] ?? 0);
  $enabled = (int)($_POST['enabled'] ?? 1);

  if (!$ppId) $err = "Elegí un proveedor.";
  else {
    $pdo->prepare("INSERT INTO store_product_sources(store_product_id,provider_product_id,enabled)
                   VALUES(?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)")
        ->execute([$productId,$ppId,$enabled?1:0]);
    $msg = "Fuente actualizada.";
  }
}

$productSt->execute([$productId,(int)$seller['id']]);
$product = $productSt->fetch();

$provStock = provider_stock_sum($pdo, (int)$product['id']);
$sell = current_sell_price($pdo, $product, $product);
$stockTotal = $provStock + (int)$product['own_stock_qty'];
$sellTxt = ($sell>0) ? '$'.number_format($sell,2,',','.') : 'Sin stock';

page_header('Producto');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<p><a href='productos.php?store_id=".h((string)$storeId)."'>← Volver al listado</a></p>";

echo "<h3>Editar producto</h3>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='update_info'>
<p>Título: <input name='title' value='".h($product['title'])."' style='width:520px'></p>
<p>SKU: <input name='sku' value='".h((string)($product['sku']??''))."' style='width:220px'></p>
<p>Código universal (8-14 dígitos): <input name='universal_code' value='".h((string)($product['universal_code']??''))."' style='width:220px'></p>
<p>Descripción:<br><textarea name='description' rows='4' style='width:90%'>".h((string)($product['description']??''))."</textarea></p>
<button>Guardar cambios</button>
</form><hr>";

echo "<h3>Stock y precio</h3>
<p>Stock proveedor: ".h((string)$provStock)." | Precio actual: ".h($sellTxt)." (total: ".h((string)$stockTotal).")</p>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='update_stock'>
Own qty <input name='own_stock_qty' value='".h((string)$product['own_stock_qty'])."' style='width:70px'>
Own $ <input name='own_stock_price' value='".h((string)($product['own_stock_price']??''))."' style='width:90px'>
Manual $ <input name='manual_price' value='".h((string)($product['manual_price']??''))."' style='width:90px'>
<button>Guardar</button>
</form><hr>";

echo "<h3>Proveedor</h3>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='toggle_source'>
<select name='provider_product_id' style='width:360px'>
  <option value='0'>-- proveedor --</option>";
  foreach($providerProducts as $pp){
    echo "<option value='".h((string)$pp['id'])."'>#".h((string)$pp['id'])." ".h($pp['provider_name'])." | ".h($pp['title'])." ($".h((string)$pp['base_price']).")</option>";
  }

echo "</select>
<select name='enabled'><option value='1'>ON</option><option value='0'>OFF</option></select>
<button>Aplicar</button>
</form>";

echo "<h3>Productos vinculados</h3>";
if (!$linkedProducts) {
  echo "<p>No hay productos vinculados a esta publicación.</p>";
} else {
  echo "<table border='1' cellpadding='6' cellspacing='0'>
  <tr><th>ID</th><th>Título</th><th>SKU</th><th>Proveedor</th><th>Stock</th></tr>";
  foreach($linkedProducts as $linked){
    $providerName = $linked['provider_name'] ?: '—';
    echo "<tr>
      <td>".h((string)$linked['id'])."</td>
      <td>".h((string)$linked['title'])."</td>
      <td>".h((string)($linked['sku'] ?? ''))."</td>
      <td>".h((string)$providerName)."</td>
      <td>".h((string)$linked['stock'])."</td>
    </tr>";
  }
  echo "</table>";
}

page_footer();
