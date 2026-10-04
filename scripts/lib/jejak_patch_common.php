<?php
declare(strict_types=1);

/**
 * Shared, deliberately dumb helpers for the "Jejak Stock Opname" production
 * patchers (report-opname.js / app.css / index.html) + installer + rollback.
 *
 * Every helper FAILS CLOSED: on any doubt it throws/exits before writing
 * anything. Nothing here guesses, fuzzy-matches, or proceeds on a partial
 * match.
 *
 * State files written next to each patched target:
 *   <target>.pre-patch-backup      byte-exact copy of the production preimage
 *   <target>.jejak-patch.json      {preimage_sha256, postimage_sha256, ...}
 * rollback_jejak_production.php uses them to restore ONLY when the file is
 * still byte-identical to what the patcher produced.
 */

function jp_fail(string $msg): void
{
    fwrite(STDERR, "FAILED: {$msg}\n");
    exit(1);
}

/** @return array{path:string, expect:string, apply:bool} */
function jp_parse_args(array $argv, string $usage): array
{
    $args = $argv;
    array_shift($args);
    $apply = false;
    $expect = null;
    $path = null;
    foreach ($args as $arg) {
        if ($arg === '--apply') {
            $apply = true;
        } elseif (str_starts_with($arg, '--expect-sha256=')) {
            $expect = strtolower(substr($arg, strlen('--expect-sha256=')));
        } elseif ($path === null && !str_starts_with($arg, '--')) {
            $path = $arg;
        } else {
            jp_fail("unknown argument: {$arg}\nusage: {$usage}");
        }
    }
    if ($path === null || $expect === null || !preg_match('/^[0-9a-f]{64}$/', $expect)) {
        jp_fail("usage: {$usage}\n(--expect-sha256 must be the 64-hex SHA256 of the CURRENT production file)");
    }
    return ['path' => $path, 'expect' => $expect, 'apply' => $apply];
}

function jp_read(string $path): string
{
    if (!is_file($path)) {
        jp_fail("file not found: {$path}");
    }
    $data = file_get_contents($path);
    if ($data === false) {
        jp_fail("could not read: {$path}");
    }
    return $data;
}

function jp_assert_preimage(string $source, string $expect): void
{
    $actual = hash('sha256', $source);
    if (!hash_equals($expect, $actual)) {
        jp_fail("preimage SHA256 mismatch — refusing to patch. expected={$expect} actual={$actual}");
    }
    echo "OK — preimage hash matches ({$actual}).\n";
}

/** @param string[] $needles */
function jp_refuse_if_contains(string $source, array $needles): void
{
    foreach ($needles as $needle) {
        if (str_contains($source, $needle)) {
            jp_fail("'{$needle}' already present — this file looks already patched; refusing to patch a second time.");
        }
    }
}

function jp_replace_exactly_once(string $source, string $anchor, string $replacement, string $label): string
{
    $count = substr_count($source, $anchor);
    if ($count !== 1) {
        jp_fail("anchor '{$label}' matched {$count} times (expected exactly 1) — production file differs from the audited layout; nothing was written.");
    }
    echo "OK — anchor '{$label}' found exactly once.\n";
    return str_replace($anchor, $replacement, $source);
}

/** Regex variant: pattern must match EXACTLY once; $replacer receives the preg match array. */
function jp_regex_replace_exactly_once(string $source, string $pattern, callable $replacer, string $label): string
{
    $count = preg_match_all($pattern, $source);
    if ($count !== 1) {
        jp_fail("anchor '{$label}' matched " . var_export($count, true) . " times (expected exactly 1) — production file differs from the audited layout; nothing was written.");
    }
    echo "OK — anchor '{$label}' found exactly once.\n";
    $out = preg_replace_callback($pattern, $replacer, $source, 1);
    if ($out === null) {
        jp_fail("regex replacement failed for '{$label}'");
    }
    return $out;
}

// State-file suffixes are overridable (define() BEFORE require_once) so a later
// package can keep its own backup/meta beside an earlier package's without
// colliding: v2 used the defaults; v3 defines '.pre-v3-backup' / '.jejak-v3-patch.json'.
if (!defined('JP_BACKUP_SUFFIX')) {
    define('JP_BACKUP_SUFFIX', '.pre-patch-backup');
}
if (!defined('JP_META_SUFFIX')) {
    define('JP_META_SUFFIX', '.jejak-patch.json');
}

function jp_meta_path(string $path): string
{
    return $path . JP_META_SUFFIX;
}

function jp_backup_path(string $path): string
{
    return $path . JP_BACKUP_SUFFIX;
}

/**
 * Dry-run prints the resulting hash and exits 0 (writes NOTHING). Apply:
 * refuses if a backup/meta already exists, backs up, verifies the backup,
 * writes atomically, re-reads and verifies the written bytes (auto-restoring
 * from the backup on any mismatch), then records the meta file.
 */
function jp_finish(string $path, string $source, string $patched, bool $apply): void
{
    $pre = hash('sha256', $source);
    $post = hash('sha256', $patched);
    if ($patched === $source) {
        jp_fail('patch would not change the file — refusing.');
    }
    if (!$apply) {
        echo "\nDRY RUN ONLY — nothing was written. Re-run with --apply to write {$path}.\n";
        echo "Resulting file would hash to: {$post}\n";
        exit(0);
    }

    $backup = jp_backup_path($path);
    $meta = jp_meta_path($path);
    if (file_exists($backup) || file_exists($meta)) {
        jp_fail("{$backup} or {$meta} already exists — refusing to apply over a previous run. Roll back first (rollback_jejak_production.php) or remove the stale state files deliberately.");
    }
    if (!copy($path, $backup)) {
        jp_fail("could not write backup to {$backup} — aborting before touching {$path}");
    }
    if (hash_file('sha256', $backup) !== $pre) {
        @unlink($backup);
        jp_fail('backup verification failed (hash differs from preimage) — aborting before touching the target.');
    }

    $tmp = $path . '.jejak-tmp';
    if (file_put_contents($tmp, $patched) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        jp_fail("could not write {$path} (backup is safe at {$backup})");
    }
    if (hash_file('sha256', $path) !== $post) {
        copy($backup, $path);
        jp_fail("post-write verification failed — restored {$path} from {$backup}.");
    }
    file_put_contents($meta, json_encode([
        'target' => basename($path), 'preimage_sha256' => $pre, 'postimage_sha256' => $post, 'patched_at' => gmdate('c'),
    ], JSON_PRETTY_PRINT) . "\n");

    echo "\nAPPLIED. Backup: {$backup}\n";
    echo "Preimage  SHA256: {$pre}\n";
    echo "New       SHA256: {$post}\n";
}
