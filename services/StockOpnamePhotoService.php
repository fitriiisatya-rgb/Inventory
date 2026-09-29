<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.14.11 — photo evidence for a Stock Opname finding's DAMAGED/
 * EXPIRED/DEADSTOCK condition. Uploaded BEFORE the finding it will belong
 * to exists (the mobile count card lets a counter attach photos while
 * still filling the form) — see stock_opname_finding_photos' finding_id
 * being nullable.
 *
 * PHASE V2.14.11.1 CORRECTIVE — an independent audit found the original
 * "auto-attach every still-unattached photo matching session/line/role/
 * condition/uploader" design unsafe: a photo selected then abandoned
 * (condition reset to 0, or the form abandoned) stayed pending and could
 * silently attach itself to a LATER, unrelated finding for the same SKU.
 * Every upload now mints an unguessable upload_token (never the bare
 * auto-increment id — sequential ids are guessable); the client must
 * name that exact token in submitFinding()'s `photos` payload for it to
 * be attached (see attachExplicit()) — nothing is ever attached
 * implicitly. remove() lets the counter discard a pending photo before
 * Simpan Temuan; cleanupExpiredPending() reclaims anything left pending
 * past PENDING_EXPIRY_HOURS.
 *
 * Storage lives under storage/ (outside public/, the document root — see
 * .htaccess/docs/DEPLOYMENT.md), so an uploaded file is never web-
 * reachable directly, executable or not; it is only ever served back
 * through GET /stock-opname/{id}/photos/{photoId} (index.php), which
 * re-checks the caller's authorization before streaming bytes.
 */
final class StockOpnamePhotoService
{
    // Raw upload cap before re-encoding — generous for a phone camera
    // photo, small enough to bound worst-case memory use during decode.
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
    private const MAX_DIMENSION = 1600;
    private const JPEG_QUALITY = 82;
    // PHASE V2.14.11.1 — a pending (finding_id IS NULL) photo older than
    // this is treated as abandoned: rejected if a caller tries to attach
    // it, and eligible for cleanupExpiredPending() to delete outright.
    private const PENDING_EXPIRY_HOURS = 24;

    /** @return array{photo_id:int, token:string, condition_type:string, byte_size:int} */
    public static function upload(
        PDO $pdo,
        int $sessionId,
        string $role,
        int $itemId,
        string $conditionType,
        int $userId,
        string $claimToken,
        string $tmpPath,
        ?string $caption = null
    ): array {
        $role = strtolower($role);
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $conditionType = strtoupper($conditionType);
        if (!in_array($conditionType, ['DAMAGED', 'EXPIRED', 'DEADSTOCK'], true)) {
            throw new ValidationException(['condition_type must be DAMAGED, EXPIRED, or DEADSTOCK — GOOD never requires photo evidence']);
        }

        $session = $pdo->prepare('SELECT status, counting_model FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }
        if ($session['status'] !== 'OPEN') {
            throw new ValidationException(['opname session must be OPEN to attach photo evidence']);
        }
        if (($session['counting_model'] ?? 'LEGACY_DUAL_COUNT') !== 'FINDINGS_V1') {
            throw new CountingModeConflictException("session {$sessionId} uses LEGACY_DUAL_COUNT — photo evidence only applies to FINDINGS_V1 sessions");
        }

        $line = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $line = $line->fetch();
        if (!$line) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }

        // Same claim contract submitFinding() enforces — a photo may only
        // be uploaded by whoever currently holds the live claim on this
        // exact line/role, so a photo can never be uploaded "on spec" for
        // an item nobody on this team is actively counting. This is
        // checked once, HERE, at upload time — it is deliberately NOT
        // re-checked against the original claim_token at attach time
        // (see attachExplicit()), since a legitimate Tambah Temuan
        // re-claim between upload and Simpan Temuan would otherwise
        // invalidate an already-valid upload for no safety reason.
        $claimByCol = "{$role}_claimed_by_user_id";
        $claimAtCol = "{$role}_claimed_at";
        $claimTokenCol = "{$role}_claim_token";
        $leaseExpiry = date('Y-m-d H:i:s', time() - 900);
        if ((int) ($line[$claimByCol] ?? 0) !== $userId
            || $line[$claimTokenCol] === null || !hash_equals((string) $line[$claimTokenCol], $claimToken)
            || $line[$claimAtCol] === null || $line[$claimAtCol] < $leaseExpiry
        ) {
            throw new ClaimConflictException('you no longer hold a valid claim on this item — claim it again before attaching a photo');
        }

        // Opportunistic cleanup (no cron required) — bounded to this
        // session's own line, so it never scans the whole table.
        self::cleanupExpiredPending($pdo, (int) $line['id']);

        [$reencoded, $mimeType] = self::validateAndReencode($tmpPath);

        $storageDir = __DIR__ . '/../storage/stock_opname_photos';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        $ext = $mimeType === 'image/png' ? 'png' : 'jpg';
        $storedName = date('Ymd') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = $storageDir . '/' . $storedName;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }
        if (file_put_contents($destination, $reencoded) === false) {
            throw new ValidationException(['failed to store the uploaded photo']);
        }

        $token = self::uuid();
        $caption = $caption !== null && trim($caption) !== '' ? substr(trim($caption), 0, 255) : null;
        try {
            $insert = $pdo->prepare(
                'INSERT INTO stock_opname_finding_photos
                    (upload_token, session_id, stock_opname_line_id, team_role, condition_type, finding_id, uploaded_by, storage_path, mime_type, byte_size, caption, uploaded_at)
                 VALUES (:token, :sid, :line_id, :role, :ct, NULL, :uid, :path, :mime, :size, :caption, :now)'
            );
            $insert->execute([
                'token' => $token, 'sid' => $sessionId, 'line_id' => $line['id'], 'role' => strtoupper($role), 'ct' => $conditionType,
                'uid' => $userId, 'path' => $storedName, 'mime' => $mimeType, 'size' => strlen($reencoded),
                'caption' => $caption, 'now' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // PHASE V2.14.11.1 item F — the permanent file was already
            // written; if the DB insert (or anything in this try block)
            // fails, remove it rather than leaving an orphan on disk.
            @unlink($destination);
            throw $e;
        }
        $photoId = (int) $pdo->lastInsertId();

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_PHOTO_UPLOAD', 'stock_opname_finding_photos', $photoId, null, [
            'item_id' => $itemId, 'role' => strtoupper($role), 'condition_type' => $conditionType, 'byte_size' => strlen($reencoded),
        ], null);

        return ['photo_id' => $photoId, 'token' => $token, 'condition_type' => $conditionType, 'byte_size' => strlen($reencoded)];
    }

    /**
     * PHASE V2.14.11.1 item D — discards a pending (not yet attached)
     * photo before Simpan Temuan. Ownership-checked (token + uploader);
     * an already-attached photo (finding_id NOT NULL) can never be
     * removed here — an attached photo is part of a saved finding's
     * permanent record, corrected only via voidFinding(), never deleted.
     * Deletes the filesystem file only after the DB row is confirmed
     * gone, so a failure here never leaves a DB row pointing at nothing
     * (the reverse of upload()'s ordering, and just as important — the
     * next cleanupExpiredPending() pass would otherwise never revisit a
     * row that's already gone).
     */
    public static function remove(PDO $pdo, int $photoId, string $token, int $userId): void
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_opname_finding_photos WHERE id = :id');
        $stmt->execute(['id' => $photoId]);
        $photo = $stmt->fetch();
        if (!$photo || !hash_equals((string) $photo['upload_token'], $token) || (int) $photo['uploaded_by'] !== $userId) {
            throw new ValidationException(['photo not found, or this token/uploader does not match']);
        }
        if ($photo['finding_id'] !== null) {
            throw new ValidationException(['this photo is already attached to a saved finding and cannot be removed']);
        }

        $delete = $pdo->prepare('DELETE FROM stock_opname_finding_photos WHERE id = :id AND finding_id IS NULL');
        $delete->execute(['id' => $photoId]);
        if ($delete->rowCount() === 1) {
            @unlink(self::absolutePath($photo['storage_path']));
            AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_PHOTO_REMOVE', 'stock_opname_finding_photos', $photoId, null, ['condition_type' => $photo['condition_type']], null);
        }
    }

    /**
     * PHASE V2.14.11.1 item B — the ONLY way a pending photo ever becomes
     * attached. Called by StockOpnameService::submitFinding() once per
     * condition, inside the SAME transaction as the finding/quantity
     * insert — if that transaction rolls back, this UPDATE rolls back
     * with it (item G: never attached without the finding transaction
     * succeeding), and every photo named here simply remains pending and
     * retryable. Verifies, for every token: it exists, is still pending,
     * belongs to this exact session/line/team/condition/uploader, and is
     * not expired — never attaches a token that fails any of those,
     * never touches any OTHER pending photo.
     *
     * @param string[] $tokens
     * @return int how many photos were attached (== count($tokens) or this throws)
     */
    public static function attachExplicit(
        PDO $pdo,
        array $tokens,
        int $findingId,
        int $sessionId,
        int $lineId,
        string $role,
        string $conditionType,
        int $userId
    ): int {
        if ($tokens === []) {
            return 0;
        }
        $role = strtoupper($role);
        $expiry = date('Y-m-d H:i:s', time() - self::PENDING_EXPIRY_HOURS * 3600);
        $seen = [];
        $lookup = $pdo->prepare('SELECT * FROM stock_opname_finding_photos WHERE upload_token = :token');
        $attach = $pdo->prepare('UPDATE stock_opname_finding_photos SET finding_id = :fid, attached_at = :now WHERE id = :id');
        $attached = 0;
        foreach ($tokens as $token) {
            $token = (string) $token;
            if (isset($seen[$token])) {
                throw new ValidationException(["photo token {$token} was supplied more than once"]);
            }
            $seen[$token] = true;

            $lookup->execute(['token' => $token]);
            $photo = $lookup->fetch();
            if (!$photo) {
                throw new ValidationException(["photo token {$token} does not exist"]);
            }
            if ($photo['finding_id'] !== null) {
                throw new ValidationException(["photo token {$token} is already attached to another finding"]);
            }
            if ((int) $photo['session_id'] !== $sessionId || (int) $photo['stock_opname_line_id'] !== $lineId) {
                throw new ValidationException(["photo token {$token} does not belong to this session/item"]);
            }
            if ($photo['team_role'] !== $role) {
                throw new ValidationException(["photo token {$token} was not uploaded by this team"]);
            }
            if ($photo['condition_type'] !== $conditionType) {
                throw new ValidationException(["photo token {$token} was uploaded for {$photo['condition_type']}, not {$conditionType}"]);
            }
            if ((int) $photo['uploaded_by'] !== $userId) {
                throw new ValidationException(["photo token {$token} was not uploaded by you"]);
            }
            if ($photo['uploaded_at'] < $expiry) {
                throw new ValidationException(["photo token {$token} has expired — please upload it again"]);
            }

            $attach->execute(['fid' => $findingId, 'now' => date('Y-m-d H:i:s'), 'id' => $photo['id']]);
            $attached++;
        }
        return $attached;
    }

    /**
     * PHASE V2.14.11.1 item E — deletes pending (finding_id IS NULL)
     * photos older than PENDING_EXPIRY_HOURS, filesystem file first
     * (harmless if already gone), DB row second, so a crash mid-cleanup
     * never leaves an orphan file with no DB row to eventually reclaim
     * it. Runs opportunistically (called from upload()) — no cron
     * required. $lineId narrows the scan to one session line when given
     * (the common case, called from upload()); omitted, it sweeps the
     * whole table — safe to call that way too (e.g. from a future admin
     * action), just not on every request.
     */
    public static function cleanupExpiredPending(PDO $pdo, ?int $lineId = null): int
    {
        $expiry = date('Y-m-d H:i:s', time() - self::PENDING_EXPIRY_HOURS * 3600);
        $sql = 'SELECT id, storage_path FROM stock_opname_finding_photos WHERE finding_id IS NULL AND uploaded_at < :expiry';
        $params = ['expiry' => $expiry];
        if ($lineId !== null) {
            $sql .= ' AND stock_opname_line_id = :line_id';
            $params['line_id'] = $lineId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return 0;
        }

        $delete = $pdo->prepare('DELETE FROM stock_opname_finding_photos WHERE id = :id AND finding_id IS NULL');
        $count = 0;
        foreach ($rows as $row) {
            @unlink(self::absolutePath($row['storage_path']));
            $delete->execute(['id' => $row['id']]);
            $count += $delete->rowCount();
        }
        return $count;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * MIME-sniffs the REAL file content (never trusts the client-supplied
     * extension/Content-Type), decodes and re-encodes it through GD —
     * which both strips all embedded metadata (EXIF/GPS/etc, never
     * preserved) and guarantees the stored bytes are a genuine,
     * fully-decoded raster image rather than a disguised payload — and
     * caps the longest side at MAX_DIMENSION. Returns the re-encoded
     * bytes and the (possibly normalized, e.g. png stays png, everything
     * else becomes jpeg) output MIME type; never returns the original
     * bytes verbatim.
     *
     * @return array{0:string, 1:string}
     */
    private static function validateAndReencode(string $tmpPath): array
    {
        $size = filesize($tmpPath);
        if ($size === false || $size <= 0) {
            throw new ValidationException(['uploaded file is empty or unreadable']);
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new ValidationException(['uploaded photo exceeds the ' . (self::MAX_UPLOAD_BYTES / 1024 / 1024) . 'MB limit']);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        if (!in_array($detectedMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ValidationException(['only JPEG, PNG, or WebP photos are accepted (detected: ' . ($detectedMime ?: 'unknown') . ')']);
        }

        // getimagesize() independently parses the image header structure —
        // a file that passes the MIME sniff above but fails this is
        // malformed or not really an image, and is rejected rather than
        // handed to GD.
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            throw new ValidationException(['file is not a valid image']);
        }

        $image = match ($detectedMime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png' => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
            default => false,
        };
        if ($image === false) {
            throw new ValidationException(['failed to decode image — file may be corrupt']);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            throw new ValidationException(['image has invalid dimensions']);
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            $scale = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        // PNG stays PNG (may legitimately need transparency for a
        // screenshot-style evidence photo); everything else is normalized
        // to JPEG — both paths re-encode from the decoded pixel buffer,
        // which is what actually strips metadata (EXIF lives in the file
        // container, never survives a decode/re-encode round trip).
        ob_start();
        if ($detectedMime === 'image/png') {
            imagepng($image, null, 6);
            $outMime = 'image/png';
        } else {
            imagejpeg($image, null, self::JPEG_QUALITY);
            $outMime = 'image/jpeg';
        }
        $bytes = ob_get_clean();
        imagedestroy($image);

        if ($bytes === false || $bytes === '') {
            throw new ValidationException(['failed to re-encode image']);
        }

        return [$bytes, $outMime];
    }

    /** Resolves a stored photo's absolute filesystem path for streaming back — never trusts a caller-supplied path directly. */
    public static function absolutePath(string $storagePath): string
    {
        return __DIR__ . '/../storage/stock_opname_photos/' . $storagePath;
    }
}
