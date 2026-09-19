<?php
/**
 * A small PDF writer and the ticket PDF built with it.
 *
 * Like the QR encoder, it has no dependencies. It uses the standard PDF
 * fonts (Helvetica, Times, Courier) with a custom encoding that covers
 * Latin-1 plus Turkish and common Central European letters, so names like
 * "Şükrü Ağaoğlu" or "Łukasz" come out right without embedding a font file.
 * The QR is drawn as vector squares, so it stays sharp at any zoom.
 */

if (!defined('ABSPATH') && php_sapi_name() !== 'cli') exit;

class SNN_T_PDF_Doc {

    private $w;
    private $h;
    private $pages   = [];
    private $page    = -1;
    private $images  = [];

    /** Glyphs placed at 128-159 on top of the Latin-1 range. */
    const EXTRA_GLYPHS = [
        0x011E => 'Gbreve',    0x011F => 'gbreve',     0x0130 => 'Idotaccent', 0x0131 => 'dotlessi',
        0x015E => 'Scedilla',  0x015F => 'scedilla',   0x0160 => 'Scaron',     0x0161 => 'scaron',
        0x017D => 'Zcaron',    0x017E => 'zcaron',     0x0106 => 'Cacute',     0x0107 => 'cacute',
        0x010C => 'Ccaron',    0x010D => 'ccaron',     0x0141 => 'Lslash',     0x0142 => 'lslash',
        0x0143 => 'Nacute',    0x0144 => 'nacute',     0x015A => 'Sacute',     0x015B => 'sacute',
        0x0179 => 'Zacute',    0x017A => 'zacute',     0x017B => 'Zdotaccent', 0x017C => 'zdotaccent',
        0x0104 => 'Aogonek',   0x0105 => 'aogonek',    0x0118 => 'Eogonek',    0x0119 => 'eogonek',
        0x2013 => 'endash',    0x2014 => 'emdash',     0x2019 => 'quoteright', 0x20AC => 'Euro',
    ];

    /** Latin-1 glyph names for 160-255, in order. */
    const LATIN1 = 'space exclamdown cent sterling currency yen brokenbar section dieresis copyright ordfeminine guillemotleft logicalnot hyphen registered macron degree plusminus twosuperior threesuperior acute mu paragraph periodcentered cedilla onesuperior ordmasculine guillemotright onequarter onehalf threequarters questiondown Agrave Aacute Acircumflex Atilde Adieresis Aring AE Ccedilla Egrave Eacute Ecircumflex Edieresis Igrave Iacute Icircumflex Idieresis Eth Ntilde Ograve Oacute Ocircumflex Otilde Odieresis multiply Oslash Ugrave Uacute Ucircumflex Udieresis Yacute Thorn germandbls agrave aacute acircumflex atilde adieresis aring ae ccedilla egrave eacute ecircumflex edieresis igrave iacute icircumflex idieresis eth ntilde ograve oacute ocircumflex otilde odieresis divide oslash ugrave uacute ucircumflex udieresis yacute thorn ydieresis';

    /** Helvetica advance widths for 32..126. */
    const W_REGULAR = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    const W_BOLD    = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    /** Font resource name => [base font, bold widths?, monospace?] */
    const FONTS = [
        'F1' => ['Helvetica', false, false],
        'F2' => ['Helvetica-Bold', true, false],
        'F3' => ['Times-Roman', false, false],
        'F4' => ['Times-Bold', true, false],
        'F5' => ['Courier-Bold', true, true],
    ];

    private static $map = null;

    public function __construct($w = 595.28, $h = 841.89) {
        $this->w = $w;
        $this->h = $h;
    }

    public function width()  { return $this->w; }
    public function height() { return $this->h; }

    public function add_page() {
        $this->pages[] = '';
        $this->page = count($this->pages) - 1;
        return $this;
    }

    private function out($s) {
        if ($this->page < 0) $this->add_page();
        $this->pages[$this->page] .= $s . "\n";
    }

    private static function n($v) {
        return rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
    }

    /** y is measured from the top, like CSS. */
    private function y($y) { return $this->h - $y; }

    /* ------------------------------------------------------------------
     * Graphics
     * ---------------------------------------------------------------- */

    public static function color_ops($hex, $stroke = false) {
        $hex = ltrim((string)$hex, '#');
        if (strlen($hex) !== 6) $hex = '000000';
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        return self::n($r) . ' ' . self::n($g) . ' ' . self::n($b) . ($stroke ? ' RG' : ' rg');
    }

    public function fill($hex)   { $this->out(self::color_ops($hex)); return $this; }
    public function stroke($hex) { $this->out(self::color_ops($hex, true)); return $this; }
    public function line_width($w) { $this->out(self::n($w) . ' w'); return $this; }

    public function dash($on = 0, $off = 0) {
        $this->out($on ? '[' . self::n($on) . ' ' . self::n($off) . '] 0 d' : '[] 0 d');
        return $this;
    }

    /** $mode: 'f' fill, 'S' stroke, 'B' both */
    public function rect($x, $y, $w, $h, $mode = 'f') {
        $this->out(self::n($x) . ' ' . self::n($this->y($y + $h)) . ' ' . self::n($w) . ' ' . self::n($h) . ' re ' . $mode);
        return $this;
    }

    public function rounded_rect($x, $y, $w, $h, $r, $mode = 'f') {
        $r = max(0, min($r, $w / 2, $h / 2));
        if ($r <= 0) return $this->rect($x, $y, $w, $h, $mode);
        $k  = 0.5523 * $r;
        $x2 = $x + $w; $y2 = $y + $h;
        $p  = function ($px, $py) { return self::n($px) . ' ' . self::n($this->y($py)); };
        $this->out(
            $p($x + $r, $y) . ' m '
          . $p($x2 - $r, $y) . ' l '
          . $p($x2 - $r + $k, $y) . ' ' . $p($x2, $y + $r - $k) . ' ' . $p($x2, $y + $r) . ' c '
          . $p($x2, $y2 - $r) . ' l '
          . $p($x2, $y2 - $r + $k) . ' ' . $p($x2 - $r + $k, $y2) . ' ' . $p($x2 - $r, $y2) . ' c '
          . $p($x + $r, $y2) . ' l '
          . $p($x + $r - $k, $y2) . ' ' . $p($x, $y2 - $r + $k) . ' ' . $p($x, $y2 - $r) . ' c '
          . $p($x, $y + $r) . ' l '
          . $p($x, $y + $r - $k) . ' ' . $p($x + $r - $k, $y) . ' ' . $p($x + $r, $y) . ' c h ' . $mode
        );
        return $this;
    }

    /** Start clipping to a rounded rectangle; pair with restore(). */
    public function clip_rounded($x, $y, $w, $h, $r) {
        $this->out('q');
        return $this->rounded_rect($x, $y, $w, $h, $r, 'W n');
    }

    public function restore() { $this->out('Q'); return $this; }

    public function circle($cx, $cy, $r, $mode = 'f') {
        $k = 0.5523 * $r;
        $p = function ($px, $py) { return self::n($px) . ' ' . self::n($this->y($py)); };
        $this->out(
            $p($cx + $r, $cy) . ' m '
          . $p($cx + $r, $cy + $k) . ' ' . $p($cx + $k, $cy + $r) . ' ' . $p($cx, $cy + $r) . ' c '
          . $p($cx - $k, $cy + $r) . ' ' . $p($cx - $r, $cy + $k) . ' ' . $p($cx - $r, $cy) . ' c '
          . $p($cx - $r, $cy - $k) . ' ' . $p($cx - $k, $cy - $r) . ' ' . $p($cx, $cy - $r) . ' c '
          . $p($cx + $k, $cy - $r) . ' ' . $p($cx + $r, $cy - $k) . ' ' . $p($cx + $r, $cy) . ' c h ' . $mode
        );
        return $this;
    }

    public function line($x1, $y1, $x2, $y2) {
        $this->out(self::n($x1) . ' ' . self::n($this->y($y1)) . ' m ' . self::n($x2) . ' ' . self::n($this->y($y2)) . ' l S');
        return $this;
    }

    /** Two-colour horizontal gradient faked with thin bands (no shading objects needed). */
    public function gradient_rect($x, $y, $w, $h, $from, $to, $steps = 60) {
        $band = $w / $steps;
        for ($i = 0; $i < $steps; $i++) {
            $this->fill(SNN_T_Design::mix($from, $to, $i / max(1, $steps - 1)));
            $this->rect($x + $i * $band, $y, $band + 0.6, $h);
        }
        return $this;
    }

    /**
     * Draw a QR matrix as vector squares. Runs of dark modules in a row
     * become one rectangle, which keeps the file small.
     */
    public function qr($matrix, $x, $y, $size, $dark = '#000000') {
        $n = count($matrix);
        if (!$n) return $this;
        $m = $size / $n;
        $ops = [self::color_ops($dark)];
        foreach ($matrix as $r => $row) {
            $c = 0;
            while ($c < $n) {
                if (!$row[$c]) { $c++; continue; }
                $start = $c;
                while ($c < $n && $row[$c]) $c++;
                // Slight overlap hides hairline seams in some viewers.
                $ops[] = self::n($x + $start * $m) . ' ' . self::n($this->y($y + ($r + 1) * $m)) . ' '
                       . self::n(($c - $start) * $m + 0.05) . ' ' . self::n($m + 0.05) . ' re';
            }
        }
        $ops[] = 'f';
        $this->out(implode("\n", $ops));
        return $this;
    }

    /**
     * Place a JPEG. PNGs and GIFs are converted through GD when it is
     * available; otherwise they are skipped.
     *
     * @return bool placed
     */
    public function image($data, $x, $y, $w = 0, $h = 0) {
        $info = @getimagesizefromstring($data);
        if (!$info) return false;

        if ($info[2] !== IMAGETYPE_JPEG) {
            if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return false;
            $src = @imagecreatefromstring($data);
            if (!$src) return false;
            $iw = imagesx($src); $ih = imagesy($src);
            $dst = imagecreatetruecolor($iw, $ih);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopy($dst, $src, 0, 0, 0, 0, $iw, $ih);
            ob_start(); imagejpeg($dst, null, 90); $data = ob_get_clean();
            $info = @getimagesizefromstring($data);
            if (!$info) return false;
        }

        $iw = $info[0]; $ih = $info[1];
        $channels = isset($info['channels']) ? (int)$info['channels'] : 3;
        if (!$w && !$h) { $w = $iw * 0.75; $h = $ih * 0.75; }
        elseif (!$w)     { $w = $h * $iw / $ih; }
        elseif (!$h)     { $h = $w * $ih / $iw; }

        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = [
            'data'  => $data,
            'w'     => $iw,
            'h'     => $ih,
            'cs'    => $channels === 4 ? 'DeviceCMYK' : ($channels === 1 ? 'DeviceGray' : 'DeviceRGB'),
        ];
        $this->out('q ' . self::n($w) . ' 0 0 ' . self::n($h) . ' ' . self::n($x) . ' ' . self::n($this->y($y + $h)) . ' cm /' . $name . ' Do Q');
        return true;
    }

    /* ------------------------------------------------------------------
     * Text
     * ---------------------------------------------------------------- */

    /** Unicode code point => [byte, glyph name, width-alike ASCII char] */
    private static function charmap() {
        if (self::$map !== null) return self::$map;
        $map = [];
        $names = explode(' ', self::LATIN1);
        foreach ($names as $i => $g) {
            $cp = 160 + $i;
            $alike = self::ascii_alike($cp);
            $map[$cp] = [$cp, $g, $alike];
        }
        $byte = 128;
        foreach (self::EXTRA_GLYPHS as $cp => $g) {
            $map[$cp] = [$byte++, $g, self::ascii_alike($cp)];
        }
        return self::$map = $map;
    }

    /** A visually similar ASCII character, used to estimate glyph widths. */
    private static function ascii_alike($cp) {
        $specials = [0x2013 => '-', 0x2014 => 'M', 0x2019 => "'", 0x20AC => '0', 0x0131 => 'i', 0x0130 => 'I',
                     0x0141 => 'L', 0x0142 => 'l', 0x00DF => 'B', 0x00C6 => 'W', 0x00E6 => 'm', 0x00D7 => '+', 0x00F7 => '+'];
        if (isset($specials[$cp])) return $specials[$cp];
        if (function_exists('iconv')) {
            $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', self::utf8_chr($cp));
            if (is_string($s) && strlen($s) === 1 && ctype_alnum($s)) return $s;
        }
        return 'o';
    }

    private static function utf8_chr($cp) {
        return mb_convert_encoding(pack('N', $cp), 'UTF-8', 'UCS-4BE');
    }

    /**
     * Convert UTF-8 to the single-byte encoding the fonts use. Characters
     * outside it are transliterated when possible, else become "?".
     */
    public static function encode($text) {
        $map = self::charmap();
        $out = '';
        $cps = self::codepoints((string)$text);
        foreach ($cps as $cp) {
            if ($cp >= 32 && $cp <= 126) { $out .= chr($cp); continue; }
            if ($cp === 9 || $cp === 10 || $cp === 13) { $out .= ' '; continue; }
            if (isset($map[$cp])) { $out .= chr($map[$cp][0]); continue; }
            if ($cp === 0x2018) { $out .= chr($map[0x2019][0]); continue; }
            if ($cp === 0x201C || $cp === 0x201D) { $out .= '"'; continue; }
            if ($cp === 0x2022 || $cp === 0x00B7) { $out .= chr(183); continue; }
            $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', self::utf8_chr($cp)) : '';
            $out .= (is_string($t) && $t !== '') ? preg_replace('/[^\x20-\x7e]/', '', $t) : '?';
        }
        return $out;
    }

    private static function codepoints($s) {
        if ($s === '') return [];
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        $u = mb_convert_encoding($s, 'UCS-4BE', 'UTF-8');
        return array_values(unpack('N*', $u));
    }

    /** Width of already-encoded bytes in points. */
    private static function encoded_width($bytes, $font, $size) {
        list(, $bold, $mono) = self::FONTS[$font];
        if ($mono) return strlen($bytes) * 600 * $size / 1000;

        static $reverse = null;
        if ($reverse === null) {
            $reverse = [];
            foreach (self::charmap() as $cp => $m) $reverse[$m[0]] = $m[2];
        }

        $widths = $bold ? self::W_BOLD : self::W_REGULAR;
        $total = 0;
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($bytes[$i]);
            if ($o >= 32 && $o <= 126) { $total += $widths[$o - 32]; continue; }
            if ($o === 183) { $total += 278; continue; }
            $alike = $reverse[$o] ?? 'o';
            $total += $widths[ord($alike) - 32];
        }
        return $total * $size / 1000;
    }

    public static function text_width($text, $font, $size) {
        return self::encoded_width(self::encode($text), $font, $size);
    }

    private static function escape($bytes) {
        return strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']);
    }

    /**
     * Draw one line of text. $y is the baseline, measured from the top.
     * $align: L, C or R relative to $x (C/R use $x as centre/right edge).
     */
    public function text($x, $y, $text, $font = 'F1', $size = 11, $color = '#000000', $align = 'L', $tracking = 0) {
        $bytes = self::encode($text);
        if ($bytes === '') return $this;
        $w = self::encoded_width($bytes, $font, $size) + $tracking * max(0, strlen($bytes) - 1);
        if ($align === 'C') $x -= $w / 2;
        if ($align === 'R') $x -= $w;
        $this->out('BT ' . self::color_ops($color) . ' /' . $font . ' ' . self::n($size) . ' Tf '
                 // Character spacing is part of the text state and outlives
                 // BT/ET, so always set it or one tracked label spaces out
                 // every line after it.
                 . self::n($tracking) . ' Tc '
                 . self::n($x) . ' ' . self::n($this->y($y)) . ' Td (' . self::escape($bytes) . ') Tj ET');
        return $this;
    }

    /** Break text into lines that fit $width. */
    public static function wrap($text, $font, $size, $width) {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $para) {
            $words = preg_split('/\s+/', trim($para));
            $line = '';
            foreach ($words as $word) {
                if ($word === '') continue;
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && self::text_width($try, $font, $size) > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
                // A single word wider than the box is cut rather than overflowing.
                while (self::text_width($line, $font, $size) > $width && mb_strlen($line) > 1) {
                    $cut = mb_strlen($line) - 1;
                    while ($cut > 1 && self::text_width(mb_substr($line, 0, $cut), $font, $size) > $width) $cut--;
                    $lines[] = mb_substr($line, 0, $cut);
                    $line = mb_substr($line, $cut);
                }
            }
            $lines[] = $line;
        }
        while ($lines && end($lines) === '') array_pop($lines);
        return $lines;
    }

    /** Shorten to fit $width, adding an ellipsis. */
    public static function fit($text, $font, $size, $width) {
        $text = (string)$text;
        if (self::text_width($text, $font, $size) <= $width) return $text;
        while (mb_strlen($text) > 1 && self::text_width($text . '...', $font, $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '...';
    }

    /**
     * @return float y of the line after the paragraph
     */
    public function paragraph($x, $y, $width, $text, $font = 'F1', $size = 10, $color = '#000000', $leading = 1.45, $max_lines = 0) {
        $lines = self::wrap($text, $font, $size, $width);
        if ($max_lines && count($lines) > $max_lines) {
            $lines = array_slice($lines, 0, $max_lines);
            $lines[$max_lines - 1] = self::fit($lines[$max_lines - 1] . ' …', $font, $size, $width);
        }
        foreach ($lines as $line) {
            $this->text($x, $y, $line, $font, $size, $color);
            $y += $size * $leading;
        }
        return $y;
    }

    /* ------------------------------------------------------------------
     * Output
     * ---------------------------------------------------------------- */

    public function output($title = '') {
        if ($this->page < 0) $this->add_page();

        $objects = [];
        $add = function ($body) use (&$objects) { $objects[] = $body; return count($objects); };

        $catalog_id = $add(null);
        $pages_id   = $add(null);

        // Shared encoding dictionary.
        $diff = '';
        foreach (explode(' ', self::LATIN1) as $i => $g) $diff .= ' /' . $g;
        $extra = '';
        foreach (self::EXTRA_GLYPHS as $g) $extra .= ' /' . $g;
        $enc_id = $add('<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [128' . $extra . ' 160' . $diff . '] >>');

        $font_ids = [];
        foreach (self::FONTS as $key => $f) {
            $enc = $f[0] === 'Courier-Bold' ? '/WinAnsiEncoding' : $enc_id . ' 0 R';
            $font_ids[$key] = $add('<< /Type /Font /Subtype /Type1 /BaseFont /' . $f[0] . ' /Encoding ' . $enc . ' >>');
        }

        $image_ids = [];
        foreach ($this->images as $name => $img) {
            $image_ids[$name] = $add([
                'dict'   => '<< /Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h']
                          . ' /ColorSpace /' . $img['cs'] . ' /BitsPerComponent 8 /Filter /DCTDecode'
                          . ($img['cs'] === 'DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '')
                          . ' /Length ' . strlen($img['data']) . ' >>',
                'stream' => $img['data'],
            ]);
        }

        $fonts = '';
        foreach ($font_ids as $k => $id) $fonts .= '/' . $k . ' ' . $id . ' 0 R ';
        $xobj = '';
        foreach ($image_ids as $k => $id) $xobj .= '/' . $k . ' ' . $id . ' 0 R ';
        $resources = '<< /ProcSet [/PDF /Text /ImageC] /Font << ' . $fonts . '>>' . ($xobj ? ' /XObject << ' . $xobj . '>>' : '') . ' >>';

        $kids = [];
        foreach ($this->pages as $content) {
            $data = $content;
            $filter = '';
            if (function_exists('gzcompress')) {
                $data = gzcompress($content, 6);
                $filter = ' /Filter /FlateDecode';
            }
            $content_id = $add(['dict' => '<< /Length ' . strlen($data) . $filter . ' >>', 'stream' => $data]);
            $kids[] = $add('<< /Type /Page /Parent ' . $pages_id . ' 0 R /MediaBox [0 0 ' . self::n($this->w) . ' ' . self::n($this->h) . ']'
                         . ' /Resources ' . $resources . ' /Contents ' . $content_id . ' 0 R >>');
        }

        $objects[$pages_id - 1]   = '<< /Type /Pages /Kids [' . implode(' ', array_map(function ($k) { return $k . ' 0 R'; }, $kids)) . '] /Count ' . count($kids) . ' >>';
        $objects[$catalog_id - 1] = '<< /Type /Catalog /Pages ' . $pages_id . ' 0 R >>';

        $info_id = $add('<< /Producer (SNN Tickets) /Title ' . self::pdf_string($title) . ' /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>');

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n";
            if (is_array($obj)) {
                $pdf .= $obj['dict'] . "\nstream\n" . $obj['stream'] . "\nendstream";
            } else {
                $pdf .= $obj;
            }
            $pdf .= "\nendobj\n";
        }

        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root {$catalog_id} 0 R /Info {$info_id} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /** A PDF text string, as UTF-16BE so titles keep every character. */
    private static function pdf_string($s) {
        $u = mb_convert_encoding((string)$s, 'UTF-16BE', 'UTF-8');
        return '<FEFF' . strtoupper(bin2hex($u)) . '>';
    }
}

/**
 * The printable ticket.
 */
class SNN_T_PDF {

    /**
     * @param array $t ticket data from SNN_T_Events::ticket_data()
     * @return string|WP_Error PDF bytes
     */
    public static function ticket($t) {
        try {
            require_once dirname(__DIR__) . '/qrcode.php';
            $matrix = [];
            if ($t['scan_url'] !== '') {
                $qr = new SNN_QRCode($t['scan_url'], ['errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_M]);
                $matrix = $qr->getMatrix();
            }
            return self::render($t, $matrix);
        } catch (Throwable $e) {
            return class_exists('WP_Error')
                ? new WP_Error('snn_pdf', 'PDF generation failed: ' . $e->getMessage())
                : 'PDF generation failed: ' . $e->getMessage();
        }
    }

    public static function render($t, $matrix) {
        $d   = $t['design'];
        $pdf = new SNN_T_PDF_Doc();
        $pdf->add_page();

        $serif = $d['font'] === 'serif';
        $F  = $serif ? 'F3' : 'F1';
        $FB = $serif ? 'F4' : 'F2';

        $W  = $pdf->width();
        $M  = 40;
        $cw = $W - 2 * $M;               // card width
        $x0 = $M;
        $y0 = 48;
        $r  = min(18, (int)$d['radius']);
        $stub = $d['layout'] === 'stub';

        $head_h = 96;
        $body_h = 250;
        $ch     = $head_h + $body_h;     // card height

        // Page background band behind the card, so a printed ticket keeps
        // the design's mood without flooding the page with ink.
        $pdf->fill($d['bg'])->rect(0, 0, $W, $y0 + $ch + 40);

        // Card, with everything painted inside it clipped to its corners.
        $pdf->fill($d['card'])->rounded_rect($x0, $y0, $cw, $ch, $r);
        $pdf->clip_rounded($x0, $y0, $cw, $ch, $r);

        if ($d['header_bg2']) {
            $pdf->gradient_rect($x0, $y0, $cw, $head_h, $d['header_bg'], $d['header_bg2']);
        } else {
            $pdf->fill($d['header_bg'])->rect($x0, $y0, $cw, $head_h);
        }
        if ($d['header_bg'] === $d['card']) {
            $pdf->stroke(SNN_T_Design::mix($d['card'], $d['text'], 0.12))->line_width(1)->line($x0, $y0 + $head_h, $x0 + $cw, $y0 + $head_h);
        }

        $by     = $y0 + $head_h;
        $stub_w = 200;
        $split  = $x0 + $cw - $stub_w;
        if ($stub) {
            $pdf->fill(SNN_T_Design::mix($d['card'], $d['text'], 0.04))->rect($split, $by, $stub_w, $body_h);
        }
        $pdf->restore();

        $tx = $x0 + 28;
        $logo_drawn = false;
        if (!empty($d['logo_url'])) {
            $logo = self::fetch_logo($d['logo_url']);
            if ($logo) $logo_drawn = $pdf->image($logo, $W - $M - 28 - 110, $y0 + 22, 0, 30);
        }

        $pdf->text($tx, $y0 + 30, strtoupper(__('Admit one', 'snn-tickets')), $F, 8.5, $d['header_text'], 'L', 1.6);
        $title_w = $cw - 56 - ($logo_drawn ? 130 : 0);
        $title = SNN_T_PDF_Doc::fit($t['event'] !== '' ? $t['event'] : get_bloginfo('name'), $FB, 24, $title_w);
        $pdf->text($tx, $y0 + 60, $title, $FB, 24, $d['header_text']);
        if ($t['when'] !== '') {
            $pdf->text($tx, $y0 + 80, SNN_T_PDF_Doc::fit($t['when'], $F, 11, $title_w), $F, 11, $d['header_text']);
        }

        if ($stub) {
            // Perforation with notches.
            $pdf->stroke(SNN_T_Design::mix($d['card'], $d['text'], 0.3))->line_width(1.2)->dash(4, 4)
                ->line($split, $by + 12, $split, $by + $body_h - 12)->dash();
            $pdf->fill($d['bg'])->circle($split, $by, 10)->circle($split, $y0 + $ch, 10);
        }

        $col_w = $split - $tx - 24;
        $y = $by + 34;
        $label = function ($text, $yy) use ($pdf, $tx, $F, $d) {
            $pdf->text($tx, $yy, strtoupper($text), $F, 8, $d['muted'], 'L', 1.2);
        };

        $label(__('Attendee', 'snn-tickets'), $y);
        $pdf->text($tx, $y + 20, SNN_T_PDF_Doc::fit($t['name'] !== '' ? $t['name'] : __('Guest', 'snn-tickets'), $FB, 17, $col_w), $FB, 17, $d['text']);
        $y += 50;

        if ($t['date'] !== '') {
            $half = ($col_w - 12) / 2;
            $label(__('Date', 'snn-tickets'), $y);
            $pdf->text($tx, $y + 17, SNN_T_PDF_Doc::fit($t['date'], $FB, 12, $half), $FB, 12, $d['text']);
            $label_x = $tx + $half + 12;
            $pdf->text($label_x, $y, strtoupper(__('Time', 'snn-tickets')), $F, 8, $d['muted'], 'L', 1.2);
            $pdf->text($label_x, $y + 17, SNN_T_PDF_Doc::fit($t['time'], $FB, 12, $half), $FB, 12, $d['text']);
            $y += 44;
        }

        if ($t['venue'] !== '' || $t['address'] !== '') {
            $label(__('Venue', 'snn-tickets'), $y);
            $yy = $y + 17;
            if ($t['venue'] !== '') {
                $pdf->text($tx, $yy, SNN_T_PDF_Doc::fit($t['venue'], $FB, 12, $col_w), $FB, 12, $d['text']);
                $yy += 16;
            }
            if ($t['address'] !== '') {
                $pdf->paragraph($tx, $yy, $col_w, $t['address'], $F, 10, $d['muted'], 1.35, 2);
            }
            $y += 58;
        }

        $label(__('Ticket code', 'snn-tickets'), min($y, $by + $body_h - 40));
        $pdf->text($tx, min($y, $by + $body_h - 40) + 18, $t['code'], 'F5', 13, $d['text'], 'L', 1.5);

        // QR
        $qr_size = 150;
        $qx = $stub ? $split + ($stub_w - $qr_size) / 2 : $x0 + $cw - 28 - $qr_size;
        $qy = $by + ($body_h - $qr_size) / 2 - 10;
        $pdf->fill('#ffffff')->rounded_rect($qx - 8, $qy - 8, $qr_size + 16, $qr_size + 16, 6);
        if ($matrix) $pdf->qr($matrix, $qx, $qy, $qr_size, '#000000');
        $pdf->text($qx + $qr_size / 2, $qy + $qr_size + 26, __('Scan at the entrance', 'snn-tickets'), $F, 8.5, $d['muted'], 'C');

        if (($t['status'] ?? 'active') === 'revoked') {
            $pdf->stroke('#b3261e')->line_width(3)->rounded_rect($x0 + 120, $by + 90, 260, 56, 6, 'S');
            $pdf->text($x0 + 250, $by + 128, strtoupper(__('Revoked', 'snn-tickets')), 'F2', 28, '#b3261e', 'C', 3);
        }

        // Below the card: notes, then the fine print.
        $y = $y0 + $ch + 76;
        $inner = $W - 2 * $M;
        $pdf->text($M, $y, __('Good to know', 'snn-tickets'), $FB, 13, '#111111');
        $y += 20;
        $notes = $t['description'] !== ''
            ? $t['description']
            : __('Show this ticket on your phone or printed at the entrance. The QR code can only be used once.', 'snn-tickets');
        $y = $pdf->paragraph($M, $y, $inner, $notes, $F, 10.5, '#333333', 1.5, 10);

        $y += 14;
        $meta = [];
        if ($t['organizer'] !== '') $meta[] = sprintf(__('Organised by %s', 'snn-tickets'), $t['organizer']);
        if ($t['email'] !== '')     $meta[] = sprintf(__('Issued to %s', 'snn-tickets'), $t['email']);
        foreach ($meta as $m) {
            $pdf->text($M, $y, $m, $F, 9.5, '#555555');
            $y += 14;
        }

        $pdf->stroke('#dddddd')->line_width(0.6)->line($M, 800, $W - $M, 800);
        $pdf->text($M, 815, get_bloginfo('name'), $F, 8.5, '#888888');
        $pdf->text($W - $M, 815, $t['code'], $F, 8.5, '#888888', 'R');

        return $pdf->output(($t['event'] !== '' ? $t['event'] . ' – ' : '') . $t['code']);
    }

    private static function fetch_logo($url) {
        // Prefer a local file when the logo lives in the media library.
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
        if ($uploads && strpos($url, $uploads['baseurl']) === 0) {
            $path = $uploads['basedir'] . substr($url, strlen($uploads['baseurl']));
            if (is_readable($path)) return file_get_contents($path);
        }
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, ['timeout' => 5]);
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                return wp_remote_retrieve_body($res);
            }
        }
        return '';
    }
}
