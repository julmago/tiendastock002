<?php
function best_provider_source(PDO $pdo, int $store_product_id): ?array {
  $st = $pdo->prepare("
    SELECT sps.provider_product_id, pp.base_price,
           (ws.qty_available - ws.qty_reserved) AS available
    FROM store_product_sources sps
    JOIN provider_products pp ON pp.id = sps.provider_product_id AND pp.status='active'
    JOIN providers p ON p.id = pp.provider_id AND p.status='active'
    JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
    WHERE sps.store_product_id = ? AND sps.enabled=1
      AND (ws.qty_available - ws.qty_reserved) > 0
    ORDER BY pp.base_price ASC, pp.id ASC
    LIMIT 1
  ");
  $st->execute([$store_product_id]);
  $r = $st->fetch();
  return $r ?: null;
}

function provider_stock_sum(PDO $pdo, int $store_product_id): int {
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS s
    FROM store_product_sources sps
    JOIN warehouse_stock ws ON ws.provider_product_id = sps.provider_product_id
    WHERE sps.store_product_id=? AND sps.enabled=1
  ");
  $st->execute([$store_product_id]);
  return (int)($st->fetch()['s'] ?? 0);
}

function price_value_present($value): bool {
  return $value !== null && $value !== '' && is_numeric($value);
}

function current_sell_price_details(PDO $pdo, array $store, array $sp): array {
  $best = best_provider_source($pdo, (int)$sp['id']);
  $base = $best ? (float)$best['base_price'] : 0.0;
  $markupFactor = 1.0 + ((float)$store['markup_percent']/100.0);
  $ownPrice = price_value_present($sp['own_stock_price'] ?? null) ? (float)$sp['own_stock_price'] : 0.0;
  $autoPrice = $best ? ($base * $markupFactor) : ($ownPrice > 0.0 ? ($ownPrice * $markupFactor) : 0.0);

  if (price_value_present($sp['manual_price'] ?? null)) {
    $priceCalculated = (float)$sp['manual_price'];
  } else {
    $priceCalculated = $autoPrice;
  }

  $minAllowed = $base * 1.15;
  $manualPresent = price_value_present($sp['manual_price'] ?? null);
  $minApplied = ($manualPresent && $minAllowed > 0.0 && $priceCalculated < $minAllowed);
  $finalPrice = $minApplied ? $autoPrice : $priceCalculated;

  return [
    'price' => $finalPrice,
    'min_applied' => $minApplied,
    'min_allowed' => $minAllowed,
    'auto_price' => $autoPrice,
    'base_price' => $base
  ];
}

function current_sell_price(PDO $pdo, array $store, array $sp): float {
  $details = current_sell_price_details($pdo, $store, $sp);
  return (float)$details['price'];
}
?>
