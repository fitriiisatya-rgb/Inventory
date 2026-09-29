<?php
declare(strict_types=1);

/**
 * Builds the mandatory go-live Excel report for one session: Ringkasan,
 * Detail SO, Rusak, Expired, Deadstock, Petugas. Read-only — queries the
 * same data ReconciliationService/FinalizationService already expose,
 * just reshaped into flat rows for ExcelExportService.
 */
final class SessionReportService
{
    public function __construct(private PDO $pdo, private ReconciliationService $recon, private FinalizationService $fin)
    {
    }

    /**
     * Shared raw data behind both the Excel workbook and the print/PDF
     * view — one query path, two renderings.
     * @return array{session:array, rows:array<int,array>, petugas:array<int,array>}
     */
    public function getReportData(int $sessionId): array
    {
        $session = $this->findSession($sessionId);
        if (!$session) {
            throw new RuntimeException('Session tidak ditemukan.');
        }

        $rows = $this->recon->listForReview($sessionId);
        $withFinal = array_map(function (array $r) {
            $r['final'] = $this->fin->currentFinal((int) $r['session_item']['id']);
            return $r;
        }, $rows);

        return ['session' => $session, 'rows' => $withFinal, 'petugas' => $this->petugasList($sessionId)];
    }

    public function buildWorkbook(int $sessionId): ExcelExportService
    {
        $data = $this->getReportData($sessionId);
        $session = $data['session'];
        $withFinal = $data['rows'];

        $excel = new ExcelExportService();
        $excel->addSheet('Ringkasan', ...$this->ringkasanSheet($session, $withFinal));
        $excel->addSheet('Detail SO', ...$this->detailSheet($withFinal));
        $excel->addSheet('Rusak', ...$this->conditionSheet($withFinal, 'damaged', 'final_damaged_base_qty'));
        $excel->addSheet('Expired', ...$this->conditionSheet($withFinal, 'expired', 'final_expired_base_qty'));
        $excel->addSheet('Deadstock', ...$this->conditionSheet($withFinal, 'deadstock', 'final_deadstock_base_qty'));
        $excel->addSheet('Petugas', ...$this->petugasSheet($sessionId));
        return $excel;
    }

    private function findSession(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, l.name AS location_name, c.name AS category_name
             FROM stock_opname_sessions s
             JOIN locations l ON l.id = s.location_id
             LEFT JOIN categories c ON c.id = s.category_id
             WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array{0:array<int,string>,1:array<int,array<int,mixed>>} */
    private function ringkasanSheet(array $session, array $rows): array
    {
        $counts = [
            'MATCH' => 0, 'MISMATCH' => 0, 'CONDITION_MISMATCH' => 0, 'PARTIAL' => 0,
            'BELUM_DIHITUNG' => 0, 'RECOUNT_REQUIRED' => 0, 'NOT_COUNTABLE' => 0,
        ];
        $totalVariancePhysicalValue = 0.0;
        $totalVarianceAvailableValue = 0.0;
        $hasUnknownCostVariance = false;
        $missingFinal = 0;
        foreach ($rows as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            $si = $r['session_item'];
            if ($si['item_status'] === 'NORMAL' && $r['final'] === null) {
                $missingFinal++;
            }
            if ($r['final'] !== null) {
                if ($r['final']['variance_physical_value'] !== null && $r['final']['variance_available_value'] !== null) {
                    $totalVariancePhysicalValue += (float) $r['final']['variance_physical_value'];
                    $totalVarianceAvailableValue += (float) $r['final']['variance_available_value'];
                } else {
                    $hasUnknownCostVariance = true;
                }
            }
        }

        $header = ['Field', 'Nilai'];
        $body = [
            ['Nomor Session', $session['session_no']],
            ['Nama Session', $session['name']],
            ['Lokasi', $session['location_name']],
            ['Scope', $session['scope_type'] === 'CATEGORY' ? ('Kategori: ' . ($session['category_name'] ?? '-')) : 'Semua Kategori'],
            ['Tanggal Fisik', $session['physical_date']],
            ['Status', $session['status']],
            ['Dimulai', $session['started_at']],
            ['Masuk Review', $session['review_started_at']],
            ['Selesai', $session['finished_at']],
            ['', ''],
            ['Total Item', count($rows)],
            ['MATCH', $counts['MATCH']],
            ['MISMATCH', $counts['MISMATCH']],
            ['CONDITION_MISMATCH', $counts['CONDITION_MISMATCH']],
            ['PARTIAL (baru 1 tim)', $counts['PARTIAL']],
            ['BELUM_DIHITUNG', $counts['BELUM_DIHITUNG']],
            ['RECOUNT_REQUIRED', $counts['RECOUNT_REQUIRED']],
            ['NOT_COUNTABLE', $counts['NOT_COUNTABLE']],
            ['Item NORMAL belum di-final', $missingFinal],
            ['Total Nilai Selisih Fisik (Rp)', round($totalVariancePhysicalValue, 2)],
            ['Total Nilai Selisih Available (Rp)', round($totalVarianceAvailableValue, 2)],
        ];
        if ($hasUnknownCostVariance) {
            $body[] = ['Catatan', 'Sebagian item tidak memiliki harga (unit_cost tidak diketahui) — selisih nilainya TIDAK termasuk dalam total di atas.'];
        }
        return [$header, $body];
    }

    /** @return array{0:array<int,string>,1:array<int,array<int,mixed>>} */
    private function detailSheet(array $rows): array
    {
        $header = [
            'SKU', 'Nama Barang', 'Kategori', 'Brand', 'Satuan Dasar',
            'Stok Sistem', 'Status Item',
            'P1 Good', 'P1 Rusak', 'P1 Expired', 'P1 Deadstock', 'P1 Fisik',
            'P2 Good', 'P2 Rusak', 'P2 Expired', 'P2 Deadstock', 'P2 Fisik',
            'Status Rekonsiliasi',
            'Final Good', 'Final Rusak', 'Final Expired', 'Final Deadstock', 'Final Fisik', 'Final Tersedia',
            'Harga Satuan',
            'Selisih Fisik', 'Nilai Selisih Fisik (Rp)',
            'Selisih Stok Layak / Available', 'Nilai Selisih Available (Rp)',
            'Sumber Final', 'Alasan Final',
        ];
        $body = [];
        foreach ($rows as $r) {
            $si = $r['session_item'];
            $p1 = $r['p1'];
            $p2 = $r['p2'];
            $final = $r['final'];
            $body[] = [
                $si['sku_snapshot'], $si['name_snapshot'], $si['category_snapshot'], $si['brand_snapshot'],
                $si['base_unit_snapshot'],
                (float) $si['system_qty_snapshot'], $si['item_status'],
                $p1 ? (float) $p1['good_base_qty'] : null, $p1 ? (float) $p1['damaged_base_qty'] : null,
                $p1 ? (float) $p1['expired_base_qty'] : null, $p1 ? (float) $p1['deadstock_base_qty'] : null,
                $p1 ? (float) $p1['physical_base_qty'] : null,
                $p2 ? (float) $p2['good_base_qty'] : null, $p2 ? (float) $p2['damaged_base_qty'] : null,
                $p2 ? (float) $p2['expired_base_qty'] : null, $p2 ? (float) $p2['deadstock_base_qty'] : null,
                $p2 ? (float) $p2['physical_base_qty'] : null,
                $r['status'],
                $final ? (float) $final['final_good_base_qty'] : null, $final ? (float) $final['final_damaged_base_qty'] : null,
                $final ? (float) $final['final_expired_base_qty'] : null, $final ? (float) $final['final_deadstock_base_qty'] : null,
                $final ? (float) $final['final_physical_base_qty'] : null, $final ? (float) $final['final_available_base_qty'] : null,
                $si['unit_cost_snapshot'] !== null ? (float) $si['unit_cost_snapshot'] : null,
                $final ? (float) $final['variance_physical_qty'] : null,
                $final && $final['variance_physical_value'] !== null ? (float) $final['variance_physical_value'] : null,
                $final ? (float) $final['variance_available_qty'] : null,
                $final && $final['variance_available_value'] !== null ? (float) $final['variance_available_value'] : null,
                $final['source'] ?? null, $final['reason'] ?? null,
            ];
        }
        return [$header, $body];
    }

    /** @return array{0:array<int,string>,1:array<int,array<int,mixed>>} */
    private function conditionSheet(array $rows, string $label, string $finalColumn): array
    {
        $header = ['SKU', 'Nama Barang', 'Kategori', 'Qty (' . ucfirst($label) . ')', 'Satuan Dasar', 'Harga Satuan', 'Nilai (Rp)', 'Alasan Final'];
        $body = [];
        foreach ($rows as $r) {
            $final = $r['final'];
            if (!$final || (float) $final[$finalColumn] <= 0) {
                continue;
            }
            $si = $r['session_item'];
            $qty = (float) $final[$finalColumn];
            $unitCost = $si['unit_cost_snapshot'] !== null ? (float) $si['unit_cost_snapshot'] : null;
            $body[] = [
                $si['sku_snapshot'], $si['name_snapshot'], $si['category_snapshot'],
                $qty, $si['base_unit_snapshot'], $unitCost,
                $unitCost !== null ? round($qty * $unitCost, 2) : null,
                $final['reason'],
            ];
        }
        return [$header, $body];
    }

    /**
     * Every user who actually PARTICIPATED (has at least one count row)
     * in this session — the set the signature section on the printed
     * report is required to list, not just whoever is currently assigned.
     */
    private function petugasList(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.user_id, c.user_name_snapshot, u.username,
                    (SELECT sc.team FROM stock_opname_session_counters sc
                     WHERE sc.session_id = ? AND sc.user_id = c.user_id ORDER BY sc.assigned_at DESC LIMIT 1) AS team,
                    COUNT(*) AS item_count, MIN(c.counted_at) AS first_count, MAX(c.counted_at) AS last_count
             FROM stock_opname_counts c
             JOIN stock_opname_session_items si ON si.id = c.session_item_id
             JOIN users u ON u.id = c.user_id
             WHERE si.session_id = ?
             GROUP BY c.user_id, c.user_name_snapshot, u.username
             ORDER BY u.username'
        );
        $stmt->execute([$sessionId, $sessionId]);
        return $stmt->fetchAll();
    }

    /** @return array{0:array<int,string>,1:array<int,array<int,mixed>>} */
    private function petugasSheet(int $sessionId): array
    {
        $header = ['Username', 'Nama Lengkap', 'Team (terakhir)', 'Jumlah Item Dihitung', 'Hitungan Pertama', 'Hitungan Terakhir'];
        $body = [];
        foreach ($this->petugasList($sessionId) as $row) {
            $body[] = [
                $row['username'], $row['user_name_snapshot'], $row['team'],
                (int) $row['item_count'], $row['first_count'], $row['last_count'],
            ];
        }
        return [$header, $body];
    }
}
