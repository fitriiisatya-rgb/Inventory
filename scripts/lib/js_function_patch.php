<?php
declare(strict_types=1);

/**
 * PRODUCTION-AWARE PATCHING — shared, deliberately dumb toolkit.
 *
 * We do NOT have production's actual file bytes (only a SHA256 hash and
 * a human's description of what it contains) — so every patch script
 * built on this file follows ONE rule: locate an exact anchor; if it
 * does not appear EXACTLY ONCE, FAIL CLOSED (throw, change nothing).
 * Never guess, never fuzzy-match, never proceed on a "probably fine"
 * partial match. This is intentionally a brace-counter, not a JS
 * parser — sufficient and safe for anchoring on a named top-level
 * function, never attempting anything cleverer.
 */

final class JsPatchFailure extends RuntimeException {}

/**
 * Finds the [start, end) byte offsets of ONE top-level function whose
 * signature matches $signaturePattern (a PCRE matching just the
 * "function name(...) {" line, e.g. '/\basync function loadAll\s*\(\s*\)\s*\{/'),
 * by locating its opening brace and counting braces (naively — ignores
 * braces inside strings/comments/regex literals, which is a known,
 * accepted limitation; see each patch script's own anchor choice for
 * why that's safe for ITS specific target).
 *
 * @return array{0:int,1:int,2:string} [start offset of the signature match, end offset (exclusive, just after the closing brace), the matched signature text]
 * @throws JsPatchFailure if the signature does not appear EXACTLY ONCE, or braces never balance before EOF.
 */
function find_function_bounds(string $source, string $signaturePattern): array
{
    if (!preg_match_all($signaturePattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
        throw new JsPatchFailure("anchor not found at all: {$signaturePattern}");
    }
    if (count($matches[0]) !== 1) {
        throw new JsPatchFailure('anchor matched ' . count($matches[0]) . " times (expected exactly 1): {$signaturePattern}");
    }
    [$sigText, $sigOffset] = $matches[0][0];

    $openBrace = strpos($source, '{', $sigOffset);
    if ($openBrace === false) {
        throw new JsPatchFailure('matched signature has no opening brace: ' . $sigText);
    }

    $depth = 0;
    $len = strlen($source);
    for ($i = $openBrace; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return [$sigOffset, $i + 1, $sigText];
            }
        }
    }
    throw new JsPatchFailure('braces never balanced before EOF for signature: ' . $sigText);
}

/** Replaces the ENTIRE function (signature line through its matching closing brace) with $newFunctionText. */
function replace_function(string $source, string $signaturePattern, string $newFunctionText): string
{
    [$start, $end] = find_function_bounds($source, $signaturePattern);
    return substr($source, 0, $start) . rtrim($newFunctionText) . substr($source, $end);
}

/**
 * Inserts $insertText as the FIRST statement inside a function body
 * (immediately after its opening brace), identified the same way as
 * replace_function(). Safe regardless of what the rest of the function
 * body contains — it never has to understand it.
 */
function insert_at_function_start(string $source, string $signaturePattern, string $insertText): string
{
    if (!preg_match_all($signaturePattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
        throw new JsPatchFailure("anchor not found at all: {$signaturePattern}");
    }
    if (count($matches[0]) !== 1) {
        throw new JsPatchFailure('anchor matched ' . count($matches[0]) . " times (expected exactly 1): {$signaturePattern}");
    }
    [$sigText, $sigOffset] = $matches[0][0];
    $openBrace = strpos($source, '{', $sigOffset);
    if ($openBrace === false) {
        throw new JsPatchFailure('matched signature has no opening brace: ' . $sigText);
    }
    $insertAt = $openBrace + 1;
    return substr($source, 0, $insertAt) . "\n" . $insertText . substr($source, $insertAt);
}

/**
 * Finds a line containing the EXACT substring $exactSubstring (must
 * occur exactly once in the whole file) and inserts $insertText as a
 * new line immediately AFTER it.
 */
function insert_after_line_containing(string $source, string $exactSubstring, string $insertText): string
{
    $count = substr_count($source, $exactSubstring);
    if ($count !== 1) {
        throw new JsPatchFailure("exact anchor substring matched {$count} times (expected exactly 1): " . $exactSubstring);
    }
    $pos = strpos($source, $exactSubstring);
    $lineEnd = strpos($source, "\n", $pos);
    if ($lineEnd === false) {
        $lineEnd = strlen($source);
    }
    return substr($source, 0, $lineEnd + 1) . $insertText . "\n" . substr($source, $lineEnd + 1);
}

/** Inserts $insertText as a new line immediately BEFORE the line containing the exact, single-occurrence $exactSubstring. */
function insert_before_line_containing(string $source, string $exactSubstring, string $insertText): string
{
    $count = substr_count($source, $exactSubstring);
    if ($count !== 1) {
        throw new JsPatchFailure("exact anchor substring matched {$count} times (expected exactly 1): " . $exactSubstring);
    }
    $pos = strpos($source, $exactSubstring);
    $lineStart = $pos;
    while ($lineStart > 0 && $source[$lineStart - 1] !== "\n") {
        $lineStart--;
    }
    return substr($source, 0, $lineStart) . $insertText . "\n" . substr($source, $lineStart);
}

/**
 * Like insert_before_line_containing(), but the exact-substring search is
 * scoped to ONE named function's body (located via find_function_bounds())
 * instead of the whole file — so an anchor that legitimately repeats
 * elsewhere in the file (a call made from more than one function) can
 * still be targeted safely, as long as it appears exactly once WITHIN
 * that function.
 */
function insert_before_line_containing_within_function(string $source, string $signaturePattern, string $exactSubstring, string $insertText): string
{
    [$start, $end] = find_function_bounds($source, $signaturePattern);
    $body = substr($source, $start, $end - $start);
    $patchedBody = insert_before_line_containing($body, $exactSubstring, $insertText);
    return substr($source, 0, $start) . $patchedBody . substr($source, $end);
}

/** Verifies the file's current content hashes to $expectedSha256; throws otherwise. Call this FIRST, before any mutation. */
function assert_preimage_hash(string $source, string $expectedSha256): void
{
    $actual = hash('sha256', $source);
    if (!hash_equals(strtolower($expectedSha256), $actual)) {
        throw new JsPatchFailure("preimage SHA256 mismatch — refusing to patch. expected={$expectedSha256} actual={$actual}");
    }
}
