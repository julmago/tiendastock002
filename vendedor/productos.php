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

$action = $_GET['action'] ?? '';
if ($action === 'new') {
  $redirectUrl = 'productos_nuevo.php';
  if ($storeId) {
    $redirectUrl .= '?'.http_build_query(['store_id' => $storeId]);
  }
  header("Location: ".$redirectUrl);
  exit;
}

page_header('Vendedor - Productos');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

require __DIR__.'/partials/productos_header.php';

echo "<form method='get'>
<select name='store_id'>";
foreach($myStores as $ms){
  $sel = ((int)$ms['id']===$storeId) ? "selected" : "";
  echo "<option value='".h((string)$ms['id'])."' $sel>".h($ms['name'])." (".h($ms['store_type']).")</option>";
}
echo "</select> <button>Ver</button>
</form><hr>";

$stp = $pdo->prepare("SELECT * FROM store_products WHERE store_id=? ORDER BY id DESC");
$stp->execute([$storeId]);
$storeProducts = $stp->fetchAll();

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
