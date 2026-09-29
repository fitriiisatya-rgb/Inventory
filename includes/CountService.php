<?php
declare(strict_types=1);

/** 422-worthy: one or more business-rule/input validation failures. */
final class CountValidationException extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}

/** 403/409-worthy: the caller does not hold a valid lock for this slot. */
final class CountLockException extends RuntimeException
{
}

/**
 * Save a COUNTER's count for one session_item (design review points
 * 14-19). One authoritative row per (session_item_id, team, round) —
 * the table's own UNIQUE constraint is what makes that true; this class
 * never creates a second "official" row for a round, it either inserts
 * the first one or updates it in place, always through the same
 * UNIQUE-constrained slot.
 */
final class CountService
{
    public function __construct(private PDO $pdo, private ItemLockService $locks, private PhotoEvidenceService $evidence)
    {
    }

    /**
     * @return array{count_id:int, team:string, round:int, good_base_qty:float,
     *               physical_base_qty:float, available_base_qty:float,
     *               evidence_required: array<int,string>}
     */
    public function saveCount(int $sessionItemId, int $userId, array $input): array
    {
        $siStmt = $this->pdo->prepare(
            'SELECT si.*, s.status AS session_status, s.id AS session_id FROM stock_opname_session_items si
             JOIN stock_opname_sessions s ON s.id = si.session_id
             WHERE si.id = ? LIMIT 1'
        );
        $siStmt->execute([$sessionItemId]);
        $sessionItem = $siStmt->fetch();
        if (!$sessionItem) {
            throw new RuntimeException('Item sesi tidak ditemukan.');
        }
        if ($sessionItem['session_status'] !== 'ACTIVE') {
            throw new CountValidationException(["Session tidak berstatus ACTIVE (saat ini: {$sessionItem['session_status']})."]);
        }
        if ($sessionItem['item_status'] !== 'NORMAL') {
            throw new CountValidationException(["Item berstatus {$sessionItem['item_status']}, tidak dapat dihitung."]);
        }

        $assignStmt = $this->pdo->prepare(
            "SELECT team FROM stock_opname_session_counters WHERE session_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1"
        );
        $assignStmt->execute([$sessionItem['session_id'], $userId]);
        $team = $assignStmt->fetchColumn();
        if (!$team) {
            throw new CountValidationException(['User tidak di-assign sebagai petugas P1/P2 pada session ini.']);
        }

        $lock = $this->locks->findOwnedBy($sessionItemId, $team, $userId);
        if (!$lock) {
            throw new CountLockException('Anda tidak memegang lock aktif untuk item ini (mungkin sudah kedaluwarsa) — buka kembali item ini untuk mengunci ulang.');
        }

        $itemLike = UnitConversion::fromSessionItemSnapshot($sessionItem);
        $errors = [];

        foreach (['good_buy_qty', 'good_mid_qty', 'good_base_input_qty'] as $field) {
            if (isset($input[$field]) && !is_numeric($input[$field])) {
                $errors[] = "{$field} harus berupa angka.";
            } elseif ((float) ($input[$field] ?? 0) < 0) {
                $errors[] = "{$field} tidak boleh negatif.";
            }
        }
        if ($errors) {
            throw new CountValidationException($errors);
        }

        $goodBuyQty  = (float) ($input['good_buy_qty'] ?? 0);
        $goodMidQty  = (float) ($input['good_mid_qty'] ?? 0);
        $goodBaseQty = (float) ($input['good_base_input_qty'] ?? 0);
        $goodBase = UnitConversion::normalize($itemLike, $goodBuyQty, $goodMidQty, $goodBaseQty);

        $conditions = [];
        foreach (['damaged', 'expired', 'deadstock'] as $cond) {
            $qtyRaw = $input["{$cond}_qty"] ?? 0;
            if (!is_numeric($qtyRaw) || (float) $qtyRaw < 0) {
                $errors[] = "{$cond}_qty harus berupa angka >= 0.";
                continue;
            }
            $qty = (float) $qtyRaw;
            $unit = trim((string) ($input["{$cond}_unit"] ?? ''));
            $base = 0.0;
            if ($qty > 0) {
                if ($unit === '') {
                    $errors[] = "{$cond}_unit wajib diisi karena {$cond}_qty > 0.";
                } else {
                    $converted = UnitConversion::convertToBase($itemLike, $qty, $unit);
                    if ($converted === null) {
                        $errors[] = "Unit '{$unit}' tidak dikenal untuk kondisi {$cond} pada SKU {$sessionItem['sku_snapshot']}.";
                    } else {
                        $base = $converted;
                    }
                }
            }
            $conditions[$cond] = ['qty' => $qty, 'unit' => $unit !== '' ? $unit : null, 'base' => $base];
        }
        if ($errors) {
            throw new CountValidationException($errors);
        }

        $physicalBase = $goodBase + $conditions['damaged']['base'] + $conditions['expired']['base'] + $conditions['deadstock']['base'];

        // Phase 5 owns actually enforcing/collecting evidence; Phase 4 only
        // computes and surfaces which conditions would require it, per the
        // "condition qty > 0 -> evidence_required = true" hook requested in
        // the design review. This never blocks a save.
        $evidenceRequired = [];
        foreach (['damaged' => 'DAMAGED', 'expired' => 'EXPIRED', 'deadstock' => 'DEADSTOCK'] as $key => $label) {
            if ($conditions[$key]['base'] > 0) {
                $evidenceRequired[] = $label;
            }
        }

        $round = (int) $sessionItem['current_round'];
        $note = trim((string) ($input['note'] ?? '')) ?: null;
        $now = date('Y-m-d H:i:s');

        $userStmt = $this->pdo->prepare('SELECT full_name FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $userName = (string) $userStmt->fetchColumn();

        $newState = [
            'good_buy_qty' => $goodBuyQty, 'good_mid_qty' => $goodMidQty, 'good_base_input_qty' => $goodBaseQty, 'good_base_qty' => $goodBase,
            'damaged_qty' => $conditions['damaged']['qty'], 'damaged_unit' => $conditions['damaged']['unit'], 'damaged_base_qty' => $conditions['damaged']['base'],
            'expired_qty' => $conditions['expired']['qty'], 'expired_unit' => $conditions['expired']['unit'], 'expired_base_qty' => $conditions['expired']['base'],
            'deadstock_qty' => $conditions['deadstock']['qty'], 'deadstock_unit' => $conditions['deadstock']['unit'], 'deadstock_base_qty' => $conditions['deadstock']['base'],
            'physical_base_qty' => $physicalBase, 'note' => $note,
        ];

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                'SELECT * FROM stock_opname_counts WHERE session_item_id = ? AND team = ? AND round = ? LIMIT 1 FOR UPDATE'
            );
            $existingStmt->execute([$sessionItemId, $team, $round]);
            $existing = $existingStmt->fetch();

            if ($existing) {
                $reason = trim((string) ($input['reason'] ?? ''));
                if ($reason === '') {
                    throw new CountValidationException(['reason wajib diisi saat mengubah hasil hitungan yang sudah tersimpan.']);
                }
                $oldState = [
                    'good_buy_qty' => (float) $existing['good_buy_qty'], 'good_mid_qty' => (float) $existing['good_mid_qty'],
                    'good_base_input_qty' => (float) $existing['good_base_input_qty'], 'good_base_qty' => (float) $existing['good_base_qty'],
                    'damaged_qty' => (float) $existing['damaged_qty'], 'damaged_unit' => $existing['damaged_unit'], 'damaged_base_qty' => (float) $existing['damaged_base_qty'],
                    'expired_qty' => (float) $existing['expired_qty'], 'expired_unit' => $existing['expired_unit'], 'expired_base_qty' => (float) $existing['expired_base_qty'],
                    'deadstock_qty' => (float) $existing['deadstock_qty'], 'deadstock_unit' => $existing['deadstock_unit'], 'deadstock_base_qty' => (float) $existing['deadstock_base_qty'],
                    'physical_base_qty' => (float) $existing['physical_base_qty'], 'note' => $existing['note'],
                ];

                $this->pdo->prepare(
                    'UPDATE stock_opname_counts SET
                        good_buy_qty=?, good_mid_qty=?, good_base_input_qty=?, good_base_qty=?,
                        damaged_qty=?, damaged_unit=?, damaged_base_qty=?,
                        expired_qty=?, expired_unit=?, expired_base_qty=?,
                        deadstock_qty=?, deadstock_unit=?, deadstock_base_qty=?,
                        physical_base_qty=?, note=?, counted_at=?
                     WHERE id=?'
                )->execute([
                    $goodBuyQty, $goodMidQty, $goodBaseQty, $goodBase,
                    $conditions['damaged']['qty'], $conditions['damaged']['unit'], $conditions['damaged']['base'],
                    $conditions['expired']['qty'], $conditions['expired']['unit'], $conditions['expired']['base'],
                    $conditions['deadstock']['qty'], $conditions['deadstock']['unit'], $conditions['deadstock']['base'],
                    $physicalBase, $note, $now, $existing['id'],
                ]);
                $countId = (int) $existing['id'];

                $this->pdo->prepare(
                    'INSERT INTO stock_opname_count_revisions (count_id, old_value, new_value, reason, changed_by, ip_address, user_agent)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $countId, json_encode($oldState, JSON_UNESCAPED_UNICODE), json_encode($newState, JSON_UNESCAPED_UNICODE),
                    $reason, $userId, $_SERVER['REMOTE_ADDR'] ?? null, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);

                // A condition edited from >0 down to 0 supersedes its photos
                // (kept as history, no longer "current" evidence) rather than
                // deleting them (design review points 22-23). A condition
                // still >0 after the edit keeps its existing ACTIVE photos —
                // recomputeEvidenceStatus() below re-derives whether that's
                // still enough.
                foreach (['damaged', 'expired', 'deadstock'] as $cond) {
                    if ($oldState["{$cond}_base_qty"] > 0 && $conditions[$cond]['base'] <= 0) {
                        $this->evidence->supersedeCondition($countId, strtoupper($cond), 'Qty diubah menjadi 0 saat edit hitungan');
                    }
                }
            } else {
                try {
                    $this->pdo->prepare(
                        'INSERT INTO stock_opname_counts
                            (session_item_id, team, user_id, user_name_snapshot, round,
                             good_buy_qty, good_mid_qty, good_base_input_qty, good_base_qty,
                             damaged_qty, damaged_unit, damaged_base_qty,
                             expired_qty, expired_unit, expired_base_qty,
                             deadstock_qty, deadstock_unit, deadstock_base_qty,
                             physical_base_qty, is_recount, note, counted_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $sessionItemId, $team, $userId, $userName, $round,
                        $goodBuyQty, $goodMidQty, $goodBaseQty, $goodBase,
                        $conditions['damaged']['qty'], $conditions['damaged']['unit'], $conditions['damaged']['base'],
                        $conditions['expired']['qty'], $conditions['expired']['unit'], $conditions['expired']['base'],
                        $conditions['deadstock']['qty'], $conditions['deadstock']['unit'], $conditions['deadstock']['base'],
                        $physicalBase, $round > 1 ? 1 : 0, $note, $now,
                    ]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        // Lost a genuine double-submit race for the first save
                        // of this round — surface as a conflict, not a silent
                        // second official row (which the UNIQUE key wouldn't
                        // allow anyway).
                        throw new CountValidationException(['Hitungan untuk round ini baru saja tersimpan oleh permintaan lain. Muat ulang item ini.']);
                    }
                    throw $e;
                }
                $countId = (int) $this->pdo->lastInsertId();

                $this->pdo->prepare(
                    'INSERT INTO stock_opname_count_revisions (count_id, old_value, new_value, reason, changed_by, ip_address, user_agent)
                     VALUES (?, NULL, ?, ?, ?, ?, ?)'
                )->execute([
                    $countId, json_encode($newState, JSON_UNESCAPED_UNICODE), 'Input awal',
                    $userId, $_SERVER['REMOTE_ADDR'] ?? null, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
            }

            // A count is not "done" just because it was saved (design review
            // point 15) — the lock is released only once every condition
            // with qty > 0 actually has its evidence, never on save alone.
            $evidenceStatus = $this->evidence->recomputeEvidenceStatus($countId);
            if ($evidenceStatus === 'COMPLETE') {
                $this->locks->release($sessionItemId, $team, $userId);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'count_id' => $countId,
            'team' => $team,
            'round' => $round,
            'good_base_qty' => $goodBase,
            'physical_base_qty' => $physicalBase,
            'available_base_qty' => $goodBase,
            'evidence_required' => $evidenceRequired,
            'evidence_status' => $evidenceStatus,
        ];
    }
}
