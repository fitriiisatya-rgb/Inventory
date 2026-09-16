/**
 * D2 — master data cache: items/warehouses/suppliers/divisions fetched from
 * the API and held in memory for the lifetime of the page only. Never
 * written to localStorage/sessionStorage, never seeded from a local
 * constant — the API is the only source, so a stale/missing master record
 * on the server is reflected here too rather than papered over.
 */
const Master = (() => {
    let items = [];
    let warehouses = [];
    let suppliers = [];
    let divisions = [];

    async function loadAll() {
        [items, warehouses, suppliers, divisions] = await Promise.all([
            InvApi.listItems(),
            InvApi.listWarehouses(),
            InvApi.listSuppliers(),
            InvApi.listDivisions(),
        ]);
        return { items, warehouses, suppliers, divisions };
    }

    const findById = (list, id) => list.find((row) => Number(row.id) === Number(id));

    return {
        loadAll,
        items: () => items,
        warehouses: () => warehouses,
        suppliers: () => suppliers,
        divisions: () => divisions,
        itemById: (id) => findById(items, id),
        warehouseById: (id) => findById(warehouses, id),
        supplierById: (id) => findById(suppliers, id),
        divisionById: (id) => findById(divisions, id),
    };
})();
