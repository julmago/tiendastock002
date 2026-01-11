<?php
require __DIR__.'/../../config.php';
require_role('seller','/vendedor/login.php');

header('Content-Type: application/json; charset=utf-8');

$productId = (int)($_GET['product_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$providerId = (int)($_GET['provider_id'] ?? 0);

if (!$productId) {
  http_response_code(400);
  echo json_encode(['error' => 'Producto inválido.']);
  exit;
}

$sellerSt = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$sellerSt->execute([(int)($_SESSION['uid'] ?? 0)]);
$seller = $sellerSt->fetch();
if (!$seller) {
  http_response_code(403);
  echo json_encode(['error' => 'Acceso denegado.']);
  exit;
}

$st = $pdo->prepare("SELECT sp.id FROM store_products sp JOIN stores s ON s.id=sp.store_id WHERE sp.id=? AND s.seller_id=? LIMIT 1");
$st->execute([$productId, (int)$seller['id']]);
if (!$st->fetch()) {
  http_response_code(403);
  echo json_encode(['error' => 'Acceso denegado.']);
  exit;
}

if ($q === '' || mb_strlen($q) < 2) {
  echo json_encode([]);
  exit;
}

$like = "%{$q}%";
$prefix = "{$q}%";
$params = [$productId, $like, $like, $like];
$providerFilter = '';
if ($providerId > 0) {
  $providerFilter = ' AND pp.provider_id = ?';
  $params[] = $providerId;
}
$params[] = $q;
$params[] = $prefix;

$sql = "
  SELECT pp.id, pp.title, pp.sku, p.display_name AS provider_name,
         COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS stock
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  LEFT JOIN store_product_sources sps
    ON sps.provider_product_id = pp.id AND sps.store_product_id = ? AND sps.enabled=1
  WHERE pp.status='active' AND p.status='active'
    AND sps.id IS NULL
    AND (pp.title LIKE ? OR pp.sku LIKE ? OR pp.universal_code LIKE ?)
    {$providerFilter}
  GROUP BY pp.id, pp.title, pp.sku, p.display_name
  HAVING stock > 0
  ORDER BY (pp.title = ?) DESC,
           (pp.title LIKE ?) DESC,
           pp.title ASC
  LIMIT 20
";

$searchSt = $pdo->prepare($sql);
$searchSt->execute($params);
$items = $searchSt->fetchAll();

$out = [];
foreach ($items as $item) {
  $out[] = [
    'id' => (int)$item['id'],
    'title' => (string)$item['title'],
    'sku' => (string)($item['sku'] ?? ''),
    'provider_name' => (string)($item['provider_name'] ?? ''),
    'stock' => (int)$item['stock'],
  ];
}

echo json_encode($out);
