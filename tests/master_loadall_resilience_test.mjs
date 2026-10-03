// STABILIZATION — Task 1 regression: Master.loadAll() must never let one
// failing OPTIONAL list (units/divisions/bakeryDestinations/itemBarcodes)
// blank out a CORE list (items/warehouses/suppliers/categories) that its
// own API call actually succeeded at. This is the exact production
// incident: GET /units failing turned the Stock Opname warehouse dropdown
// into "No Options" even though GET /warehouses had succeeded, because the
// old code awaited a single Promise.all() over all 8 calls.
//
// Pure Node, no browser/DB — loads the real public/assets/js/master.js
// source into a fresh vm context with a stubbed InvApi so each scenario
// controls exactly which of the 8 calls resolve/reject.
//
// Run: node tests/master_loadall_resilience_test.mjs
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'master.js'), 'utf8');

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}

function loadMasterWith(invApiStub) {
    const sandbox = {
        InvApi: invApiStub,
        console,
        Promise,
    };
    vm.createContext(sandbox);
    // The script's own final expression (the IIFE result assigned to the
    // const Master) is returned by vm.runInContext when the code ends with
    // a bare reference to it — exactly like evaluating `Master` after the
    // script runs in a real <script> tag's global scope.
    return vm.runInContext(`${source}\nMaster;`, sandbox);
}

const SCM = { id: 1, name: 'SCM' };
const CIBADAK = { id: 2, name: 'Cibadak' };
const KARANG_TENGAH = { id: 3, name: 'Karang Tengah' };
const WAREHOUSES = [SCM, CIBADAK, KARANG_TENGAH];
const ITEMS = [{ id: 1, name: 'Item A' }];
const SUPPLIERS = [{ id: 1, name: 'Supplier A' }];
const CATEGORIES = [{ id: 1, name: 'Category A' }];
const UNITS = [{ id: 1, code: 'KG', name: 'Kilogram' }];

function baseStub(overrides = {}) {
    return {
        listItems: overrides.listItems || (async () => ITEMS),
        listWarehouses: overrides.listWarehouses || (async () => WAREHOUSES),
        listSuppliers: overrides.listSuppliers || (async () => SUPPLIERS),
        listCategories: overrides.listCategories || (async () => CATEGORIES),
        listDivisions: overrides.listDivisions || (async () => []),
        listBakeryDestinations: overrides.listBakeryDestinations || (async () => []),
        listItemBarcodes: overrides.listItemBarcodes || (async () => []),
        listUnits: overrides.listUnits || (async () => UNITS),
    };
}

// ---- Scenario 1: GET /units fails -> warehouses (and every other CORE
// list) must still be available, exactly reproducing + proving the fix for
// the production "No Options" dropdown incident. ----
{
    const Master = loadMasterWith(baseStub({
        listUnits: async () => { throw new Error('units endpoint 500'); },
    }));
    await Master.loadAll();
    check('1. units endpoint fails -> warehouses still available', Master.warehouses().length === 3);
    check('1b. units endpoint fails -> items/suppliers/categories (other CORE lists) still available',
        Master.items().length === 1 && Master.suppliers().length === 1 && Master.categories().length === 1);
    check('1c. units endpoint fails -> units itself degrades to an empty array, never throws', Array.isArray(Master.units()) && Master.units().length === 0);
}

// ---- Scenario 2: GET /warehouses succeeds (alongside everything else
// succeeding) -> the Stock Opname selector (which renders directly from
// Master.warehouses()) is populated with all three real warehouses. ----
{
    const Master = loadMasterWith(baseStub());
    const resultObj = await Master.loadAll();
    const names = Master.warehouses().map((w) => w.name);
    check('2. warehouses endpoint succeeds -> Stock Opname selector source has exactly SCM/Cibadak/Karang Tengah',
        names.length === 3 && names.includes('SCM') && names.includes('Cibadak') && names.includes('Karang Tengah'),
        names.join(','));
    check('2b. loadAll() return value matches the cached Master.warehouses() (same list the UI reads)',
        resultObj.warehouses.length === 3);
}

// ---- Scenario 3: units endpoint works -> Edit Barang (Base Unit/Unit
// Conversion dropdowns, via Master.units()/Master.unitById()) still
// receives the real list, completely unaffected by the resilience change. ----
{
    const Master = loadMasterWith(baseStub());
    await Master.loadAll();
    check('3. units endpoint works -> Edit Barang still receives units via Master.units()', Master.units().length === 1 && Master.units()[0].code === 'KG');
    check('3b. units endpoint works -> Master.unitById() still resolves correctly', Master.unitById(1)?.code === 'KG');
}

// ---- Scenario 4 (converse of 1): warehouses endpoint fails -> it must
// degrade to empty (never throw, never silently keep a stale list) while
// unrelated lists (units/items) are completely unaffected — proves the
// fix is symmetric across every list, not special-cased to units. ----
{
    const Master = loadMasterWith(baseStub({
        listWarehouses: async () => { throw new Error('warehouses endpoint 500'); },
    }));
    await Master.loadAll();
    check('4. warehouses endpoint fails -> degrades to empty array, never throws', Array.isArray(Master.warehouses()) && Master.warehouses().length === 0);
    check('4b. warehouses endpoint fails -> items/units (unrelated lists) still available', Master.items().length === 1 && Master.units().length === 1);
}

// ---- Scenario 5: every OPTIONAL list fails at once -> every CORE list
// still loads (the exact CORE vs OPTIONAL split Task 3 requires for the
// Master Data/Edit Barang screen). ----
{
    const Master = loadMasterWith(baseStub({
        listUnits: async () => { throw new Error('fail'); },
        listDivisions: async () => { throw new Error('fail'); },
        listBakeryDestinations: async () => { throw new Error('fail'); },
        listItemBarcodes: async () => { throw new Error('fail'); },
    }));
    await Master.loadAll();
    check('5. every OPTIONAL endpoint fails at once -> every CORE list (items/warehouses/suppliers/categories) still loads',
        Master.items().length === 1 && Master.warehouses().length === 3 && Master.suppliers().length === 1 && Master.categories().length === 1);
}

const failed = results.filter((r) => !r).length;
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed > 0 ? 1 : 0);
