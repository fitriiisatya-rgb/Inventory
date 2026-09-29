<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$me = Auth::requireLogin();
$pageTitle = 'Master Barang';
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card">
  <h3>Tambah Barang</h3>
  <form class="inline" id="createForm">
    <label>SKU <input type="text" name="sku" required></label>
    <label>Barcode <input type="text" name="barcode"></label>
    <label>Nama <input type="text" name="name" required style="width:220px;"></label>
    <label>Kategori <select name="category_id" id="categorySelect" required></select></label>
    <label>Brand <input type="text" name="brand"></label>
    <label>Distributor <input type="text" name="distributor"></label>
    <br>
    <label>Buy Unit <input type="text" name="buy_unit" placeholder="Karton" required></label>
    <label>Buy Content (base unit per 1 buy unit) <input type="number" step="any" name="buy_content" required></label>
    <label>Mid Unit (opsional) <input type="text" name="mid_unit" placeholder="Kg"></label>
    <label>Mid Content (mid unit per 1 buy unit) <input type="number" step="any" name="mid_content"></label>
    <label>Base Unit <input type="text" name="base_unit" placeholder="Gr" required></label>
    <label>Last Buy Price (per base unit) <input type="number" step="any" name="last_buy_price"></label>
    <button type="submit">Simpan</button>
  </form>
  <div id="msg"></div>
</div>

<div class="card">
  <form class="inline" id="filterForm">
    <label>Status
      <select name="status"><option value="ACTIVE">Aktif</option><option value="INACTIVE">Tidak Aktif</option><option value="ALL">Semua</option></select>
    </label>
    <label>Cari <input type="text" name="q" placeholder="SKU / nama / barcode"></label>
    <button type="submit">Filter</button>
  </form>
  <table>
    <thead><tr><th>SKU</th><th>Nama</th><th>Kategori</th><th>Konversi</th><th>Status</th><th></th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
</div>

<script>
let categories = [];

async function loadCategories() {
  const { data } = await SO.api('/api/categories.php');
  categories = data;
  document.getElementById('categorySelect').innerHTML = data.map(c => `<option value="${c.id}">${SO.escapeHtml(c.name)}</option>`).join('');
}

function conversionLabel(r) {
  let s = `1 ${r.buy_unit} = ${r.buy_content} ${r.base_unit}`;
  if (r.mid_unit) s += ` (1 ${r.buy_unit} = ${r.mid_content} ${r.mid_unit})`;
  return s;
}

async function loadItems() {
  const fd = new FormData(document.getElementById('filterForm'));
  const params = new URLSearchParams(Object.fromEntries(fd));
  const { data } = await SO.api('/api/items.php?' + params.toString());
  document.getElementById('rows').innerHTML = data.map(r => `
    <tr>
      <td>${SO.escapeHtml(r.sku)}</td>
      <td>${SO.escapeHtml(r.name)}</td>
      <td>${SO.escapeHtml(r.category_name)}</td>
      <td>${SO.escapeHtml(conversionLabel(r))}</td>
      <td><span class="badge badge-${r.status.toLowerCase()}">${r.status}</span></td>
      <td><button class="secondary" onclick="toggleStatus(${r.id})">
        ${r.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}
      </button></td>
    </tr>`).join('');
  window.__items = Object.fromEntries(data.map(r => [r.id, r]));
}

async function toggleStatus(id) {
  const r = window.__items[id];
  const payload = Object.assign({}, r, { id, status: r.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' });
  await SO.api('/api/items.php', { method: 'PUT', body: JSON.stringify(payload) });
  loadItems();
}

document.getElementById('filterForm').addEventListener('submit', (e) => { e.preventDefault(); loadItems(); });

document.getElementById('createForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const msg = document.getElementById('msg');
  msg.innerHTML = '';
  try {
    const res = await SO.api('/api/items.php', { method: 'POST', body: JSON.stringify(Object.fromEntries(fd)) });
    if (res.warnings && res.warnings.length) {
      msg.innerHTML = `<div class="warning-box">${res.warnings.map(w => SO.escapeHtml(w.message)).join('<br>')}</div>`;
    }
    e.target.reset();
    loadItems();
  } catch (err) {
    const issues = (err.data && err.data.issues) || [];
    const text = issues.length ? issues.map(i => `[${i.severity}] ${i.message}`).join('<br>') : err.message;
    msg.innerHTML = `<div class="error-box">${text}</div>`;
  }
});

loadCategories().then(loadItems);
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
