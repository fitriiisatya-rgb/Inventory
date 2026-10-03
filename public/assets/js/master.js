/**
 * D2 — master data cache: items/warehouses/suppliers/divisions fetched from
 * the API and held in memory for the lifetime of the page only. Never
 * written to localStorage/sessionStorage, never seeded from a local
 * constant — the API is the only source, so a stale/missing master record
 * on the server is reflected here too rather than papered over.
 *
 * PHASE V2: extended with categories and bakery_destinations — same
 * read-only-cache convention, same loadAll() call.
 *
 * PHASE V2.10: extended with itemBarcodes (multi-unit barcode mappings) —
 * loaded once here (ALL rows, active+inactive, same as every other list in
 * this cache) so ItemSelector's client-side search/scan resolution never
 * needs a per-keystroke or per-scan network round trip (Part A4).
 *
 * STABILIZATION (production incident): loadAll() used to fetch all 8 lists
 * via a single Promise.all() — ONE failing call (e.g. GET /units returning
 * a transient error, or a deployment where the frontend shipped slightly
 * ahead of a new backend route) rejected the WHOLE Promise.all, so the
 * destructuring assignment below it never ran at all: items/warehouses/
 * suppliers/categories silently stayed at their initial empty arrays too,
 * even though their own API calls had actually succeeded. That is exactly
 * how the Stock Opname warehouse dropdown went to "No Options" in
 * production — not a warehouses problem at all, but an unrelated list's
 * failure taking every other list down with it.
 *
 * Fixed with Promise.allSettled(): every list loads independently. CORE
 * lists (items/warehouses/suppliers/categories) are what the rest of the
 * app cannot function without; OPTIONAL lists (units/divisions/bakery
 * destinations/item barcodes) degrade to an empty array on failure rather
 * than ever being allowed to blank out a CORE list. Every failure is still
 * surfaced via console.error (never silently swallowed) so a real backend
 * problem stays visible to whoever is debugging, without taking down
 * screens that don't even use the list that failed.
 */
const Master = (() => {
    let items = [];
    let warehouses = [];
    let suppliers = [];
    let divisions = [];
    let categories = [];
    let bakeryDestinations = [];
    let itemBarcodes = [];
    let units = [];

    function settle(result, label) {
        if (result.status === 'fulfilled') {
            return result.value;
        }
        // eslint-disable-next-line no-console
        console.error(`Master.loadAll(): "${label}" failed to load — degrading to an empty list rather than blocking every other master list.`, result.reason);
        return [];
    }

    async function loadAll() {
        const [
            itemsR, warehousesR, suppliersR, categoriesR,
            divisionsR, bakeryR, barcodesR, unitsR,
        ] = await Promise.allSettled([
            InvApi.listItems(),
            InvApi.listWarehouses(),
            InvApi.listSuppliers(),
            InvApi.listCategories(),
            InvApi.listDivisions(),
            InvApi.listBakeryDestinations(),
            InvApi.listItemBarcodes(),
            InvApi.listUnits(),
        ]);

        // CORE — every other screen assumes these are populated whenever
        // their own API call actually succeeded, regardless of what else
        // failed alongside them.
        items = settle(itemsR, 'items');
        warehouses = settle(warehousesR, 'warehouses');
        suppliers = settle(suppliersR, 'suppliers');
        categories = settle(categoriesR, 'categories');
        // OPTIONAL — a failure here degrades only the feature that needed
        // it (e.g. Unit Conversion/HPP dropdowns go empty, Edit Barang
        // still opens) and never touches the CORE lists above.
        divisions = settle(divisionsR, 'divisions');
        bakeryDestinations = settle(bakeryR, 'bakeryDestinations');
        itemBarcodes = settle(barcodesR, 'itemBarcodes');
        units = settle(unitsR, 'units');

        return { items, warehouses, suppliers, divisions, categories, bakeryDestinations, itemBarcodes, units };
    }

    const findById = (list, id) => list.find((row) => Number(row.id) === Number(id));

    return {
        loadAll,
        items: () => items,
        warehouses: () => warehouses,
        suppliers: () => suppliers,
        divisions: () => divisions,
        categories: () => categories,
        bakeryDestinations: () => bakeryDestinations,
        itemBarcodes: () => itemBarcodes,
        units: () => units,
        itemById: (id) => findById(items, id),
        warehouseById: (id) => findById(warehouses, id),
        supplierById: (id) => findById(suppliers, id),
        divisionById: (id) => findById(divisions, id),
        categoryById: (id) => findById(categories, id),
        bakeryDestinationById: (id) => findById(bakeryDestinations, id),
        unitById: (id) => findById(units, id),
        // Re-fetch just the barcode list (after a create/edit in Master
        // Barang's barcode manager) without re-loading every other cache.
        async reloadItemBarcodes() {
            itemBarcodes = await InvApi.listItemBarcodes();
            return itemBarcodes;
        },
    };
})();
