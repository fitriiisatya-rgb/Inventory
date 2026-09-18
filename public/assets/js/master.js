/**
 * D2 — master data cache: items/warehouses/suppliers/divisions fetched from
 * the API and held in memory for the lifetime of the page only. Never
 * written to localStorage/sessionStorage, never seeded from a local
 * constant — the API is the only source, so a stale/missing master record
 * on the server is reflected here too rather than papered over.
 *
 * PHASE V2: extended with categories and bakery_destinations — same
 * read-only-cache convention, same loadAll() call.
 */
const Master = (() => {
    let items = [];
    let warehouses = [];
    let suppliers = [];
    let divisions = [];
    let categories = [];
    let bakeryDestinations = [];

    async function loadAll() {
        [items, warehouses, suppliers, divisions, categories, bakeryDestinations] = await Promise.all([
            InvApi.listItems(),
            InvApi.listWarehouses(),
            InvApi.listSuppliers(),
            InvApi.listDivisions(),
            InvApi.listCategories(),
            InvApi.listBakeryDestinations(),
        ]);
        return { items, warehouses, suppliers, divisions, categories, bakeryDestinations };
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
        itemById: (id) => findById(items, id),
        warehouseById: (id) => findById(warehouses, id),
        supplierById: (id) => findById(suppliers, id),
        divisionById: (id) => findById(divisions, id),
        categoryById: (id) => findById(categories, id),
        bakeryDestinationById: (id) => findById(bakeryDestinations, id),
    };
})();
