/**
 * PHASE V2.10 — reusable searchable item selector (Part F). One component,
 * mounted once per Stock IN / Stock OUT "Barang" step, that owns: text
 * search (SKU/name/barcode), the results dropdown, keyboard navigation,
 * hardware-scanner Enter handling, camera barcode scanning, and the
 * coupled Satuan <select> (unit choice needs the resolved item's units,
 * so keeping them in one component avoids a second, separately-wired
 * dropdown falling out of sync with the selected item).
 *
 * SEARCH (Part A4): pure client-side filter over Master.items() /
 * Master.itemBarcodes() — both already fully loaded once by Master.loadAll()
 * at page load. No new search endpoint, no per-keypress network request.
 *
 * SELECTION STATE (Part A3/G): the component tracks its own itemId/unitId
 * and calls back via onChange() with a `valid` flag. Editing the text after
 * a selection immediately invalidates it (itemId/unitId cleared, valid:
 * false) — the caller must not allow POST while valid is false. Selecting a
 * different item always replaces the entire {itemId, unitId, units} state,
 * never merges with the previous item's leftovers.
 *
 * SECURITY (Part I): this component is a SELECTION AID ONLY. Nothing here
 * enforces permissions or business rules — POST /transactions/in|out still
 * independently validates item/unit/warehouse/price exactly as before,
 * regardless of whether item_id got there by typing or by scanning.
 */
const ItemSelector = (() => {
    const MSG_PICK_FROM_RESULTS = 'Pilih barang dari hasil pencarian.';
    const MSG_BARCODE_UNKNOWN = 'Barcode tidak terdaftar.';
    const MSG_ITEM_INACTIVE = 'Barang tidak aktif.';
    const MSG_MAPPING_INACTIVE = 'Barcode tidak aktif.';
    const MSG_CAMERA_UNSUPPORTED = 'Pemindaian kamera tidak didukung di perangkat ini. Gunakan scanner barcode atau ketik SKU/nama barang.';
    const MAX_RESULTS = 20;

    // ---- pure search over already-loaded master data (Part A1) ----
    function search(query) {
        const q = String(query || '').trim().toLowerCase();
        if (!q) return [];

        const byItemId = new Map(); // item.id -> best rank found (lower = better)
        const items = Master.items();
        const itemsById = new Map(items.map((i) => [Number(i.id), i]));

        const consider = (item, rank) => {
            if (!item || item.status !== 'ACTIVE') return;
            const existing = byItemId.get(item.id);
            if (existing === undefined || rank < existing) byItemId.set(item.id, rank);
        };

        items.forEach((item) => {
            const sku = String(item.sku || '').toLowerCase();
            const name = String(item.name || '').toLowerCase();
            const barcode = String(item.barcode || '').toLowerCase();
            if (sku === q || (barcode && barcode === q)) consider(item, 0);
            else if (sku.startsWith(q)) consider(item, 1);
            else if (name.includes(q)) consider(item, 2);
            else if (sku.includes(q)) consider(item, 3);
        });

        // Multi-unit barcode mappings (Part C) — active only for search
        // ranking purposes (an inactive mapping is still resolvable via
        // exact-Enter/scan, handled separately in resolveBarcode()).
        Master.itemBarcodes().forEach((b) => {
            if (!b.is_active) return;
            if (String(b.barcode || '').toLowerCase() === q) {
                consider(itemsById.get(Number(b.item_id)), 0);
            }
        });

        return Array.from(byItemId.entries())
            .sort((a, b) => {
                if (a[1] !== b[1]) return a[1] - b[1];
                const ia = itemsById.get(a[0]);
                const ib = itemsById.get(b[0]);
                return String(ia.name).localeCompare(String(ib.name));
            })
            .slice(0, MAX_RESULTS)
            .map(([id]) => itemsById.get(id));
    }

    /**
     * Resolve one exact barcode value (hardware scanner Enter, or a camera
     * detection result) against Master.itemBarcodes() — never a fuzzy
     * match, never falls back to a "similar" item (Part C8).
     */
    function resolveBarcode(rawValue) {
        const value = String(rawValue || '').trim();
        if (!value) return { status: 'UNKNOWN' };

        const mappings = Master.itemBarcodes().filter((b) => String(b.barcode) === value);
        if (mappings.length === 0) return { status: 'UNKNOWN' };

        // Prefer an active mapping if one exists among same-value rows
        // (mirrors the backend's uq_item_barcodes_active guarantee — there
        // can be at most one active one — but stay defensive here too).
        const mapping = mappings.find((b) => b.is_active) || mappings[0];
        if (!mapping.is_active) return { status: 'MAPPING_INACTIVE' };

        const item = Master.itemById(mapping.item_id);
        if (!item || item.status !== 'ACTIVE') return { status: 'ITEM_INACTIVE' };

        return { status: 'OK', item, unitId: mapping.unit_id !== null && mapping.unit_id !== undefined ? Number(mapping.unit_id) : null };
    }

    function barcodeFailureMessage(status) {
        if (status === 'ITEM_INACTIVE') return MSG_ITEM_INACTIVE;
        if (status === 'MAPPING_INACTIVE') return MSG_MAPPING_INACTIVE;
        return MSG_BARCODE_UNKNOWN;
    }

    /**
     * @param {HTMLElement} hostEl container to render into (emptied first)
     * @param {{initialItemId?:number, initialUnitId?:number, showUnit?:boolean, showLabel?:boolean, compact?:boolean, externalUnitHost?:HTMLElement,
     *          onChange:(state:{itemId:?number, unitId:?number, item:?object, units:list, valid:boolean})=>void}} opts
     *   showUnit (default true) — when false, no "Satuan" sub-selector is
     *   rendered and InvApi.itemUnits() is never called for this instance;
     *   for callers (Stock Adjustment, Kartu Stok filter) that only ever
     *   need item_id and have no unit concept of their own. unitId/units in
     *   the emitted state stay null/[] in that mode.
     *   showLabel (default true) — when false, skips the "Barang"/"Satuan"
     *   <label> text and the .form-group wrapper's margin, for embedding
     *   inside a compact table cell whose column header already names the
     *   field (PHASE V2.14.8 — Transfer/Production row tables).
     *   compact (default false) — slightly tighter input padding, for the
     *   same table-cell embedding.
     */
    function mount(hostEl, opts) {
        const idPrefix = `isel-${Math.random().toString(36).slice(2, 9)}`;
        const showUnit = opts.showUnit !== false;
        const showLabel = opts.showLabel !== false;
        const compact = !!opts.compact;
        let itemId = opts.initialItemId || null;
        let unitId = opts.initialUnitId || null;
        let units = [];
        let valid = !!itemId;
        let results = [];
        let highlightIndex = -1;
        let destroyed = false;
        let cameraStop = null;

        hostEl.innerHTML = '';
        const wrap = UI.el('div', { class: 'item-selector' });
        const inputWrap = UI.el('div', { class: 'item-selector-input-wrap' });
        const input = UI.el('input', {
            type: 'text', class: 'item-selector-input', autocomplete: 'off',
            placeholder: 'Ketik SKU, nama barang, atau scan barcode...',
        });
        const cameraSupported = 'mediaDevices' in navigator && 'BarcodeDetector' in window;
        const scanBtn = UI.el('button', {
            type: 'button', class: 'item-selector-scan-btn',
            title: cameraSupported ? 'Scan Barcode (kamera)' : MSG_CAMERA_UNSUPPORTED,
        }, '📷');
        // PHASE V2.14.8 — the dropdown is appended to document.body (never
        // to inputWrap) and positioned with `position:fixed` computed from
        // the input's own live bounding rect. A mounted-inside-a-table
        // instance (Transfer/Production's compact rows) sits inside an
        // `overflow-y:auto` scroll body — an absolutely-positioned child
        // would be silently clipped by that ancestor's overflow, and would
        // never be able to escape the table row's own height either. Body-
        // reparenting sidesteps both problems for every caller, old and
        // new (Stock IN/OUT, the Cutover modal, etc. behave identically).
        const dropdown = UI.el('div', { class: 'item-selector-dropdown item-selector-dropdown-floating' });
        dropdown.style.display = 'none';
        inputWrap.appendChild(input);
        inputWrap.appendChild(scanBtn);
        const errorNode = UI.el('div', { class: 'item-selector-error' });
        errorNode.style.display = 'none';

        // PHASE V2.14.8 — externalUnitHost: when set, the "Satuan" <select>
        // is appended THERE instead of stacked under the item input — used
        // by the Transfer/Production compact tables, whose header already
        // has a separate "Satuan" column (mounting both pieces in one
        // table cell would blow past the ~44-56px row-height target).
        // Unit-fetching/wiring logic is unchanged either way.
        let unitGroup = null;
        let unitSelect = null;
        if (showUnit) {
            unitSelect = UI.el('select', { class: 'item-selector-unit' });
            if (opts.externalUnitHost) {
                opts.externalUnitHost.innerHTML = '';
                opts.externalUnitHost.appendChild(unitSelect);
            } else {
                unitGroup = UI.el('div', { class: showLabel ? 'form-group' : 'form-group form-group-compact' },
                    showLabel ? [UI.el('label', {}, 'Satuan')] : []);
                unitGroup.appendChild(unitSelect);
            }
        }

        const barangGroupChildren = showLabel ? [UI.el('label', {}, 'Barang'), inputWrap, errorNode] : [inputWrap, errorNode];
        wrap.appendChild(UI.el('div', { class: showLabel ? 'form-group' : 'form-group form-group-compact' }, barangGroupChildren));
        if (unitGroup) wrap.appendChild(unitGroup);
        if (compact) wrap.classList.add('item-selector-compact');
        hostEl.appendChild(wrap);

        function emitChange() {
            if (destroyed) return;
            opts.onChange({ itemId, unitId, item: itemId ? Master.itemById(itemId) : null, units, valid });
        }

        function showError(message) {
            if (!message) { errorNode.style.display = 'none'; errorNode.textContent = ''; return; }
            errorNode.textContent = message;
            errorNode.style.display = 'block';
        }

        function closeDropdown() {
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            if (dropdown.parentNode) dropdown.parentNode.removeChild(dropdown);
            highlightIndex = -1;
        }

        // Positions the body-attached dropdown against the input's live
        // bounding rect — flips above the input when there isn't enough
        // room below (common for a row near the bottom of a scrollable
        // table body), and clamps horizontally so it never overflows the
        // right edge of the viewport on a narrow/iPad screen.
        function positionDropdown() {
            const rect = input.getBoundingClientRect();
            const viewportH = window.innerHeight;
            const viewportW = window.innerWidth;
            const maxDropdownH = 280;
            const spaceBelow = viewportH - rect.bottom;
            const openAbove = spaceBelow < maxDropdownH && rect.top > spaceBelow;
            const width = Math.max(rect.width, 220);
            const left = Math.min(rect.left, viewportW - width - 8);
            dropdown.style.left = `${Math.max(8, left)}px`;
            dropdown.style.width = `${width}px`;
            if (openAbove) {
                dropdown.style.top = 'auto';
                dropdown.style.bottom = `${viewportH - rect.top + 6}px`;
            } else {
                dropdown.style.bottom = 'auto';
                dropdown.style.top = `${rect.bottom + 6}px`;
            }
        }

        function renderDropdown() {
            dropdown.innerHTML = '';
            if (results.length === 0) {
                dropdown.appendChild(UI.el('div', { class: 'item-selector-empty' }, 'Tidak ditemukan.'));
            } else {
                results.forEach((item, idx) => {
                    const row = UI.el('div', {
                        class: `item-selector-option${idx === highlightIndex ? ' active' : ''}`,
                    }, [
                        UI.el('span', { class: 'item-selector-option-sku' }, item.sku),
                        UI.el('span', { class: 'item-selector-option-name' }, item.name),
                    ]);
                    row.addEventListener('mousedown', (e) => {
                        // mousedown (not click) fires before the input's blur
                        // handler would otherwise close the dropdown first.
                        e.preventDefault();
                        selectItem(item, null);
                    });
                    dropdown.appendChild(row);
                });
            }
            if (!dropdown.parentNode) document.body.appendChild(dropdown);
            positionDropdown();
            dropdown.style.display = 'block';
        }

        async function loadUnitsForItem(preferredUnitId) {
            if (!showUnit) { units = []; unitId = null; return; }
            unitSelect.innerHTML = '<option>Memuat...</option>';
            try {
                units = await InvApi.itemUnits(itemId);
                // STABILIZATION_TRANSFER_UNIT_BASE_BACKFILL — GET /items/{id}/units
                // only ever returns item_unit_conversions rows (by design —
                // that table holds "the purchase/middle unit being defined",
                // not the base unit). The item's own base unit therefore
                // silently drops out of every unit picker built on this
                // endpoint whenever the item has at least one real
                // conversion defined. Backfill it here, once, for every
                // ItemSelector-based screen (Transfer included) — appended,
                // never prepended, so today's default-selected option
                // (first item, per the endpoint's own ORDER BY) is
                // unchanged; skipped entirely if the base unit already has
                // its own open conversion row, so it's never duplicated.
                const selectedItem = Master.itemById(itemId);
                const baseUnitId = selectedItem ? selectedItem.base_unit_id : null;
                if (baseUnitId !== null && baseUnitId !== undefined
                    && !units.some((u) => String(u.id) === String(baseUnitId))) {
                    const baseUnit = Master.unitById(baseUnitId);
                    if (baseUnit) {
                        units = units.concat([{
                            id: baseUnit.id,
                            code: baseUnit.code,
                            name: baseUnit.name,
                            conversion_to_base: 1,
                            is_purchase_default: false,
                            reference_price: null,
                            price_source: null,
                        }]);
                    }
                }
                unitSelect.innerHTML = units.map((u) => `<option value="${u.id}">${u.code} (${u.name})</option>`).join('')
                    || '<option value="">(belum ada satuan terdaftar)</option>';
                if (preferredUnitId !== null && preferredUnitId !== undefined
                    && units.some((u) => String(u.id) === String(preferredUnitId))) {
                    unitSelect.value = String(preferredUnitId);
                }
                unitId = unitSelect.value || null;
            } catch (err) {
                UI.handleApiError(err);
                unitSelect.innerHTML = '<option value="">Gagal memuat satuan</option>';
                units = [];
                unitId = null;
            }
        }

        async function selectItem(item, preferredUnitId) {
            showError(null);
            input.value = `${item.sku} — ${item.name}`;
            itemId = item.id;
            valid = true;
            closeDropdown();
            await loadUnitsForItem(preferredUnitId);
            emitChange();
        }

        function invalidateSelection() {
            if (itemId === null && valid === false) return;
            itemId = null;
            unitId = null;
            units = [];
            valid = false;
            if (unitSelect) unitSelect.innerHTML = '';
            emitChange();
        }

        async function applyBarcodeResolution(value) {
            const resolution = resolveBarcode(value);
            if (resolution.status !== 'OK') {
                showError(barcodeFailureMessage(resolution.status));
                UI.toast(barcodeFailureMessage(resolution.status), 'error');
                return false;
            }
            await selectItem(resolution.item, resolution.unitId);
            return true;
        }

        async function handleEnter() {
            if (highlightIndex >= 0 && results[highlightIndex]) {
                await selectItem(results[highlightIndex], null);
                return;
            }
            const raw = input.value.trim();
            if (!raw) return;

            // 1) exact barcode (legacy items.barcode OR item_barcodes mapping)
            const exactBarcode = Master.itemBarcodes().some((b) => String(b.barcode) === raw)
                || Master.items().some((i) => String(i.barcode || '') === raw && raw !== '');
            if (exactBarcode) {
                await applyBarcodeResolution(raw);
                return;
            }

            // 2) exact SKU (case-insensitive)
            const skuMatch = Master.items().find((i) => i.status === 'ACTIVE' && String(i.sku).toLowerCase() === raw.toLowerCase());
            if (skuMatch) {
                await selectItem(skuMatch, null);
                return;
            }

            // 3) exactly one live suggestion currently shown
            if (results.length === 1) {
                await selectItem(results[0], null);
                return;
            }

            // 4) nothing resolvable — treat as an unrecognized scan (the
            // most common real-world cause of a plain Enter with no match).
            if (results.length === 0) {
                showError(MSG_BARCODE_UNKNOWN);
                UI.toast(MSG_BARCODE_UNKNOWN, 'error');
            }
        }

        input.addEventListener('input', () => {
            if (itemId !== null) invalidateSelection();
            showError(null);
            results = search(input.value);
            highlightIndex = -1;
            if (input.value.trim()) renderDropdown();
            else closeDropdown();
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (dropdown.style.display === 'none' && input.value.trim()) { results = search(input.value); renderDropdown(); }
                if (results.length === 0) return;
                highlightIndex = Math.min(highlightIndex + 1, results.length - 1);
                renderDropdown();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (results.length === 0) return;
                highlightIndex = Math.max(highlightIndex - 1, 0);
                renderDropdown();
            } else if (e.key === 'Enter') {
                // Hardware scanners emit Enter after the barcode digits —
                // this must resolve the item, never submit a surrounding form.
                e.preventDefault();
                e.stopPropagation();
                handleEnter();
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        function onDocumentMousedown(e) {
            if (destroyed) return;
            // dropdown is a document.body sibling now (not a descendant of
            // wrap), so a click on one of its own option rows must not be
            // treated as "outside" — selectItem()'s own mousedown handler
            // (bound directly to the row) already ran first in the bubble
            // phase; this only needs to catch genuinely outside clicks.
            if (!wrap.contains(e.target) && !dropdown.contains(e.target)) closeDropdown();
        }
        document.addEventListener('mousedown', onDocumentMousedown);

        // Scrolling any ancestor (e.g. a compact table's own scrollable
        // body) would otherwise leave the floating dropdown visually
        // detached from the input it belongs to — closing it on any
        // scroll (capture:true catches scrolling on any element, not just
        // window) is the simplest correct behavior, same convention a
        // native <select> or browser autocomplete uses.
        function onAncestorScroll(e) {
            if (destroyed || dropdown.style.display === 'none') return;
            if (e.target === input || e.target.contains?.(input)) return;
            closeDropdown();
        }
        window.addEventListener('scroll', onAncestorScroll, true);
        window.addEventListener('resize', onAncestorScroll);

        if (unitSelect) {
            unitSelect.addEventListener('change', () => {
                unitId = unitSelect.value || null;
                emitChange();
            });
        }

        scanBtn.addEventListener('click', () => openCameraModal());

        function openCameraModal() {
            if (!cameraSupported) {
                Modal.alert({ title: 'Scan Barcode', message: MSG_CAMERA_UNSUPPORTED });
                return;
            }
            const video = UI.el('video', { autoplay: 'autoplay', playsinline: 'playsinline', muted: 'muted', class: 'item-selector-camera-video' });
            const statusLine = UI.el('div', { class: 'item-selector-camera-status' }, 'Meminta izin kamera...');
            const cancelBtn = UI.el('button', { class: 'btn btn-secondary' }, 'Batal');
            const overlay = UI.el('div', { class: 'modal open' });
            const content = UI.el('div', { class: 'modal-content item-selector-camera-modal' }, [
                UI.el('h3', {}, 'Scan Barcode'),
                video,
                statusLine,
                UI.el('div', { style: 'display:flex; gap:10px; justify-content:flex-end; margin-top:14px;' }, [cancelBtn]),
            ]);
            overlay.appendChild(content);
            document.body.appendChild(overlay);

            let stream = null;
            let pollHandle = null;
            let stopped = false;

            const stopCamera = () => {
                if (stopped) return;
                stopped = true;
                if (pollHandle) clearInterval(pollHandle);
                if (stream) stream.getTracks().forEach((t) => t.stop());
                document.removeEventListener('visibilitychange', onVisibilityChange);
                overlay.remove();
                cameraStop = null;
            };
            cameraStop = stopCamera;

            const onVisibilityChange = () => { if (document.hidden) stopCamera(); };
            document.addEventListener('visibilitychange', onVisibilityChange);
            cancelBtn.addEventListener('click', stopCamera);
            overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) stopCamera(); });

            (async () => {
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                } catch (err) {
                    statusLine.textContent = 'Izin kamera ditolak atau kamera tidak tersedia.';
                    return;
                }
                if (stopped) { stream.getTracks().forEach((t) => t.stop()); return; }
                video.srcObject = stream;
                statusLine.textContent = 'Arahkan kamera ke barcode...';

                let detector;
                try {
                    detector = new window.BarcodeDetector();
                } catch (err) {
                    statusLine.textContent = MSG_CAMERA_UNSUPPORTED;
                    return;
                }

                pollHandle = setInterval(async () => {
                    if (stopped) return;
                    try {
                        const detections = await detector.detect(video);
                        if (detections && detections.length > 0 && !stopped) {
                            const value = detections[0].rawValue;
                            stopCamera();
                            await applyBarcodeResolution(value);
                        }
                    } catch (err) { /* transient decode failure — keep polling */ }
                }, 300);
            })();
        }

        if (itemId) {
            const existingItem = Master.itemById(itemId);
            if (existingItem) {
                input.value = `${existingItem.sku} — ${existingItem.name}`;
                loadUnitsForItem(unitId);
            }
        }

        return {
            getState: () => ({ itemId, unitId, item: itemId ? Master.itemById(itemId) : null, units, valid }),
            invalidate: invalidateSelection,
            focus: () => input.focus(),
            // Every mount() must be independently disposable: removes the
            // document-level outside-click listener and the window-level
            // scroll/resize listeners (the leaks that outlive this
            // instance's own DOM, since none of them are registered on
            // `wrap`), stops any in-progress camera stream, closes any open
            // dropdown (which also detaches it from document.body — see
            // closeDropdown()), and clears result/highlight state.
            // Input/keydown/unit-change listeners are all bound directly to
            // elements inside `wrap` — once the caller removes hostEl/wrap
            // from the document (which every caller does immediately after
            // calling destroy(), same as DistributionOrders'
            // remountQuickAddSelector() already established), those die
            // with the DOM node and need no separate teardown. Safe to
            // call multiple times.
            destroy: () => {
                if (destroyed) return;
                destroyed = true;
                if (cameraStop) cameraStop();
                document.removeEventListener('mousedown', onDocumentMousedown);
                window.removeEventListener('scroll', onAncestorScroll, true);
                window.removeEventListener('resize', onAncestorScroll);
                closeDropdown();
                results = [];
                hostEl.innerHTML = '';
                if (opts.externalUnitHost) opts.externalUnitHost.innerHTML = '';
            },
        };
    }

    return { mount, search, resolveBarcode, MESSAGES: {
        PICK_FROM_RESULTS: MSG_PICK_FROM_RESULTS,
        BARCODE_UNKNOWN: MSG_BARCODE_UNKNOWN,
        ITEM_INACTIVE: MSG_ITEM_INACTIVE,
        MAPPING_INACTIVE: MSG_MAPPING_INACTIVE,
        CAMERA_UNSUPPORTED: MSG_CAMERA_UNSUPPORTED,
    } };
})();
