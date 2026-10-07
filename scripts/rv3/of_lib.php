<?php
declare(strict_types=1);

/**
 * OCTOBER OPENING FORECAST (read-only) — what the month after the cutoff will report once BOTH corrections are in:
 *   1. the Stock Opname adjustments of the cutoff day (30 Sep) are in September's closing, hence in October's opening, and are no longer an October adjustment
 *   2. the Karang Tengah opening balance (effective 2026-10-01) is October opening — never Stock IN / Purchase / Transfer / Adjustment
 * Per warehouse and company-wide: BEFORE (the ledger as reported today) → AFTER (predicted), with the invariants that must hold. The prediction is checked against the real figures after the
 * corrections are written (tests/october_opening_combined_test.php).
 */

use App\Services\MovementReportV3Service;

/**
 * @param array<string,mixed> $pc  pc_plan() result (period cutoff)
 * @param array<string,mixed> $kt  kt_plan() result (Karang Tengah opening)
 * @return array<string,mixed>
 */
function of_forecast(PDO $pdo, array $pc, array $kt): array
{
    if (!class_exists(MovementReportV3Service::class)) {
        throw new RuntimeException('MovementReportV3Service is not installed');
    }
    $from = date('Y-m-d', strtotime($pc['cutoff'] . ' +1 day'));
    $to = date('Y-m-t', strtotime($from));
    $shift = [];
    foreach ($pc['transactions'] as $r) {
        if ($r['state'] === 'SET' && $r['status'] !== 'REVERSED') {
            $d = substr((string) $r['transaction_date'], 0, 10);
            if ($d >= $from && $d <= $to) {
                $shift[(int) $r['warehouse_id']] = ($shift[(int) $r['warehouse_id']] ?? 0.0) + (float) $r['value'];
            }
        }
    }
    $khId = $kt['warehouse']['warehouse_id'];
    $khAdd = ($khId !== null && !$kt['warehouse']['already_posted']) ? (float) $kt['summary']['normalized_value'] : 0.0;   // already posted ⇒ it is in the BEFORE figures
    $rows = [];
    $sum = ['before' => ['opening' => 0.0, 'in' => 0.0, 'adjustment' => 0.0, 'closing' => 0.0], 'after' => ['opening' => 0.0, 'in' => 0.0, 'adjustment' => 0.0, 'closing' => 0.0], 'shift' => 0.0, 'karang' => 0.0];
    $whs = $pdo->query('SELECT id, code, name, is_active FROM warehouses ORDER BY id')->fetchAll();
    foreach ($whs as $w) {
        $id = (int) $w['id'];
        $b = MovementReportV3Service::overview($pdo, $from, $to, $id)['split_totals'];
        $s = $shift[$id] ?? 0.0;
        $k = $id === (int) $khId ? $khAdd : 0.0;
        $row = ['warehouse_id' => $id, 'code' => $w['code'], 'name' => $w['name'], 'is_active' => (int) $w['is_active'],
            'before' => ['opening' => round((float) $b['opening'], 4), 'in' => round((float) $b['in'], 4), 'adjustment' => round((float) $b['adjustment'], 4), 'closing' => round((float) $b['closing'], 4)],
            'so_cutoff_shift' => round($s, 4), 'karang_opening' => round($k, 4)];
        $row['after'] = ['opening' => round((float) $b['opening'] + $s + $k, 4), 'in' => round((float) $b['in'], 4), 'adjustment' => round((float) $b['adjustment'] - $s, 4), 'closing' => round((float) $b['closing'] + $k, 4)];
        $rows[] = $row;
        foreach (['opening', 'in', 'adjustment', 'closing'] as $f) {
            $sum['before'][$f] += $row['before'][$f];
            $sum['after'][$f] += $row['after'][$f];
        }
        $sum['shift'] += $s;
        $sum['karang'] += $k;
    }
    $all = MovementReportV3Service::overview($pdo, $from, $to, null)['split_totals'];
    $company = ['scope' => 'Semua Gudang', 'before' => ['opening' => round((float) $all['opening'], 4), 'in' => round((float) $all['in'], 4), 'adjustment' => round((float) $all['adjustment'], 4), 'closing' => round((float) $all['closing'], 4)],
        'so_cutoff_shift' => round($sum['shift'], 4), 'karang_opening' => round($sum['karang'], 4)];
    $company['after'] = ['opening' => round((float) $all['opening'] + $sum['shift'] + $sum['karang'], 4), 'in' => round((float) $all['in'], 4), 'adjustment' => round((float) $all['adjustment'] - $sum['shift'], 4), 'closing' => round((float) $all['closing'] + $sum['karang'], 4)];
    $inv = [];
    $inv[] = ['No double counting: opening_before + adjustment_before == opening_after + adjustment_after − Karang opening (the SO amount only moves from movement to opening)',
        abs(($company['before']['opening'] + $company['before']['adjustment']) - ($company['after']['opening'] + $company['after']['adjustment'] - $company['karang_opening'])) < 0.01];
    $inv[] = ['Company closing changes ONLY by the Karang Tengah opening (the SO reclassification does not change total closing inventory value)', abs($company['after']['closing'] - $company['before']['closing'] - $company['karang_opening']) < 0.01];
    $inv[] = ['October Stock IN is unchanged (neither correction is a Stock IN)', abs($company['after']['in'] - $company['before']['in']) < 0.01];
    $inv[] = ['Σ warehouses (after) == Semua Gudang (after): opening and closing', abs(array_sum(array_column(array_column($rows, 'after'), 'opening')) - $company['after']['opening']) < 0.05 && abs(array_sum(array_column(array_column($rows, 'after'), 'closing')) - $company['after']['closing']) < 0.05];
    return ['period' => "{$from} .. {$to}", 'cutoff' => $pc['cutoff'], 'rows' => $rows, 'company' => $company, 'invariants' => $inv];
}

/** @return list<string> */
function of_lines(array $f): array
{
    $n = static fn (float $v): string => number_format($v, 2, ',', '.');
    $o = ["=== PREDIKSI STOK AWAL OKTOBER (READ ONLY) — periode {$f['period']} ===", 'SEBELUM = laporan hari ini; SESUDAH = setelah (1) adjustment SO cutoff ' . $f['cutoff'] . ' dipindah ke penutupan bulan sebelumnya dan (2) opening Karang Tengah.', ''];
    $o[] = sprintf('%-20s %-3s %18s %18s %18s %18s %18s', 'Gudang', 'Akt', 'Awal SEBELUM', '+ adj SO cutoff', '+ opening Karang', 'Awal SESUDAH', 'Adjustment: sebelum → sesudah');
    foreach ($f['rows'] as $r) {
        $o[] = sprintf('%-20s %-3d %18s %18s %18s %18s %18s → %s', $r['code'], $r['is_active'], $n($r['before']['opening']), $n($r['so_cutoff_shift']), $n($r['karang_opening']), $n($r['after']['opening']), $n($r['before']['adjustment']), $n($r['after']['adjustment']));
    }
    $c = $f['company'];
    $o[] = sprintf('%-20s %-3s %18s %18s %18s %18s %18s → %s', 'SEMUA GUDANG', '', $n($c['before']['opening']), $n($c['so_cutoff_shift']), $n($c['karang_opening']), $n($c['after']['opening']), $n($c['before']['adjustment']), $n($c['after']['adjustment']));
    $o[] = '';
    $o[] = sprintf('Stok Akhir SEMUA GUDANG: %s → %s   |   Stock IN: %s → %s (tidak berubah)', $n($c['before']['closing']), $n($c['after']['closing']), $n($c['before']['in']), $n($c['after']['in']));
    $o[] = '';
    foreach ($f['invariants'] as [$name, $ok]) {
        $o[] = ($ok ? 'PASS' : 'FAIL') . " - {$name}";
    }
    return $o;
}
