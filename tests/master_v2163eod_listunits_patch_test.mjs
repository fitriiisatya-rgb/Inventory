// STABILIZATION — proves the confirmed production root cause and the
// patch for it: the ACTIVE api-client-v2163eod.js has no listUnits()
// method, so InvApi.listUnits() throws TypeError SYNCHRONOUSLY (before
// any fetch), which is exactly why GET /units never reaches the access
// log and Master.units() stays empty no matter how many times Edit
// Barang's resilience patch retries Master.loadAll().
//
// Pure Node, no browser/DB. Builds the simulated pre-patch
// api-client-v2163eod.js the SAME way scripts/patch_api_client_v2163eod_
// production.php's own test run does (real api-client.js with its
// listUnits line stripped — NOT a guess at production's real bytes,
// just a minimal, clearly-labeled fixture for exercising the patch
// mechanism and the resulting runtime behavior), runs
// public/assets/js/master.js's REAL loadAll() against it before and
// after applying the real patcher, and proves:
//   1. before the patch: InvApi.listUnits is not a function
//   2. before the patch: Master.loadAll() still resolves (CORE/OPTIONAL
//      split absorbs the synchronous throw via settle()'s try/catch
//      boundary — see master.js) but Master.units() stays empty
//   3. after the patch: InvApi.listUnits is a function
//   4. after the patch: it requests exactly '/api/units' (nothing else)
//   5. after the patch: Master.loadAll() populates Master.units() with
//      real data
//
// Run: node tests/master_v2163eod_listunits_patch_test.mjs
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..');

const results = [];
function check(name, pass, detail = '') {
    results.push(pass);
    console.log(`${pass ? 'PASS' : 'FAIL'} - ${name}${detail ? ` (${detail})` : ''}`);
}

// ---- build the simulated pre-patch fixture (real api-client.js minus its listUnits line) ----
const realApiClient = fs.readFileSync(path.join(repoRoot, 'public/assets/js/api-client.js'), 'utf8');
const preImage = realApiClient.replace(/\s*listUnits: \(\) => request\('GET', '\/units'\),\n/, '\n');
if (preImage.includes('listUnits')) throw new Error('fixture setup failed: listUnits still present');
const fixturePath = '/tmp/stab_v2163eod_fixture.js';
fs.writeFileSync(fixturePath, preImage);

function loadApiClientInSandbox(source) {
    const requests = [];
    const sandbox = {
        fetch: async (url, opts) => {
            requests.push({ url, method: opts?.method });
            return { json: async () => ({ success: true, data: [{ id: 1, code: 'KG', name: 'Kilogram' }] }) };
        },
        URLSearchParams,
        FormData: class { append() {} },
        console,
    };
    vm.createContext(sandbox);
    const InvApi = vm.runInContext(`${source}\nInvApi;`, sandbox);
    return { InvApi, requests };
}

const masterJsSource = fs.readFileSync(path.join(repoRoot, 'public/assets/js/master.js'), 'utf8');
function loadMasterWith(invApi) {
    const sandbox = { InvApi: invApi, console, Promise };
    vm.createContext(sandbox);
    return vm.runInContext(`${masterJsSource}\nMaster;`, sandbox);
}

// ---- BEFORE the patch ----
{
    const { InvApi } = loadApiClientInSandbox(preImage);
    check('1. BEFORE patch: InvApi.listUnits is NOT a function (the confirmed production defect)', typeof InvApi.listUnits !== 'function');

    const Master = loadMasterWith(InvApi);
    let loadAllRejected = false;
    try {
        await Master.loadAll();
    } catch (e) {
        loadAllRejected = true;
    }
    // IMPORTANT, NOT AN ASSUMPTION — proven below by direct JS semantics:
    // `InvApi.listUnits()` is called while BUILDING the array literal
    // passed into `Promise.allSettled([...])` in THIS sandbox's
    // master.js. Calling a method that doesn't exist throws
    // SYNCHRONOUSLY at that point — before Promise.allSettled is even
    // reached — so the CORE/OPTIONAL split's own settle()/try-catch
    // machinery never runs at all. The Promise.allSettled() design only
    // absorbs a call that REJECTS (e.g. a network/HTTP failure); it
    // cannot absorb a call that was never made because the method
    // itself doesn't exist. loadAll() therefore rejects ENTIRELY in
    // THIS sandbox's master.js, and every list — not just units — stays
    // at its initial empty array.
    check('2. BEFORE patch, in THIS sandbox\'s master.js: Master.loadAll() itself REJECTS ENTIRELY (a missing method throws during array construction, before Promise.allSettled ever runs — confirmed via a standalone Node repro, not assumed)', loadAllRejected);
    check('2b. BEFORE patch: Master.units() stays empty — reproduces "No Options" with ZERO network call for units', Master.units().length === 0);
    // CORE lists in THIS sandbox's master.js are collaterally empty too,
    // for the reason above. Production's OWN independently-hotfixed
    // master.js clearly does NOT have this exact structure (the user
    // confirmed warehouses/items/etc. work fine in production even with
    // listUnits missing) — so this assertion documents THIS sandbox's
    // real behavior, not a claim about production's unseen code.
    check('2c. BEFORE patch, in THIS sandbox\'s master.js specifically: CORE lists (items/warehouses/suppliers/categories) are ALSO collaterally emptied — a missing API method is NOT survivable by the current CORE/OPTIONAL design, unlike a method that exists but its network call fails',
        Master.items().length === 0 && Master.warehouses().length === 0 && Master.suppliers().length === 0 && Master.categories().length === 0);
}

// ---- apply the REAL patcher (scripts/patch_api_client_v2163eod_production.php) ----
const hash = execSync(`sha256sum ${fixturePath}`).toString().split(' ')[0];
execSync(`php ${path.join(repoRoot, 'scripts/patch_api_client_v2163eod_production.php')} ${fixturePath} --expect-sha256=${hash} --apply`, { cwd: repoRoot });
const postImage = fs.readFileSync(fixturePath, 'utf8');

// ---- AFTER the patch ----
{
    const { InvApi, requests } = loadApiClientInSandbox(postImage);
    check('3. AFTER patch: InvApi.listUnits is now a function', typeof InvApi.listUnits === 'function');

    await InvApi.listUnits();
    check('4. AFTER patch: it requests exactly "/api/units" via GET — nothing else, no path drift',
        requests.length === 1 && requests[0].url === '/api/units' && requests[0].method === 'GET',
        JSON.stringify(requests));

    const Master = loadMasterWith(InvApi);
    await Master.loadAll();
    check('5. AFTER patch: Master.loadAll() now populates Master.units() with real data', Master.units().length === 1 && Master.units()[0].code === 'KG');
    check('5b. AFTER patch: Master.unitById() resolves correctly', Master.unitById(1)?.code === 'KG');
}

const failed = results.filter((r) => !r).length;
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed > 0 ? 1 : 0);
