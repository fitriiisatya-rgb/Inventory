<?php
declare(strict_types=1);

final class PhotoValidationException extends RuntimeException
{
}

final class PhotoForbiddenException extends RuntimeException
{
}

/**
 * Photo evidence for DAMAGED/EXPIRED/DEADSTOCK conditions (Phase 5).
 *
 * A count with any condition qty > 0 stays evidence_status=EVIDENCE_REQUIRED
 * until every such condition has at least one ACTIVE photo — recomputed
 * here after every upload, delete, and count edit, never assumed. This is
 * the single place that decides "is this count actually done", so
 * ReconciliationService and the COUNTER-facing status can both defer to it
 * instead of each re-deriving the rule.
 */
final class PhotoEvidenceService
{
    private const CONDITIONS = ['damaged' => 'DAMAGED', 'expired' => 'EXPIRED', 'deadstock' => 'DEADSTOCK'];
    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_DIMENSION = 1600;

    public function __construct(
        private PDO $pdo,
        private ItemLockService $locks,
        private string $uploadDir,
        private int $uploadMaxKb
    ) {
    }

    /** Shared construction from app config, so every API endpoint builds this identically. */
    public static function fromConfig(PDO $pdo, ItemLockService $locks): self
    {
        $app = $GLOBALS['SO_CONFIG']['app'];
        return new self($pdo, $locks, $app['upload_dir'], (int) $app['upload_max_kb']);
    }

    /**
     * Recomputes and persists evidence_status for a count based on its
     * CURRENT qty fields and CURRENT active photos. Safe to call inside an
     * ambient transaction on the same connection (no BEGIN/COMMIT here).
     */
    public function recomputeEvidenceStatus(int $countId): string
    {
        $countStmt = $this->pdo->prepare('SELECT * FROM stock_opname_counts WHERE id = ? LIMIT 1');
        $countStmt->execute([$countId]);
        $count = $countStmt->fetch();
        if (!$count) {
            throw new RuntimeException('Count tidak ditemukan.');
        }

        $photoCountStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM stock_opname_photos WHERE count_id = ? AND condition_type = ? AND status = 'ACTIVE'"
        );

        $complete = true;
        foreach (self::CONDITIONS as $field => $conditionType) {
            if ((float) $count["{$field}_base_qty"] > 0) {
                $photoCountStmt->execute([$countId, $conditionType]);
                if ((int) $photoCountStmt->fetchColumn() === 0) {
                    $complete = false;
                    break;
                }
            }
        }

        $newStatus = $complete ? 'COMPLETE' : 'EVIDENCE_REQUIRED';
        $this->pdo->prepare('UPDATE stock_opname_counts SET evidence_status = ? WHERE id = ?')->execute([$newStatus, $countId]);
        return $newStatus;
    }

    /**
     * Called by CountService when an edit zeroes out a condition that
     * previously had qty > 0: that condition's ACTIVE photos become
     * historical, never deleted (design review points 22-23). Safe inside
     * an ambient transaction.
     */
    public function supersedeCondition(int $countId, string $conditionType, string $reason): void
    {
        $this->pdo->prepare(
            "UPDATE stock_opname_photos SET status = 'SUPERSEDED', superseded_at = NOW(), superseded_reason = ?
             WHERE count_id = ? AND condition_type = ? AND status = 'ACTIVE'"
        )->execute([$reason, $countId, $conditionType]);
    }

    /**
     * @param array $file One element of $_FILES (tmp_name, name, size, error)
     * @return array{photo: array, evidence_status: string}
     */
    public function uploadPhoto(int $countId, string $conditionType, int $userId, array $file, ?string $caption): array
    {
        if (!in_array($conditionType, ['DAMAGED', 'EXPIRED', 'DEADSTOCK'], true)) {
            throw new PhotoValidationException('condition_type tidak valid.');
        }

        $context = $this->loadCountContext($countId);
        if ($context['session_status'] !== 'ACTIVE') {
            throw new PhotoValidationException('Session tidak berstatus ACTIVE.');
        }
        if ((int) $context['round'] !== (int) $context['current_round']) {
            throw new PhotoValidationException('Round ini sudah tidak aktif (sudah ada hitung ulang) — foto tidak dapat ditambahkan.');
        }
        $field = strtolower($conditionType);
        if ((float) $context["{$field}_base_qty"] <= 0) {
            throw new PhotoValidationException("Kondisi {$conditionType} bernilai 0 pada hitungan ini — tidak perlu foto bukti.");
        }

        $lock = $this->locks->findOwnedBy((int) $context['session_item_id'], $context['team'], $userId);
        if (!$lock) {
            throw new PhotoForbiddenException('Anda tidak memegang lock aktif untuk item ini.');
        }

        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new PhotoValidationException('File tidak valid.');
        }
        if (($file['size'] ?? 0) > $this->uploadMaxKb * 1024) {
            throw new PhotoValidationException("Ukuran file melebihi batas {$this->uploadMaxKb} KB.");
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new PhotoValidationException("Tipe file '{$mime}' tidak didukung. Gunakan JPEG, PNG, atau WebP.");
        }

        // Never trust the extension or declared MIME — actually decode it.
        // A renamed .php with a spoofed image header fails here.
        $raw = file_get_contents($file['tmp_name']);
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw new PhotoValidationException('File tidak dapat dibaca sebagai gambar yang valid.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            $scale = self::MAX_DIMENSION / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        $useWebp = function_exists('imagewebp');
        $extension = $useWebp ? 'webp' : 'jpg';
        $relativeDir = 'uploads/opname/' . date('Y') . '/' . date('m');
        $absoluteDir = $this->uploadDir . '/' . date('Y') . '/' . date('m');
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            imagedestroy($image);
            throw new RuntimeException('Gagal membuat direktori upload.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $absolutePath = $absoluteDir . '/' . $filename;
        $relativePath = $relativeDir . '/' . $filename;

        // Re-encoding (rather than copying the original bytes) strips EXIF
        // as a side effect and is what actually enforces the dimension cap.
        $saved = $useWebp ? imagewebp($image, $absolutePath, 80) : imagejpeg($image, $absolutePath, 82);
        imagedestroy($image);
        if (!$saved) {
            throw new RuntimeException('Gagal menyimpan file gambar.');
        }

        $this->pdo->prepare(
            'INSERT INTO stock_opname_photos (session_id, session_item_id, count_id, condition_type, file_path, status, caption, uploaded_by, uploaded_at)
             VALUES (?, ?, ?, ?, ?, \'ACTIVE\', ?, ?, NOW())'
        )->execute([
            $context['session_id'], $context['session_item_id'], $countId, $conditionType,
            $relativePath, $caption !== '' ? $caption : null, $userId,
        ]);
        $photoId = (int) $this->pdo->lastInsertId();

        Audit::log($userId, 'PHOTO_UPLOAD', 'stock_opname_photos', $photoId, null, [
            'count_id' => $countId, 'condition_type' => $conditionType, 'file_path' => $relativePath,
        ]);

        $newStatus = $this->recomputeEvidenceStatus($countId);
        if ($newStatus === 'COMPLETE') {
            $this->locks->release((int) $context['session_item_id'], $context['team'], $userId);
            Audit::log($userId, 'COUNT_EVIDENCE_COMPLETE', 'stock_opname_counts', $countId, null, null);
        }

        $photoStmt = $this->pdo->prepare('SELECT * FROM stock_opname_photos WHERE id = ?');
        $photoStmt->execute([$photoId]);

        return ['photo' => $photoStmt->fetch(), 'evidence_status' => $newStatus];
    }

    public function deletePhoto(int $photoId, int $actorId): array
    {
        $photoStmt = $this->pdo->prepare('SELECT * FROM stock_opname_photos WHERE id = ? LIMIT 1');
        $photoStmt->execute([$photoId]);
        $photo = $photoStmt->fetch();
        if (!$photo) {
            throw new RuntimeException('Foto tidak ditemukan.');
        }
        if ((int) $photo['uploaded_by'] !== $actorId) {
            throw new PhotoForbiddenException('Anda hanya dapat menghapus foto yang Anda upload sendiri.');
        }

        $countStmt = $this->pdo->prepare('SELECT evidence_status FROM stock_opname_counts WHERE id = ?');
        $countStmt->execute([$photo['count_id']]);
        $evidenceStatus = $countStmt->fetchColumn();
        if ($evidenceStatus === 'COMPLETE') {
            throw new PhotoValidationException('Foto tidak dapat dihapus setelah hitungan berstatus lengkap — gunakan edit hitungan jika perlu koreksi.');
        }

        $this->pdo->prepare('DELETE FROM stock_opname_photos WHERE id = ?')->execute([$photoId]);
        $absolutePath = $this->uploadDir . '/' . preg_replace('#^uploads/opname/#', '', $photo['file_path']);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        Audit::log($actorId, 'PHOTO_DELETE', 'stock_opname_photos', $photoId, [
            'count_id' => $photo['count_id'], 'condition_type' => $photo['condition_type'], 'file_path' => $photo['file_path'],
        ], null);

        $newStatus = $this->recomputeEvidenceStatus((int) $photo['count_id']);
        return ['deleted' => true, 'evidence_status' => $newStatus];
    }

    public function listPhotos(int $countId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_opname_photos WHERE count_id = ? ORDER BY uploaded_at');
        $stmt->execute([$countId]);
        return $stmt->fetchAll();
    }

    /**
     * Permission-checked lookup for streaming a photo (design review point
     * 20). SUPERADMIN sees everything; a COUNTER may only see photos for
     * their OWN team on a session they're assigned to — never the other
     * team's evidence, which would otherwise leak condition/qty
     * information about a blind count.
     *
     * @return array{absolute_path: string, mime: string}
     */
    public function getPhotoForViewing(int $photoId, array $viewer): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.team AS count_team, si.session_id
             FROM stock_opname_photos p
             JOIN stock_opname_counts c ON c.id = p.count_id
             JOIN stock_opname_session_items si ON si.id = p.session_item_id
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch();
        if (!$photo) {
            throw new RuntimeException('Foto tidak ditemukan.');
        }

        if (!Permissions::can($viewer['role'], 'reconciliation.view')) {
            $assignStmt = $this->pdo->prepare(
                "SELECT team FROM stock_opname_session_counters WHERE session_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1"
            );
            $assignStmt->execute([$photo['session_id'], $viewer['id']]);
            $viewerTeam = $assignStmt->fetchColumn();
            if (!$viewerTeam || $viewerTeam !== $photo['count_team']) {
                throw new PhotoForbiddenException('Anda tidak memiliki akses ke foto ini.');
            }
        }

        $absolutePath = $this->uploadDir . '/' . preg_replace('#^uploads/opname/#', '', $photo['file_path']);
        if (!is_file($absolutePath)) {
            throw new RuntimeException('File foto tidak ditemukan di server.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);

        return ['absolute_path' => $absolutePath, 'mime' => $mime ?: 'application/octet-stream'];
    }

    private function loadCountContext(int $countId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, si.id AS session_item_id, si.current_round, s.status AS session_status, s.id AS session_id
             FROM stock_opname_counts c
             JOIN stock_opname_session_items si ON si.id = c.session_item_id
             JOIN stock_opname_sessions s ON s.id = si.session_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$countId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Count tidak ditemukan.');
        }
        return $row;
    }
}
