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

  if (!$ppId) $err = "Elegí un proveedor.";
  else {
    $pdo->prepare("INSERT INTO store_product_sources(store_product_id,provider_product_id,enabled)
                   VALUES(?,?,1) ON DUPLICATE KEY UPDATE enabled=1")
        ->execute([$productId,$ppId]);
    $msg = "Vínculo agregado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'unlink_source') {
  $ppId = (int)($_POST['provider_product_id'] ?? 0);

  if (!$ppId) $err = "Producto inválido.";
  else {
    $pdo->prepare("DELETE FROM store_product_sources WHERE store_product_id=? AND provider_product_id=? LIMIT 1")
        ->execute([$productId,$ppId]);
    header("Location: producto.php?id=".$productId);
    exit;
  }
}

$productSt->execute([$productId,(int)$seller['id']]);
$product = $productSt->fetch();

$provStock = provider_stock_sum($pdo, (int)$product['id']);
$sell = current_sell_price($pdo, $product, $product);
$stockTotal = $provStock + (int)$product['own_stock_qty'];
$sellTxt = ($sell>0) ? '$'.number_format($sell,2,',','.') : 'Sin stock';

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
<div id='provider-link-message' style='margin-bottom:8px; color:#b00;'></div>
<form id='provider-link-form' method='post' style='position:relative; max-width:600px;'>
  <input type='hidden' name='csrf' id='provider-link-csrf' value='".h(csrf_token())."'>
  <input type='hidden' name='provider_product_id' id='provider_product_id' value=''>
  <input type='hidden' name='product_id' id='provider-store-product-id' value='".h((string)$productId)."'>
  <input type='text' id='provider-product-search' placeholder='Buscar producto del proveedor…' style='width:100%; padding:6px;'>
  <div id='provider-product-suggestions' style='display:none; position:absolute; left:0; right:0; top:38px; border:1px solid #ccc; background:#fff; z-index:5; max-height:240px; overflow:auto;'></div>
  <button type='button' id='provider-link-btn' style='margin-top:10px;'>Vincular</button>
</form>";

echo "<h3>Productos vinculados</h3>";
if (!$linkedProducts) {
  echo "<p id='linked-products-empty'>No hay productos vinculados a esta publicación.</p>";
} else {
  echo "<table id='linked-products-table' border='1' cellpadding='6' cellspacing='0'>
  <tr><th>ID</th><th>Título</th><th>SKU</th><th>Proveedor</th><th>Stock</th><th>Acciones</th></tr>";
  foreach($linkedProducts as $linked){
    $providerName = $linked['provider_name'] ?: '—';
    echo "<tr>
      <td>".h((string)$linked['id'])."</td>
      <td>".h((string)$linked['title'])."</td>
      <td>".h((string)($linked['sku'] ?? ''))."</td>
      <td>".h((string)$providerName)."</td>
      <td>".h((string)$linked['stock'])."</td>
      <td>
        <form method='post' style='margin:0' onsubmit='return confirm(\"¿Eliminar vínculo?\")'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='unlink_source'>
          <input type='hidden' name='provider_product_id' value='".h((string)$linked['id'])."'>
          <button type='submit'>Eliminar</button>
        </form>
      </td>
    </tr>";
  }
  echo "</table>";
}

echo <<<JS
<script>
(function() {
  const searchInput = document.getElementById('provider-product-search');
  const suggestionsBox = document.getElementById('provider-product-suggestions');
  const hiddenInput = document.getElementById('provider_product_id');
  const linkBtn = document.getElementById('provider-link-btn');
  const messageBox = document.getElementById('provider-link-message');
  const csrfToken = document.getElementById('provider-link-csrf').value;
  const productId = document.getElementById('provider-store-product-id').value;
  let debounceTimer = null;
  let currentItems = [];

  function escapeHtml(value) {
    return value.replace(/[&<>"']/g, function(match) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      })[match];
    });
  }

  function setMessage(text, color) {
    messageBox.textContent = text || '';
    messageBox.style.color = color || '#b00';
  }

  function renderSuggestions(items) {
    currentItems = items;
    if (!items.length) {
      suggestionsBox.innerHTML = "<div style='padding:8px; color:#666;'>Sin resultados</div>";
      suggestionsBox.style.display = 'block';
      return;
    }
    suggestionsBox.innerHTML = items.map(function(item) {
      const text = escapeHtml(item.title) + " — SKU: " + escapeHtml(item.sku || '—') +
        " — Proveedor: " + escapeHtml(item.provider_name || '—') +
        " — Stock: " + escapeHtml(String(item.stock));
      return "<div data-id='" + item.id + "' style='padding:8px; cursor:pointer; border-bottom:1px solid #eee;'>" + text + "</div>";
    }).join('');
    suggestionsBox.style.display = 'block';
  }

  function clearSelection() {
    hiddenInput.value = '';
    linkBtn.disabled = false;
  }

  function hideSuggestions() {
    suggestionsBox.style.display = 'none';
  }

  function fetchSuggestions(query) {
    const params = new URLSearchParams({
      q: query,
      product_id: productId
    });
    fetch('/vendedor/api/provider_products_search.php?' + params.toString(), {
      credentials: 'same-origin'
    })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (Array.isArray(data)) {
          renderSuggestions(data);
        } else if (data && data.error) {
          setMessage(data.error);
        }
      })
      .catch(function() {
        setMessage('No se pudo buscar.');
      });
  }

  if (searchInput) {
    searchInput.addEventListener('input', function() {
      const query = searchInput.value.trim();
      setMessage('');
      clearSelection();
      if (debounceTimer) clearTimeout(debounceTimer);
      if (query.length < 2) {
        hideSuggestions();
        return;
      }
      debounceTimer = setTimeout(function() {
        fetchSuggestions(query);
      }, 300);
    });

    suggestionsBox.addEventListener('click', function(event) {
      const target = event.target.closest('[data-id]');
      if (!target) return;
      const id = target.getAttribute('data-id');
      const item = currentItems.find(function(row) { return String(row.id) === String(id); });
      if (!item) return;
      hiddenInput.value = item.id;
      searchInput.value = item.title;
      hideSuggestions();
    });

    document.addEventListener('click', function(event) {
      if (!suggestionsBox.contains(event.target) && event.target !== searchInput) {
        hideSuggestions();
      }
    });
  }

  linkBtn.addEventListener('click', function() {
    const linkedId = hiddenInput.value;
    if (!linkedId) {
      setMessage('Seleccioná un producto del proveedor.');
      return;
    }
    linkBtn.disabled = true;
    setMessage('');
    const body = new URLSearchParams({
      product_id: productId,
      linked_product_id: linkedId,
      csrf: csrfToken
    });
    fetch('/vendedor/api/link_product.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function(res) {
        return res.json().then(function(data) {
          if (!res.ok) {
            throw data;
          }
          return data;
        });
      })
      .then(function(data) {
        if (!data || !data.ok) {
          setMessage('No se pudo vincular.');
          return;
        }
        const item = data.item;
        const emptyRow = document.getElementById('linked-products-empty');
        if (emptyRow) emptyRow.remove();
        let table = document.getElementById('linked-products-table');
        if (!table) {
          table = document.createElement('table');
          table.id = 'linked-products-table';
          table.setAttribute('border', '1');
          table.setAttribute('cellpadding', '6');
          table.setAttribute('cellspacing', '0');
          table.innerHTML = "<tr><th>ID</th><th>Título</th><th>SKU</th><th>Proveedor</th><th>Stock</th><th>Acciones</th></tr>";
          document.getElementById('provider-link-form').insertAdjacentElement('afterend', table);
        }
        const row = document.createElement('tr');
        const providerName = item.provider_name || '—';
        row.innerHTML = "<td>" + escapeHtml(String(item.id)) + "</td>" +
          "<td>" + escapeHtml(item.title) + "</td>" +
          "<td>" + escapeHtml(item.sku || '') + "</td>" +
          "<td>" + escapeHtml(providerName) + "</td>" +
          "<td>" + escapeHtml(String(item.stock)) + "</td>" +
          "<td>" +
          "<form method='post' style='margin:0' onsubmit='return confirm(\"¿Eliminar vínculo?\")'>" +
          "<input type='hidden' name='csrf' value='" + escapeHtml(csrfToken) + "'>" +
          "<input type='hidden' name='action' value='unlink_source'>" +
          "<input type='hidden' name='provider_product_id' value='" + escapeHtml(String(item.id)) + "'>" +
          "<button type='submit'>Eliminar</button>" +
          "</form>" +
          "</td>";
        table.appendChild(row);
        setMessage('Vinculado correctamente.', 'green');
        searchInput.value = '';
        hiddenInput.value = '';
        hideSuggestions();
      })
      .catch(function(err) {
        const errorMessage = err && err.error ? err.error : 'No se pudo vincular.';
        setMessage(errorMessage);
      })
      .finally(function() {
        linkBtn.disabled = false;
      });
  });
})();
</script>
JS;

page_footer();
