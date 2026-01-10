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
  if (!$title) $err="Falta título.";
  else {
    $pdo->prepare("INSERT INTO store_products(store_id,title,sku,description,status,own_stock_qty,own_stock_price,manual_price)
                   VALUES(?,?,?,?, 'active',0,NULL,NULL)")
        ->execute([$storeId,$title,($_POST['sku']??'')?:null,($_POST['description']??'')?:null]);
    $msg="Producto creado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'copy') {
  $ppId = (int)($_POST['provider_product_id'] ?? 0);
  if (!$ppId) $err="Elegí un producto de proveedor.";
  else {
    $pp = $pdo->prepare("SELECT title, description, sku FROM provider_products WHERE id=? AND status='active'");
    $pp->execute([$ppId]);
    $row = $pp->fetch();
    if (!$row) $err="Producto proveedor inválido.";
    else {
      $pdo->prepare("INSERT INTO store_products(store_id,title,sku,description,status,own_stock_qty,own_stock_price,manual_price)
                     VALUES(?,?,?,?, 'active',0,NULL,NULL)")
          ->execute([$storeId,$row['title'],$row['sku']??null,$row['description']??null]);
      $spId = (int)$pdo->lastInsertId();
      $pdo->prepare("INSERT IGNORE INTO store_product_sources(store_product_id,provider_product_id,enabled) VALUES(?,?,1)")
          ->execute([$spId,$ppId]);
      $msg="Copiado y vinculado al proveedor.";
    }
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_stock') {
  $spId = (int)($_POST['store_product_id'] ?? 0);
  $ownQty = (int)($_POST['own_stock_qty'] ?? 0);
  $ownPrice = (float)($_POST['own_stock_price'] ?? 0);
  $manual = trim((string)($_POST['manual_price'] ?? ''));
  $manualVal = ($manual === '') ? null : (float)$manual;

  $chk = $pdo->prepare("SELECT id FROM store_products WHERE id=? AND store_id=? LIMIT 1");
  $chk->execute([$spId,$storeId]);
  if (!$chk->fetch()) $err="Producto inválido.";
  else {
    $pdo->prepare("UPDATE store_products SET own_stock_qty=?, own_stock_price=?, manual_price=? WHERE id=?")
        ->execute([$ownQty, $ownPrice>0?$ownPrice:null, $manualVal, $spId]);
    $msg="Actualizado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'toggle_source') {
  $spId = (int)($_POST['store_product_id'] ?? 0);
  $ppId = (int)($_POST['provider_product_id'] ?? 0);
  $enabled = (int)($_POST['enabled'] ?? 1);

  $chk = $pdo->prepare("SELECT id FROM store_products WHERE id=? AND store_id=? LIMIT 1");
  $chk->execute([$spId,$storeId]);
  if (!$chk->fetch()) $err="Producto inválido.";
  elseif (!$ppId) $err="Elegí un proveedor.";
  else {
    $pdo->prepare("INSERT INTO store_product_sources(store_product_id,provider_product_id,enabled)
                   VALUES(?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)")
        ->execute([$spId,$ppId,$enabled?1:0]);
    $msg="Fuente actualizada.";
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
<tr><th>ID</th><th>Título</th><th>Stock prov</th><th>Own qty</th><th>Own $</th><th>Manual $</th><th>Precio actual</th><th>Acciones</th></tr>";
foreach($storeProducts as $sp){
  $provStock = provider_stock_sum($pdo, (int)$sp['id']);
  $sell = current_sell_price($pdo, $currentStore, $sp);
  $stockTotal = $provStock + (int)$sp['own_stock_qty'];
  $sellTxt = ($sell>0) ? '$'.number_format($sell,2,',','.') : 'Sin stock';

  echo "<tr>
    <td>".h((string)$sp['id'])."</td>
    <td>".h($sp['title'])."</td>
    <td>".h((string)$provStock)."</td>
    <td>".h((string)$sp['own_stock_qty'])."</td>
    <td>".h((string)($sp['own_stock_price']??''))."</td>
    <td>".h((string)($sp['manual_price']??''))."</td>
    <td>".h($sellTxt)." (total: ".h((string)$stockTotal).")</td>
    <td>
      <form method='post' style='margin:0 0 8px 0'>
        <input type='hidden' name='csrf' value='".h(csrf_token())."'>
        <input type='hidden' name='action' value='update_stock'>
        <input type='hidden' name='store_product_id' value='".h((string)$sp['id'])."'>
        Own qty <input name='own_stock_qty' value='".h((string)$sp['own_stock_qty'])."' style='width:70px'>
        Own $ <input name='own_stock_price' value='".h((string)($sp['own_stock_price']??''))."' style='width:90px'>
        Manual $ <input name='manual_price' value='".h((string)($sp['manual_price']??''))."' style='width:90px'>
        <button>Guardar</button>
      </form>

      <form method='post' style='margin:0'>
        <input type='hidden' name='csrf' value='".h(csrf_token())."'>
        <input type='hidden' name='action' value='toggle_source'>
        <input type='hidden' name='store_product_id' value='".h((string)$sp['id'])."'>
        <select name='provider_product_id' style='width:360px'>
          <option value='0'>-- proveedor --</option>";
          foreach($providerProducts as $pp){
            echo "<option value='".h((string)$pp['id'])."'>#".h((string)$pp['id'])." ".h($pp['provider_name'])."</option>";
          }
  echo "</select>
        <select name='enabled'><option value='1'>ON</option><option value='0'>OFF</option></select>
        <button>Aplicar</button>
      </form>
    </td>
  </tr>";
}
echo "</table>";
page_footer();
