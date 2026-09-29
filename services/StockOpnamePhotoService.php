<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.14.11 — photo evidence for a Stock Opname finding's DAMAGED/
 * EXPIRED/DEADSTOCK condition. Uploaded BEFORE the finding it will belong
 * to exists (the mobile count card lets a counter attach photos while
 * still filling the form) — see stock_opname_finding_photos' finding_id
 * being nullable, and StockOpnameService::submitFinding(), which
 * atomically attaches every still-unattached photo matching this exact
 * session/line/role/condition/uploader the moment the finding is created.
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

    /** @return array{photo_id:int, condition_type:string, byte_size:int} */
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
        // be attached by whoever currently holds the live claim on this
        // exact line/role, so a photo can never be uploaded "on spec" for
        // an item nobody on this team is actively counting.
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

        $caption = $caption !== null && trim($caption) !== '' ? substr(trim($caption), 0, 255) : null;
        $insert = $pdo->prepare(
            'INSERT INTO stock_opname_finding_photos
                (session_id, stock_opname_line_id, team_role, condition_type, finding_id, uploaded_by, storage_path, mime_type, byte_size, caption, uploaded_at)
             VALUES (:sid, :line_id, :role, :ct, NULL, :uid, :path, :mime, :size, :caption, :now)'
        );
        $insert->execute([
            'sid' => $sessionId, 'line_id' => $line['id'], 'role' => strtoupper($role), 'ct' => $conditionType,
            'uid' => $userId, 'path' => $storedName, 'mime' => $mimeType, 'size' => strlen($reencoded),
            'caption' => $caption, 'now' => date('Y-m-d H:i:s'),
        ]);
        $photoId = (int) $pdo->lastInsertId();

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_PHOTO_UPLOAD', 'stock_opname_finding_photos', $photoId, null, [
            'item_id' => $itemId, 'role' => strtoupper($role), 'condition_type' => $conditionType, 'byte_size' => strlen($reencoded),
        ], null);

        return ['photo_id' => $photoId, 'condition_type' => $conditionType, 'byte_size' => strlen($reencoded)];
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
