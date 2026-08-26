<?php
/**
 * Independent verification harness for SNN_QRCode.
 *
 * Nothing here reuses the encoder's own helpers for the checks: the GF math,
 * the function-module map, the zigzag reader and the format-info decoder are
 * all re-derived from the ISO/IEC 18004 spec so that a bug in the encoder
 * cannot cancel itself out.
 */
define('SNN_QRCODE_STANDALONE', 1);
require __DIR__ . '/../qrcode.php';

$PASS = 0; $FAIL = 0; $FAILURES = [];
function check($cond, $label) {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; } else { $FAIL++; $FAILURES[] = $label; echo "  FAIL: $label\n"; }
}

/* ---------------- independent GF(256) arithmetic ---------------- */
$GEXP = []; $GLOG = [];
$x = 1;
for ($i = 0; $i < 256; $i++) {
    $GEXP[$i] = $x;
    if ($i < 255) $GLOG[$x] = $i;
    $x <<= 1;
    if ($x & 0x100) $x ^= 0x11d;
}
function gmul($a, $b) {
    global $GEXP, $GLOG;
    if ($a == 0 || $b == 0) return 0;
    return $GEXP[($GLOG[$a] + $GLOG[$b]) % 255];
}

/* ---------------- independent function-module map ---------------- */
// Official ISO/IEC 18004 Table E.1 alignment pattern centres, typed out
// independently of the library's own copy.
$ALIGN_TABLE = [
    1 => [],
    2 => [6,18],      3 => [6,22],      4 => [6,26],      5 => [6,30],
    6 => [6,34],      7 => [6,22,38],   8 => [6,24,42],   9 => [6,26,46],
    10 => [6,28,50],  11 => [6,30,54],  12 => [6,32,58],  13 => [6,34,62],
    14 => [6,26,46,66], 15 => [6,26,48,70], 16 => [6,26,50,74], 17 => [6,30,54,78],
    18 => [6,30,56,82], 19 => [6,30,58,86], 20 => [6,34,62,90],
    21 => [6,28,50,72,94],  22 => [6,26,50,74,98],  23 => [6,30,54,78,102],
    24 => [6,28,54,80,106], 25 => [6,32,58,84,110], 26 => [6,30,58,86,114],
    27 => [6,34,62,90,118],
    28 => [6,26,50,74,98,122],  29 => [6,30,54,78,102,126], 30 => [6,26,52,78,104,130],
    31 => [6,30,56,82,108,134], 32 => [6,34,60,86,112,138], 33 => [6,30,58,86,114,142],
    34 => [6,34,62,90,118,146],
    35 => [6,30,54,78,102,126,150], 36 => [6,24,50,76,102,128,154],
    37 => [6,28,54,80,106,132,158], 38 => [6,32,58,84,110,136,162],
    39 => [6,26,54,82,110,138,166], 40 => [6,30,58,86,114,142,170],
];

function alignPositions($v) {
    global $ALIGN_TABLE;
    return $ALIGN_TABLE[$v];
}

/** true where the module is reserved (not data/EC payload). */
function functionMap($v) {
    $mc = $v * 4 + 17;
    $m = array_fill(0, $mc, array_fill(0, $mc, false));

    $mark = function ($r0, $c0, $h, $w) use (&$m, $mc) {
        for ($r = $r0; $r < $r0 + $h; $r++) {
            for ($c = $c0; $c < $c0 + $w; $c++) {
                if ($r >= 0 && $r < $mc && $c >= 0 && $c < $mc) $m[$r][$c] = true;
            }
        }
    };

    // finders + separators (8x8 blocks at three corners)
    $mark(0, 0, 8, 8);
    $mark(0, $mc - 8, 8, 8);
    $mark($mc - 8, 0, 8, 8);

    // timing
    for ($i = 0; $i < $mc; $i++) { $m[6][$i] = true; $m[$i][6] = true; }

    // alignment
    $pos = alignPositions($v);
    foreach ($pos as $r) {
        foreach ($pos as $c) {
            if (($r == 6 && $c == 6) || ($r == 6 && $c == $mc - 7) || ($r == $mc - 7 && $c == 6)) continue;
            $mark($r - 2, $c - 2, 5, 5);
        }
    }

    // format info
    for ($i = 0; $i < 9; $i++) { $m[8][$i] = true; $m[$i][8] = true; }
    for ($i = 0; $i < 8; $i++) { $m[8][$mc - 1 - $i] = true; $m[$mc - 1 - $i][8] = true; }

    // version info
    if ($v >= 7) {
        $mark(0, $mc - 11, 6, 3);
        $mark($mc - 11, 0, 3, 6);
    }

    return $m;
}

function maskBit($pattern, $i, $j) {
    switch ($pattern) {
        case 0: return ($i + $j) % 2 == 0;
        case 1: return $i % 2 == 0;
        case 2: return $j % 3 == 0;
        case 3: return ($i + $j) % 3 == 0;
        case 4: return (intdiv($i, 2) + intdiv($j, 3)) % 2 == 0;
        case 5: return ($i * $j) % 2 + ($i * $j) % 3 == 0;
        case 6: return (($i * $j) % 2 + ($i * $j) % 3) % 2 == 0;
        case 7: return (($i * $j) % 3 + ($i + $j) % 2) % 2 == 0;
    }
    throw new Exception('bad mask');
}

/* ---------------- pixel helper (handles palette + truecolor PNGs) -------- */
function pixelIsDark($im, $x, $y) {
    $idx = imagecolorat($im, $x, $y);
    if (imageistruecolor($im)) {
        return (($idx >> 16) & 0xff) < 128;
    }
    $c = imagecolorsforindex($im, $idx);
    return $c['red'] < 128;
}

function pixelIsWhite($im, $x, $y) {
    $idx = imagecolorat($im, $x, $y);
    if (imageistruecolor($im)) {
        return ($idx & 0xffffff) === 0xffffff;
    }
    $c = imagecolorsforindex($im, $idx);
    return $c['red'] === 255 && $c['green'] === 255 && $c['blue'] === 255;
}

/* ---------------- format info: brute-force BCH decode ---------------- */
function formatBits($ecIndicator, $mask) {
    // ecIndicator: L=01, M=00, Q=11, H=10
    $data = ($ecIndicator << 3) | $mask;
    $d = $data << 10;
    while (strlen(decbin($d)) - strlen(decbin(0x537)) >= 0 && $d >= 0x400) {
        $d ^= (0x537 << (strlen(decbin($d)) - strlen(decbin(0x537))));
    }
    return ((($data << 10) | $d) ^ 0x5412) & 0x7fff;
}

function decodeFormat($bits15) {
    $best = null; $bestDist = 99;
    for ($ec = 0; $ec < 4; $ec++) {
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = formatBits($ec, $mask);
            $dist = substr_count(decbin($cand ^ $bits15), '1');
            if ($dist < $bestDist) { $bestDist = $dist; $best = [$ec, $mask, $dist]; }
        }
    }
    return $best;
}

/* ---------------- read the matrix back ---------------- */
function readCodewords($matrix, $v, $mask) {
    $mc = $v * 4 + 17;
    $fn = functionMap($v);

    $bits = '';
    $row = $mc - 1; $inc = -1;
    for ($col = $mc - 1; $col > 0; $col -= 2) {
        if ($col == 6) $col--;
        while (true) {
            for ($c = 0; $c < 2; $c++) {
                $cc = $col - $c;
                if (!$fn[$row][$cc]) {
                    $dark = $matrix[$row][$cc];
                    if (maskBit($mask, $row, $cc)) $dark = !$dark;
                    $bits .= $dark ? '1' : '0';
                }
            }
            $row += $inc;
            if ($row < 0 || $row >= $mc) { $row -= $inc; $inc = -$inc; break; }
        }
    }

    $codewords = [];
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $codewords[] = bindec(substr($bits, $i, 8));
    }
    return $codewords;
}

/* ---------------- de-interleave using the block layout ---------------- */
function deinterleave($codewords, $v, $ecLevel) {
    $blocks = SNN_QR_RSBlock::getRSBlocks($v, $ecLevel);
    $dc = []; $ec = []; $maxD = 0; $maxE = 0;
    foreach ($blocks as $i => $b) {
        $dc[$i] = array_fill(0, $b->dataCount, 0);
        $ec[$i] = array_fill(0, $b->totalCount - $b->dataCount, 0);
        $maxD = max($maxD, $b->dataCount);
        $maxE = max($maxE, $b->totalCount - $b->dataCount);
    }

    $idx = 0;
    for ($i = 0; $i < $maxD; $i++) {
        foreach ($blocks as $r => $b) {
            if ($i < $b->dataCount) $dc[$r][$i] = $codewords[$idx++];
        }
    }
    for ($i = 0; $i < $maxE; $i++) {
        foreach ($blocks as $r => $b) {
            if ($i < $b->totalCount - $b->dataCount) $ec[$r][$i] = $codewords[$idx++];
        }
    }
    return [$dc, $ec, $blocks];
}

/* ---------------- RS syndrome check (independent of the encoder) ---------------- */
function syndromesZero($block) {
    global $GEXP;
    $n = count($block);
    // For a valid RS codeword every syndrome S_i = sum(c_k * a^(i*(n-1-k))) must be 0.
    $ecCount = null;
    return function ($ecCount) use ($block, $n, $GEXP) {
        for ($i = 0; $i < $ecCount; $i++) {
            $s = 0;
            for ($k = 0; $k < $n; $k++) {
                $s ^= gmul($block[$k], $GEXP[($i * ($n - 1 - $k)) % 255]);
            }
            if ($s != 0) return false;
        }
        return true;
    };
}

function payloadFromDataCodewords($dc, $v) {
    $bits = '';
    foreach ($dc as $block) {
        foreach ($block as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    }
    $mode = bindec(substr($bits, 0, 4));
    if ($mode != 4) return [null, "mode=$mode (expected 4 / byte)"];
    $lenBits = ($v < 10) ? 8 : 16;
    $len = bindec(substr($bits, 4, $lenBits));
    $out = '';
    $p = 4 + $lenBits;
    for ($i = 0; $i < $len; $i++) {
        $chunk = substr($bits, $p, 8);
        if (strlen($chunk) < 8) return [null, 'truncated payload'];
        $out .= chr(bindec($chunk));
        $p += 8;
    }
    return [$out, null];
}

/* ================= TESTS ================= */

echo "PHP " . PHP_VERSION . " | GD: " . (extension_loaded('gd') ? 'yes' : 'no')
   . " | zlib: " . (function_exists('gzcompress') ? 'yes' : 'no') . "\n\n";

$levels = [
    'L' => SNN_QRCode::ERROR_CORRECT_L,
    'M' => SNN_QRCode::ERROR_CORRECT_M,
    'Q' => SNN_QRCode::ERROR_CORRECT_Q,
    'H' => SNN_QRCode::ERROR_CORRECT_H,
];
// spec bit pattern for the EC level, used to check the format info
$ecIndicator = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

echo "== 1. Round-trip: every version 1-40 x every EC level, at full capacity ==\n";
foreach ($levels as $name => $lvl) {
    for ($v = 1; $v <= 40; $v++) {
        $blocks = SNN_QR_RSBlock::getRSBlocks($v, $lvl);
        $totalData = 0;
        foreach ($blocks as $b) $totalData += $b->dataCount;
        $lenBits = ($v < 10) ? 8 : 16;
        $capacity = intdiv($totalData * 8 - 4 - $lenBits, 8);
        if ($v < 10) $capacity = min($capacity, 255);

        // payload of exactly the maximum length, pseudo-random but reproducible
        mt_srand($v * 100 + $lvl);
        $payload = '';
        for ($i = 0; $i < $capacity; $i++) $payload .= chr(mt_rand(32, 126));

        try {
            $qr = new SNN_QRCode($payload, ['typeNumber' => $v, 'errorCorrectLevel' => $lvl]);
        } catch (Throwable $e) {
            check(false, "v$v/$name encode threw: " . $e->getMessage());
            continue;
        }

        $matrix = $qr->getMatrix();
        $mc = $v * 4 + 17;

        // -- format info round-trip
        $fbits = 0;
        for ($i = 0; $i < 15; $i++) {
            if ($i < 6)      $bit = $matrix[$i][8];
            elseif ($i < 8)  $bit = $matrix[$i + 1][8];
            else             $bit = $matrix[$mc - 15 + $i][8];
            if ($bit) $fbits |= (1 << $i);
        }
        list($decEc, $decMask, $dist) = decodeFormat($fbits);
        check($dist === 0, "v$v/$name format info not an exact BCH codeword (dist=$dist)");
        check($decEc === $ecIndicator[$name], "v$v/$name format info EC level wrong (got $decEc)");

        // second copy of the format info must agree
        $fbits2 = 0;
        for ($i = 0; $i < 15; $i++) {
            if ($i < 8)      $bit = $matrix[8][$mc - $i - 1];
            elseif ($i < 9)  $bit = $matrix[8][15 - $i - 1 + 1];
            else             $bit = $matrix[8][15 - $i - 1];
            if ($bit) $fbits2 |= (1 << $i);
        }
        check($fbits === $fbits2, "v$v/$name the two format info copies disagree");

        // -- payload round-trip
        $cw = readCodewords($matrix, $v, $decMask);
        list($dc, $ec, $blks) = deinterleave($cw, $v, $lvl);
        list($decoded, $err) = payloadFromDataCodewords($dc, $v);
        check($decoded === $payload, "v$v/$name payload mismatch" . ($err ? " ($err)" : ''));

        // -- Reed-Solomon syndromes must all vanish
        $rsOk = true;
        foreach ($blks as $bi => $b) {
            $full = array_merge($dc[$bi], $ec[$bi]);
            $ecCount = $b->totalCount - $b->dataCount;
            $fn = syndromesZero($full);
            if (!$fn($ecCount)) { $rsOk = false; break; }
        }
        check($rsOk, "v$v/$name Reed-Solomon syndromes non-zero");
    }
    echo "  level $name done\n";
}

echo "\n== 2. Structural patterns ==\n";
foreach ([1, 7, 14, 26, 32, 40] as $v) {
    $qr = new SNN_QRCode('sc', ['typeNumber' => $v]);
    $m = $qr->getMatrix();
    $mc = $v * 4 + 17;
    check(count($m) === $mc && count($m[0]) === $mc, "v$v module count is $mc");

    foreach ([[0,0],[0,$mc-7],[$mc-7,0]] as list($r0, $c0)) {
        $ok = true;
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $expect = ($r == 0 || $r == 6 || $c == 0 || $c == 6)
                       || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                if ($m[$r0 + $r][$c0 + $c] !== $expect) { $ok = false; break 2; }
            }
        }
        check($ok, "v$v finder pattern at ($r0,$c0)");
    }

    $ok = true;
    for ($i = 8; $i < $mc - 8; $i++) {
        if ($m[6][$i] !== ($i % 2 == 0)) $ok = false;
        if ($m[$i][6] !== ($i % 2 == 0)) $ok = false;
    }
    check($ok, "v$v timing patterns");
    check($m[$mc - 8][8] === true, "v$v dark module");

    // separators must be light all the way round each finder
    $sepOk = true;
    for ($i = 0; $i < 8; $i++) {
        if ($m[7][$i] || $m[$i][7]) $sepOk = false;
        if ($m[7][$mc - 1 - $i] || $m[$i][$mc - 8]) $sepOk = false;
        if ($m[$mc - 8][$i] || $m[$mc - 1 - $i][7]) $sepOk = false;
    }
    check($sepOk, "v$v finder separators");

    // version info blocks, present from v7 up
    if ($v >= 7) {
        $bits = 0;
        for ($i = 0; $i < 18; $i++) {
            if ($m[intdiv($i, 3)][$i % 3 + $mc - 8 - 3]) $bits |= (1 << $i);
        }
        check(($bits >> 12) === $v, "v$v version info encodes $v (got " . ($bits >> 12) . ")");
    }

    // alignment pattern centres
    foreach (alignPositions($v) as $ar) {
        foreach (alignPositions($v) as $ac) {
            if (($ar == 6 && $ac == 6) || ($ar == 6 && $ac == $mc - 7) || ($ar == $mc - 7 && $ac == 6)) continue;
            check($m[$ar][$ac] === true && $m[$ar][$ac - 1] === false,
                  "v$v alignment pattern at ($ar,$ac)");
        }
    }
}

echo "\n== 3. Automatic version selection ==\n";
$cases = [
    ['A', SNN_QRCode::ERROR_CORRECT_M, 1],
    [str_repeat('A', 14), SNN_QRCode::ERROR_CORRECT_M, 1],
    [str_repeat('A', 15), SNN_QRCode::ERROR_CORRECT_M, 2],
    ['https://example.com/ticket-scan/?c=ABC12345&s=9f3a2b1c8d7e6f50', SNN_QRCode::ERROR_CORRECT_M, null],
];
foreach ($cases as list($text, $lvl, $expect)) {
    $qr = new SNN_QRCode($text, ['errorCorrectLevel' => $lvl]);
    $got = $qr->getTypeNumber();
    if ($expect !== null) {
        check($got === $expect, "auto version for " . strlen($text) . " bytes: expected $expect got $got");
    } else {
        echo "  " . strlen($text) . "-byte URL -> version $got (" . ($got * 4 + 17) . " modules)\n";
        check($got >= 1 && $got <= 10, "URL fits in a small version (got $got)");
    }
    // and it must still decode
    $m = $qr->getMatrix(); $v = $got; $mc = $v * 4 + 17;
    $fbits = 0;
    for ($i = 0; $i < 15; $i++) {
        if ($i < 6) $bit = $m[$i][8]; elseif ($i < 8) $bit = $m[$i + 1][8]; else $bit = $m[$mc - 15 + $i][8];
        if ($bit) $fbits |= (1 << $i);
    }
    list($e2, $mask2,) = decodeFormat($fbits);
    list($dc,,) = deinterleave(readCodewords($m, $v, $mask2), $v, $lvl);
    list($dec,) = payloadFromDataCodewords($dc, $v);
    check($dec === $text, "auto-version payload round-trip for " . strlen($text) . " bytes");
}

// the old library capped at version 4 / ECC H (~34 bytes) and threw; auto must not
$long = str_repeat('x', 200);
try {
    $qr = new SNN_QRCode($long, ['errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_H]);
    check($qr->getTypeNumber() > 4, "200 bytes at ECC H picks version > 4 (got {$qr->getTypeNumber()})");
} catch (Throwable $e) {
    check(false, "200 bytes at ECC H threw: " . $e->getMessage());
}

// and genuinely oversized input must fail cleanly, not silently truncate
try {
    new SNN_QRCode(str_repeat('x', 3000), ['errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_H]);
    check(false, "oversized input should throw");
} catch (Exception $e) {
    check(str_contains($e->getMessage(), 'too long'), "oversized input throws a clear error");
}

echo "\n== 4. Payload content edge cases ==\n";
$payloads = [
    'empty-ish'   => ' ',
    'utf8'        => 'Bilet: Şükrü Ünlü — ödeme ✓',
    'binary-ish'  => "\x01\x02\xfe\xff\x00nope",
    'url'         => 'https://snn.example/scan/?c=7KQ2M9XZ&s=' . str_repeat('a', 32),
    'json'        => '{"t":"ABC12345","e":42,"s":"deadbeefcafe"}',
    'newlines'    => "line1\nline2\r\nline3",
];
foreach ($payloads as $label => $text) {
    foreach ($levels as $lname => $lvl) {
        $qr = new SNN_QRCode($text, ['errorCorrectLevel' => $lvl]);
        $v = $qr->getTypeNumber(); $m = $qr->getMatrix(); $mc = $v * 4 + 17;
        $fbits = 0;
        for ($i = 0; $i < 15; $i++) {
            if ($i < 6) $bit = $m[$i][8]; elseif ($i < 8) $bit = $m[$i + 1][8]; else $bit = $m[$mc - 15 + $i][8];
            if ($bit) $fbits |= (1 << $i);
        }
        list(, $mask2,) = decodeFormat($fbits);
        list($dc,,) = deinterleave(readCodewords($m, $v, $mask2), $v, $lvl);
        list($dec,) = payloadFromDataCodewords($dc, $v);
        check($dec === $text, "payload '$label' at level $lname round-trips");
    }
}

echo "\n== 5. PNG output ==\n";
$qr = new SNN_QRCode('PNG OUTPUT TEST', ['errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_M]);
$mc = $qr->getModuleCount();

$png = $qr->toPng(8, 4);
check(substr($png, 0, 8) === "\x89PNG\r\n\x1a\n", 'PNG signature');
$expectSize = $mc * 8 + 4 * 2 * 8;
check($qr->getImageSize(8, 4) === $expectSize, "reported image size $expectSize");

if (extension_loaded('gd')) {
    $im = imagecreatefromstring($png);
    check($im !== false, 'GD can re-read its own PNG');
    check(imagesx($im) === $expectSize && imagesy($im) === $expectSize,
          "GD PNG is {$expectSize}x{$expectSize} (got " . imagesx($im) . 'x' . imagesy($im) . ')');

    // every pixel must match the module it covers
    $off = 4 * 8; $ok = true;
    $matrix = $qr->getMatrix();
    for ($r = 0; $r < $mc && $ok; $r++) {
        for ($c = 0; $c < $mc; $c++) {
            if (pixelIsDark($im, $off + $c * 8 + 4, $off + $r * 8 + 4) !== $matrix[$r][$c]) {
                $ok = false; break;
            }
        }
    }
    check($ok, 'GD PNG pixels match the module matrix');

    // quiet zone must be white
    check(pixelIsWhite($im, 2, 2), 'GD PNG quiet zone is white');
    imagedestroy($im);

    // native encoder must produce a pixel-identical image
    $ref = new ReflectionMethod('SNN_QRCode', 'toPngNative');
    $ref->setAccessible(true);
    $nativePng = $ref->invoke($qr, 8, 4);
    check(substr($nativePng, 0, 8) === "\x89PNG\r\n\x1a\n", 'native PNG signature');

    $im2 = imagecreatefromstring($nativePng);
    check($im2 !== false, 'GD can read the native-encoder PNG');
    if ($im2) {
        check(imagesx($im2) === $expectSize && imagesy($im2) === $expectSize,
              'native PNG has the right dimensions');
        $same = true;
        for ($r = 0; $r < $mc && $same; $r++) {
            for ($c = 0; $c < $mc; $c++) {
                if (pixelIsDark($im2, $off + $c * 8 + 4, $off + $r * 8 + 4) !== $matrix[$r][$c]) {
                    $same = false; break;
                }
            }
        }
        check($same, 'native PNG pixels match the module matrix');
        check(pixelIsWhite($im2, 2, 2), 'native PNG quiet zone is white');
        imagedestroy($im2);
    }
} else {
    echo "  (GD absent - PNG pixel comparison skipped, native encoder still exercised)\n";
}

$uri = $qr->toDataUri(8, 4);
check(str_starts_with($uri, 'data:image/png;base64,'), 'data URI prefix');
check(base64_decode(substr($uri, 22), true) === $png, 'data URI decodes back to the PNG');

$tmp = sys_get_temp_dir() . '/snn_qr_test_' . getmypid() . '.png';
check($qr->savePng($tmp, 6, 4) === true, 'savePng writes a file');
check(file_exists($tmp) && filesize($tmp) > 0, 'saved PNG is non-empty');
@unlink($tmp);

// scale/margin variations
foreach ([[1, 0], [1, 4], [3, 2], [16, 4]] as list($s, $mg)) {
    $b = $qr->toPng($s, $mg);
    $want = $mc * $s + $mg * 2 * $s;
    if (extension_loaded('gd')) {
        $i = imagecreatefromstring($b);
        check($i && imagesx($i) === $want, "scale=$s margin=$mg gives {$want}px");
        if ($i) imagedestroy($i);
    } else {
        check(strlen($b) > 0, "scale=$s margin=$mg produced bytes");
    }
}

echo "\n== 6. SVG output ==\n";
$svg = $qr->toSvg(8, 4);
check(str_starts_with($svg, '<svg'), 'SVG root element');
check(str_contains($svg, 'width="' . $expectSize . '"'), 'SVG width matches PNG size');
check(substr_count($svg, '<path') === 1, 'SVG uses a single merged path');
check(str_ends_with(trim($svg), '</svg>'), 'SVG is closed');

echo "\n== 7. Determinism and isolation ==\n";
$a = (new SNN_QRCode('repeatable', ['errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_Q]))->getMatrix();
$b = (new SNN_QRCode('repeatable', ['errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_Q]))->getMatrix();
check($a === $b, 'same input produces the same matrix');
$c = (new SNN_QRCode('repeatablf', ['errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_Q]))->getMatrix();
check($a !== $c, 'a one-character change alters the matrix');
check(!class_exists('QRCode', false), 'no global QRCode class is declared (no plugin collisions)');

echo "\n== 8. Performance sanity ==\n";
$t = microtime(true);
for ($i = 0; $i < 50; $i++) {
    $q = new SNN_QRCode('https://example.com/scan/?c=CODE' . $i . '&s=abcdef0123456789');
    $q->toPng(8, 4);
}
$ms = (microtime(true) - $t) * 1000;
printf("  50 typical ticket QRs (encode + PNG): %.0f ms total, %.1f ms each\n", $ms, $ms / 50);
check($ms / 50 < 250, 'under 250ms per ticket QR');

echo "\n";
echo str_repeat('=', 58) . "\n";
echo "PASS: $PASS   FAIL: $FAIL\n";
if ($FAIL) {
    echo "\nFailures:\n";
    foreach (array_slice($FAILURES, 0, 40) as $f) echo "  - $f\n";
    if (count($FAILURES) > 40) echo "  ... and " . (count($FAILURES) - 40) . " more\n";
    exit(1);
}
echo "ALL CHECKS PASSED\n";
exit(0);
