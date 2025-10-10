<?php
/**
 * QR Code Generator for PHP 8.0+
 * Uses only built-in PHP GD library
 * No external dependencies required
 */

class QRCode {
    const MODE_NUMBER = 1;
    const MODE_ALPHA_NUM = 2;
    const MODE_8BIT_BYTE = 4;
    const MODE_KANJI = 8;
    
    const ERROR_CORRECT_L = 1;
    const ERROR_CORRECT_M = 0;
    const ERROR_CORRECT_Q = 3;
    const ERROR_CORRECT_H = 2;
    
    private $typeNumber;
    private $errorCorrectLevel;
    private $modules;
    private $moduleCount;
    private $dataCache;
    private $dataList = [];
    
    private static $PATTERN_POSITION_TABLE = [
        [],
        [6, 18],
        [6, 22],
        [6, 26],
        [6, 30],
        [6, 34],
        [6, 22, 38],
        [6, 24, 42],
        [6, 26, 46],
        [6, 28, 50],
        [6, 30, 54],
        [6, 32, 58],
        [6, 34, 62],
        [6, 26, 46, 66],
        [6, 26, 48, 70],
        [6, 26, 50, 74],
        [6, 30, 54, 78],
        [6, 30, 56, 82],
        [6, 30, 58, 86],
        [6, 34, 62, 90],
        [6, 28, 50, 72, 94],
        [6, 26, 50, 74, 98],
        [6, 30, 54, 78, 102],
        [6, 28, 54, 80, 106],
        [6, 32, 58, 84, 110],
        [6, 30, 58, 86, 114],
        [6, 34, 62, 90, 118],
        [6, 26, 50, 74, 98, 122],
        [6, 30, 54, 78, 102, 126],
        [6, 26, 52, 78, 104, 130],
        [6, 30, 56, 82, 108, 134],
        [6, 34, 60, 86, 112, 138],
        [6, 30, 58, 86, 114, 142],
        [6, 34, 62, 90, 118, 146],
        [6, 30, 54, 78, 102, 126, 150],
        [6, 24, 50, 76, 102, 128, 154],
        [6, 28, 54, 80, 106, 132, 158],
        [6, 32, 58, 84, 110, 136, 162],
        [6, 26, 54, 82, 110, 138, 166],
        [6, 30, 58, 86, 114, 142, 170]
    ];
    
    private static $EXP_TABLE = [];
    private static $LOG_TABLE = [];
    private static $initialized = false;
    
    public function __construct($text, $options = []) {
        $this->typeNumber = $options['typeNumber'] ?? 4;
        $this->errorCorrectLevel = $options['errorCorrectLevel'] ?? self::ERROR_CORRECT_H;
        
        if (!self::$initialized) {
            self::initTables();
        }
        
        $this->addData($text);
        $this->make();
    }
    
    private static function initTables() {
        for ($i = 0; $i < 8; $i++) {
            self::$EXP_TABLE[$i] = 1 << $i;
        }
        for ($i = 8; $i < 256; $i++) {
            self::$EXP_TABLE[$i] = self::$EXP_TABLE[$i - 4]
                ^ self::$EXP_TABLE[$i - 5]
                ^ self::$EXP_TABLE[$i - 6]
                ^ self::$EXP_TABLE[$i - 8];
        }
        for ($i = 0; $i < 255; $i++) {
            self::$LOG_TABLE[self::$EXP_TABLE[$i]] = $i;
        }
        self::$initialized = true;
    }
    
    private static function glog($n) {
        if ($n < 1) {
            throw new Exception("glog($n)");
        }
        return self::$LOG_TABLE[$n];
    }
    
    private static function gexp($n) {
        while ($n < 0) {
            $n += 255;
        }
        while ($n >= 256) {
            $n -= 255;
        }
        return self::$EXP_TABLE[$n];
    }
    
    public function addData($data) {
        $this->dataList[] = new QR8bitByte($data);
        $this->dataCache = null;
    }
    
    public function isDark($row, $col) {
        if ($row < 0 || $this->moduleCount <= $row || $col < 0 || $this->moduleCount <= $col) {
            throw new Exception("$row,$col");
        }
        return $this->modules[$row][$col];
    }
    
    public function getModuleCount() {
        return $this->moduleCount;
    }
    
    public function make() {
        $this->makeImpl(false, $this->getBestMaskPattern());
    }
    
    private function makeImpl($test, $maskPattern) {
        $this->moduleCount = $this->typeNumber * 4 + 17;
        $this->modules = array_fill(0, $this->moduleCount, array_fill(0, $this->moduleCount, null));
        
        $this->setupPositionProbePattern(0, 0);
        $this->setupPositionProbePattern($this->moduleCount - 7, 0);
        $this->setupPositionProbePattern(0, $this->moduleCount - 7);
        $this->setupPositionAdjustPattern();
        $this->setupTimingPattern();
        $this->setupTypeInfo($test, $maskPattern);
        
        if ($this->typeNumber >= 7) {
            $this->setupTypeNumber($test);
        }
        
        if ($this->dataCache == null) {
            $this->dataCache = self::createData($this->typeNumber, $this->errorCorrectLevel, $this->dataList);
        }
        
        $this->mapData($this->dataCache, $maskPattern);
    }
    
    private function setupPositionProbePattern($row, $col) {
        for ($r = -1; $r <= 7; $r++) {
            if ($row + $r <= -1 || $this->moduleCount <= $row + $r) continue;
            
            for ($c = -1; $c <= 7; $c++) {
                if ($col + $c <= -1 || $this->moduleCount <= $col + $c) continue;
                
                if ((0 <= $r && $r <= 6 && ($c == 0 || $c == 6))
                    || (0 <= $c && $c <= 6 && ($r == 0 || $r == 6))
                    || (2 <= $r && $r <= 4 && 2 <= $c && $c <= 4)) {
                    $this->modules[$row + $r][$col + $c] = true;
                } else {
                    $this->modules[$row + $r][$col + $c] = false;
                }
            }
        }
    }
    
    private function getBestMaskPattern() {
        $minLostPoint = 0;
        $pattern = 0;
        
        for ($i = 0; $i < 8; $i++) {
            $this->makeImpl(true, $i);
            $lostPoint = $this->getLostPoint();
            
            if ($i == 0 || $minLostPoint > $lostPoint) {
                $minLostPoint = $lostPoint;
                $pattern = $i;
            }
        }
        
        return $pattern;
    }
    
    private function setupTimingPattern() {
        for ($r = 8; $r < $this->moduleCount - 8; $r++) {
            if ($this->modules[$r][6] !== null) continue;
            $this->modules[$r][6] = ($r % 2 == 0);
        }
        
        for ($c = 8; $c < $this->moduleCount - 8; $c++) {
            if ($this->modules[6][$c] !== null) continue;
            $this->modules[6][$c] = ($c % 2 == 0);
        }
    }
    
    private function setupPositionAdjustPattern() {
        $pos = self::$PATTERN_POSITION_TABLE[$this->typeNumber - 1];
        
        for ($i = 0; $i < count($pos); $i++) {
            for ($j = 0; $j < count($pos); $j++) {
                $row = $pos[$i];
                $col = $pos[$j];
                
                if ($this->modules[$row][$col] !== null) continue;
                
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        if ($r == -2 || $r == 2 || $c == -2 || $c == 2 || ($r == 0 && $c == 0)) {
                            $this->modules[$row + $r][$col + $c] = true;
                        } else {
                            $this->modules[$row + $r][$col + $c] = false;
                        }
                    }
                }
            }
        }
    }
    
    private function setupTypeNumber($test) {
        $bits = $this->getBCHTypeNumber($this->typeNumber);
        
        for ($i = 0; $i < 18; $i++) {
            $mod = (!$test && (($bits >> $i) & 1) == 1);
            $this->modules[floor($i / 3)][$i % 3 + $this->moduleCount - 8 - 3] = $mod;
        }
        
        for ($i = 0; $i < 18; $i++) {
            $mod = (!$test && (($bits >> $i) & 1) == 1);
            $this->modules[$i % 3 + $this->moduleCount - 8 - 3][floor($i / 3)] = $mod;
        }
    }
    
    private function setupTypeInfo($test, $maskPattern) {
        $data = ($this->errorCorrectLevel << 3) | $maskPattern;
        $bits = $this->getBCHTypeInfo($data);
        
        for ($i = 0; $i < 15; $i++) {
            $mod = (!$test && (($bits >> $i) & 1) == 1);
            
            if ($i < 6) {
                $this->modules[$i][8] = $mod;
            } else if ($i < 8) {
                $this->modules[$i + 1][8] = $mod;
            } else {
                $this->modules[$this->moduleCount - 15 + $i][8] = $mod;
            }
        }
        
        for ($i = 0; $i < 15; $i++) {
            $mod = (!$test && (($bits >> $i) & 1) == 1);
            
            if ($i < 8) {
                $this->modules[8][$this->moduleCount - $i - 1] = $mod;
            } else if ($i < 9) {
                $this->modules[8][15 - $i - 1 + 1] = $mod;
            } else {
                $this->modules[8][15 - $i - 1] = $mod;
            }
        }
        
        $this->modules[$this->moduleCount - 8][8] = (!$test);
    }
    
    private function mapData($data, $maskPattern) {
        $inc = -1;
        $row = $this->moduleCount - 1;
        $bitIndex = 7;
        $byteIndex = 0;
        
        for ($col = $this->moduleCount - 1; $col > 0; $col -= 2) {
            if ($col == 6) $col--;
            
            while (true) {
                for ($c = 0; $c < 2; $c++) {
                    if ($this->modules[$row][$col - $c] === null) {
                        $dark = false;
                        
                        if ($byteIndex < count($data)) {
                            $dark = ((($data[$byteIndex] >> $bitIndex) & 1) == 1);
                        }
                        
                        if ($this->getMask($maskPattern, $row, $col - $c)) {
                            $dark = !$dark;
                        }
                        
                        $this->modules[$row][$col - $c] = $dark;
                        $bitIndex--;
                        
                        if ($bitIndex == -1) {
                            $byteIndex++;
                            $bitIndex = 7;
                        }
                    }
                }
                
                $row += $inc;
                
                if ($row < 0 || $this->moduleCount <= $row) {
                    $row -= $inc;
                    $inc = -$inc;
                    break;
                }
            }
        }
    }
    
    private function getMask($maskPattern, $i, $j) {
        switch ($maskPattern) {
            case 0: return ($i + $j) % 2 == 0;
            case 1: return $i % 2 == 0;
            case 2: return $j % 3 == 0;
            case 3: return ($i + $j) % 3 == 0;
            case 4: return (floor($i / 2) + floor($j / 3)) % 2 == 0;
            case 5: return ($i * $j) % 2 + ($i * $j) % 3 == 0;
            case 6: return (($i * $j) % 2 + ($i * $j) % 3) % 2 == 0;
            case 7: return (($i * $j) % 3 + ($i + $j) % 2) % 2 == 0;
            default: throw new Exception("bad maskPattern:$maskPattern");
        }
    }
    
    private function getLostPoint() {
        $lostPoint = 0;
        
        // LEVEL1
        for ($row = 0; $row < $this->moduleCount; $row++) {
            for ($col = 0; $col < $this->moduleCount; $col++) {
                $sameCount = 0;
                $dark = $this->isDark($row, $col);
                
                for ($r = -1; $r <= 1; $r++) {
                    if ($row + $r < 0 || $this->moduleCount <= $row + $r) continue;
                    
                    for ($c = -1; $c <= 1; $c++) {
                        if ($col + $c < 0 || $this->moduleCount <= $col + $c) continue;
                        if ($r == 0 && $c == 0) continue;
                        
                        if ($dark == $this->isDark($row + $r, $col + $c)) {
                            $sameCount++;
                        }
                    }
                }
                
                if ($sameCount > 5) {
                    $lostPoint += (3 + $sameCount - 5);
                }
            }
        }
        
        // LEVEL2
        for ($row = 0; $row < $this->moduleCount - 1; $row++) {
            for ($col = 0; $col < $this->moduleCount - 1; $col++) {
                $count = 0;
                if ($this->isDark($row, $col)) $count++;
                if ($this->isDark($row + 1, $col)) $count++;
                if ($this->isDark($row, $col + 1)) $count++;
                if ($this->isDark($row + 1, $col + 1)) $count++;
                
                if ($count == 0 || $count == 4) {
                    $lostPoint += 3;
                }
            }
        }
        
        // LEVEL3
        for ($row = 0; $row < $this->moduleCount; $row++) {
            for ($col = 0; $col < $this->moduleCount - 6; $col++) {
                if ($this->isDark($row, $col)
                    && !$this->isDark($row, $col + 1)
                    && $this->isDark($row, $col + 2)
                    && $this->isDark($row, $col + 3)
                    && $this->isDark($row, $col + 4)
                    && !$this->isDark($row, $col + 5)
                    && $this->isDark($row, $col + 6)) {
                    $lostPoint += 40;
                }
            }
        }
        
        for ($col = 0; $col < $this->moduleCount; $col++) {
            for ($row = 0; $row < $this->moduleCount - 6; $row++) {
                if ($this->isDark($row, $col)
                    && !$this->isDark($row + 1, $col)
                    && $this->isDark($row + 2, $col)
                    && $this->isDark($row + 3, $col)
                    && $this->isDark($row + 4, $col)
                    && !$this->isDark($row + 5, $col)
                    && $this->isDark($row + 6, $col)) {
                    $lostPoint += 40;
                }
            }
        }
        
        // LEVEL4
        $darkCount = 0;
        for ($col = 0; $col < $this->moduleCount; $col++) {
            for ($row = 0; $row < $this->moduleCount; $row++) {
                if ($this->isDark($row, $col)) {
                    $darkCount++;
                }
            }
        }
        
        $ratio = abs(100 * $darkCount / $this->moduleCount / $this->moduleCount - 50) / 5;
        $lostPoint += $ratio * 10;
        
        return $lostPoint;
    }
    
    private function getBCHTypeInfo($data) {
        $d = $data << 10;
        while ($this->getBCHDigit($d) - $this->getBCHDigit(0x537) >= 0) {
            $d ^= (0x537 << ($this->getBCHDigit($d) - $this->getBCHDigit(0x537)));
        }
        return (($data << 10) | $d) ^ 0x5412;
    }
    
    private function getBCHTypeNumber($data) {
        $d = $data << 12;
        while ($this->getBCHDigit($d) - $this->getBCHDigit(0x1f25) >= 0) {
            $d ^= (0x1f25 << ($this->getBCHDigit($d) - $this->getBCHDigit(0x1f25)));
        }
        return ($data << 12) | $d;
    }
    
    private function getBCHDigit($data) {
        $digit = 0;
        while ($data != 0) {
            $digit++;
            $data >>= 1;
        }
        return $digit;
    }
    
    private static function createData($typeNumber, $errorCorrectLevel, $dataList) {
        $rsBlocks = QRRSBlock::getRSBlocks($typeNumber, $errorCorrectLevel);
        $buffer = new QRBitBuffer();
        
        for ($i = 0; $i < count($dataList); $i++) {
            $data = $dataList[$i];
            $buffer->put($data->mode, 4);
            $buffer->put($data->getLength(), self::getLengthInBits($data->mode, $typeNumber));
            $data->write($buffer);
        }
        
        $totalDataCount = 0;
        for ($i = 0; $i < count($rsBlocks); $i++) {
            $totalDataCount += $rsBlocks[$i]->dataCount;
        }
        
        if ($buffer->getLengthInBits() > $totalDataCount * 8) {
            throw new Exception("code length overflow. (" . $buffer->getLengthInBits() . ">" . ($totalDataCount * 8) . ")");
        }
        
        if ($buffer->getLengthInBits() + 4 <= $totalDataCount * 8) {
            $buffer->put(0, 4);
        }
        
        while ($buffer->getLengthInBits() % 8 != 0) {
            $buffer->putBit(false);
        }
        
        while (true) {
            if ($buffer->getLengthInBits() >= $totalDataCount * 8) break;
            $buffer->put(0xec, 8);
            
            if ($buffer->getLengthInBits() >= $totalDataCount * 8) break;
            $buffer->put(0x11, 8);
        }
        
        return self::createBytes($buffer, $rsBlocks);
    }
    
    private static function createBytes($buffer, $rsBlocks) {
        $offset = 0;
        $maxDcCount = 0;
        $maxEcCount = 0;
        $dcdata = [];
        $ecdata = [];
        
        for ($r = 0; $r < count($rsBlocks); $r++) {
            $dcCount = $rsBlocks[$r]->dataCount;
            $ecCount = $rsBlocks[$r]->totalCount - $dcCount;
            
            $maxDcCount = max($maxDcCount, $dcCount);
            $maxEcCount = max($maxEcCount, $ecCount);
            
            $dcdata[$r] = [];
            for ($i = 0; $i < $dcCount; $i++) {
                $dcdata[$r][$i] = 0xff & $buffer->buffer[$i + $offset];
            }
            $offset += $dcCount;
            
            $rsPoly = self::getErrorCorrectPolynomial($ecCount);
            $rawPoly = new QRPolynomial($dcdata[$r], $rsPoly->getLength() - 1);
            $modPoly = $rawPoly->mod($rsPoly);
            
            $ecdata[$r] = [];
            for ($i = 0; $i < $rsPoly->getLength() - 1; $i++) {
                $modIndex = $i + $modPoly->getLength() - ($rsPoly->getLength() - 1);
                $ecdata[$r][$i] = ($modIndex >= 0) ? $modPoly->get($modIndex) : 0;
            }
        }
        
        $totalCodeCount = 0;
        for ($i = 0; $i < count($rsBlocks); $i++) {
            $totalCodeCount += $rsBlocks[$i]->totalCount;
        }
        
        $data = [];
        $index = 0;
        
        for ($i = 0; $i < $maxDcCount; $i++) {
            for ($r = 0; $r < count($rsBlocks); $r++) {
                if ($i < count($dcdata[$r])) {
                    $data[$index++] = $dcdata[$r][$i];
                }
            }
        }
        
        for ($i = 0; $i < $maxEcCount; $i++) {
            for ($r = 0; $r < count($rsBlocks); $r++) {
                if ($i < count($ecdata[$r])) {
                    $data[$index++] = $ecdata[$r][$i];
                }
            }
        }
        
        return $data;
    }
    
    private static function getErrorCorrectPolynomial($errorCorrectLength) {
        $a = new QRPolynomial([1], 0);
        for ($i = 0; $i < $errorCorrectLength; $i++) {
            $a = $a->multiply(new QRPolynomial([1, self::gexp($i)], 0));
        }
        return $a;
    }
    
    private static function getLengthInBits($mode, $type) {
        if (1 <= $type && $type < 10) {
            switch($mode) {
                case self::MODE_NUMBER: return 10;
                case self::MODE_ALPHA_NUM: return 9;
                case self::MODE_8BIT_BYTE: return 8;
                case self::MODE_KANJI: return 8;
                default: throw new Exception("mode:$mode");
            }
        } else if ($type < 27) {
            switch($mode) {
                case self::MODE_NUMBER: return 12;
                case self::MODE_ALPHA_NUM: return 11;
                case self::MODE_8BIT_BYTE: return 16;
                case self::MODE_KANJI: return 10;
                default: throw new Exception("mode:$mode");
            }
        } else if ($type < 41) {
            switch($mode) {
                case self::MODE_NUMBER: return 14;
                case self::MODE_ALPHA_NUM: return 13;
                case self::MODE_8BIT_BYTE: return 16;
                case self::MODE_KANJI: return 12;
                default: throw new Exception("mode:$mode");
            }
        } else {
            throw new Exception("type:$type");
        }
    }
    
    public function outputImage($file = null, $size = 4, $margin = 4) {
        $imgSize = $this->moduleCount * $size + $margin * 2;
        $img = imagecreatetruecolor($imgSize, $imgSize);
        
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        
        imagefilledrectangle($img, 0, 0, $imgSize - 1, $imgSize - 1, $white);
        
        for ($r = 0; $r < $this->moduleCount; $r++) {
            for ($c = 0; $c < $this->moduleCount; $c++) {
                if ($this->isDark($r, $c)) {
                    imagefilledrectangle(
                        $img,
                        $c * $size + $margin,
                        $r * $size + $margin,
                        $c * $size + $size - 1 + $margin,
                        $r * $size + $size - 1 + $margin,
                        $black
                    );
                }
            }
        }
        
        if ($file === null) {
            header('Content-Type: image/png');
            imagepng($img);
        } else {
            imagepng($img, $file);
        }
        
        imagedestroy($img);
    }
}

class QR8bitByte {
    public $mode;
    public $data;
    public $parsedData = [];
    
    public function __construct($data) {
        $this->mode = QRCode::MODE_8BIT_BYTE;
        $this->data = $data;
        
        $this->parsedData = [];
        for ($i = 0; $i < strlen($data); $i++) {
            $this->parsedData[] = ord($data[$i]);
        }
    }
    
    public function getLength() {
        return count($this->parsedData);
    }
    
    public function write($buffer) {
        for ($i = 0; $i < count($this->parsedData); $i++) {
            $buffer->put($this->parsedData[$i], 8);
        }
    }
}

class QRBitBuffer {
    public $buffer = [];
    public $length = 0;
    
    public function get($index) {
        $bufIndex = floor($index / 8);
        return (($this->buffer[$bufIndex] >> (7 - $index % 8)) & 1) == 1;
    }
    
    public function put($num, $length) {
        for ($i = 0; $i < $length; $i++) {
            $this->putBit((($num >> ($length - $i - 1)) & 1) == 1);
        }
    }
    
    public function getLengthInBits() {
        return $this->length;
    }
    
    public function putBit($bit) {
        $bufIndex = floor($this->length / 8);
        if (count($this->buffer) <= $bufIndex) {
            $this->buffer[] = 0;
        }
        
        if ($bit) {
            $this->buffer[$bufIndex] |= (0x80 >> ($this->length % 8));
        }
        
        $this->length++;
    }
}

class QRPolynomial {
    public $num = [];
    
    public function __construct($num, $shift) {
        if (!is_array($num)) {
            throw new Exception('Invalid array');
        }
        
        $offset = 0;
        while ($offset < count($num) && $num[$offset] == 0) {
            $offset++;
        }
        
        $this->num = array_fill(0, count($num) - $offset + $shift, 0);
        for ($i = 0; $i < count($num) - $offset; $i++) {
            $this->num[$i] = $num[$i + $offset];
        }
    }
    
    public function get($index) {
        return $this->num[$index];
    }
    
    public function getLength() {
        return count($this->num);
    }
    
    public function multiply($e) {
        $num = array_fill(0, $this->getLength() + $e->getLength() - 1, 0);
        
        for ($i = 0; $i < $this->getLength(); $i++) {
            for ($j = 0; $j < $e->getLength(); $j++) {
                $num[$i + $j] ^= QRCode::gexp(QRCode::glog($this->get($i)) + QRCode::glog($e->get($j)));
            }
        }
        
        return new QRPolynomial($num, 0);
    }
    
    public function mod($e) {
        if ($this->getLength() - $e->getLength() < 0) {
            return $this;
        }
        
        $ratio = QRCode::glog($this->get(0)) - QRCode::glog($e->get(0));
        $num = [];
        for ($i = 0; $i < $this->getLength(); $i++) {
            $num[$i] = $this->get($i);
        }
        
        for ($i = 0; $i < $e->getLength(); $i++) {
            $num[$i] ^= QRCode::gexp(QRCode::glog($e->get($i)) + $ratio);
        }
        
        return (new QRPolynomial($num, 0))->mod($e);
    }
}

class QRRSBlock {
    public $totalCount;
    public $dataCount;
    
    public function __construct($totalCount, $dataCount) {
        $this->totalCount = $totalCount;
        $this->dataCount = $dataCount;
    }
    
    public static function getRSBlocks($typeNumber, $errorCorrectLevel) {
        $rsBlock = self::getRsBlockTable($typeNumber, $errorCorrectLevel);
        
        if ($rsBlock == null) {
            throw new Exception("bad rs block @ typeNumber:$typeNumber/errorCorrectLevel:$errorCorrectLevel");
        }
        
        $length = count($rsBlock) / 3;
        $list = [];
        
        for ($i = 0; $i < $length; $i++) {
            $count = $rsBlock[$i * 3 + 0];
            $totalCount = $rsBlock[$i * 3 + 1];
            $dataCount = $rsBlock[$i * 3 + 2];
            
            for ($j = 0; $j < $count; $j++) {
                $list[] = new QRRSBlock($totalCount, $dataCount);
            }
        }
        
        return $list;
    }
    
    private static function getRsBlockTable($typeNumber, $errorCorrectLevel) {
        switch($errorCorrectLevel) {
            case QRCode::ERROR_CORRECT_L:
                return self::$RS_BLOCK_TABLE[($typeNumber - 1) * 4 + 0];
            case QRCode::ERROR_CORRECT_M:
                return self::$RS_BLOCK_TABLE[($typeNumber - 1) * 4 + 1];
            case QRCode::ERROR_CORRECT_Q:
                return self::$RS_BLOCK_TABLE[($typeNumber - 1) * 4 + 2];
            case QRCode::ERROR_CORRECT_H:
                return self::$RS_BLOCK_TABLE[($typeNumber - 1) * 4 + 3];
            default:
                return null;
        }
    }
    
    private static $RS_BLOCK_TABLE = [
        [1, 26, 19],
        [1, 26, 16],
        [1, 26, 13],
        [1, 26, 9],
        [1, 44, 34],
        [1, 44, 28],
        [1, 44, 22],
        [1, 44, 16],
        [1, 70, 55],
        [1, 70, 44],
        [2, 35, 17],
        [2, 35, 13],
        [1, 100, 80],
        [2, 50, 32],
        [2, 50, 24],
        [4, 25, 9],
        [1, 134, 108],
        [2, 67, 43],
        [2, 33, 15, 2, 34, 16],
        [2, 33, 11, 2, 34, 12],
        [2, 86, 68],
        [4, 43, 27],
        [4, 43, 19],
        [4, 43, 15],
        [2, 98, 78],
        [4, 49, 31],
        [2, 32, 14, 4, 33, 15],
        [4, 39, 13, 1, 40, 14],
        [2, 121, 97],
        [2, 60, 38, 2, 61, 39],
        [4, 40, 18, 2, 41, 19],
        [4, 40, 14, 2, 41, 15],
        [2, 86, 68, 2, 87, 69],
        [4, 69, 43, 1, 70, 44],
        [6, 43, 19, 2, 44, 20],
        [6, 43, 15, 2, 44, 16],
        [4, 101, 81],
        [1, 80, 50, 4, 81, 51],
        [4, 50, 22, 4, 51, 23],
        [3, 36, 12, 8, 37, 13],
        [2, 116, 92, 2, 117, 93],
        [6, 58, 36, 2, 59, 37],
        [4, 46, 20, 6, 47, 21],
        [7, 42, 14, 4, 43, 15],
        [4, 133, 107],
        [8, 59, 37, 1, 60, 38],
        [8, 44, 20, 4, 45, 21],
        [12, 33, 11, 4, 34, 12],
        [3, 145, 115, 1, 146, 116],
        [4, 64, 40, 5, 65, 41],
        [11, 36, 16, 5, 37, 17],
        [11, 36, 12, 5, 37, 13],
        [5, 109, 87, 1, 110, 88],
        [5, 65, 41, 5, 66, 42],
        [5, 54, 24, 7, 55, 25],
        [11, 36, 12],
        [5, 122, 98, 1, 123, 99],
        [7, 73, 45, 3, 74, 46],
        [15, 43, 19, 2, 44, 20],
        [3, 45, 15, 13, 46, 16],
        [1, 135, 107, 5, 136, 108],
        [10, 74, 46, 1, 75, 47],
        [1, 50, 22, 15, 51, 23],
        [2, 42, 14, 17, 43, 15],
        [5, 150, 120, 1, 151, 121],
        [9, 69, 43, 4, 70, 44],
        [17, 50, 22, 1, 51, 23],
        [2, 42, 14, 19, 43, 15],
        [3, 141, 113, 4, 142, 114],
        [3, 70, 44, 11, 71, 45],
        [17, 47, 21, 4, 48, 22],
        [9, 39, 13, 16, 40, 14],
        [3, 135, 107, 5, 136, 108],
        [3, 67, 41, 13, 68, 42],
        [15, 54, 24, 5, 55, 25],
        [15, 43, 15, 10, 44, 16],
        [4, 144, 116, 4, 145, 117],
        [17, 68, 42],
        [17, 50, 22, 6, 51, 23],
        [19, 46, 16, 6, 47, 17],
        [2, 139, 111, 7, 140, 112],
        [17, 74, 46],
        [7, 54, 24, 16, 55, 25],
        [34, 37, 13],
        [4, 151, 121, 5, 152, 122],
        [4, 75, 47, 14, 76, 48],
        [11, 54, 24, 14, 55, 25],
        [16, 45, 15, 14, 46, 16],
        [6, 147, 117, 4, 148, 118],
        [6, 73, 45, 14, 74, 46],
        [11, 54, 24, 16, 55, 25],
        [30, 46, 16, 2, 47, 17],
        [8, 132, 106, 4, 133, 107],
        [8, 75, 47, 13, 76, 48],
        [7, 54, 24, 22, 55, 25],
        [22, 45, 15, 13, 46, 16],
        [10, 142, 114, 2, 143, 115],
        [19, 74, 46, 4, 75, 47],
        [28, 50, 22, 6, 51, 23],
        [33, 46, 16, 4, 47, 17],
        [8, 152, 122, 4, 153, 123],
        [22, 73, 45, 3, 74, 46],
        [8, 53, 23, 26, 54, 24],
        [12, 45, 15, 28, 46, 16],
        [3, 147, 117, 10, 148, 118],
        [3, 73, 45, 23, 74, 46],
        [4, 54, 24, 31, 55, 25],
        [11, 45, 15, 31, 46, 16],
        [7, 146, 116, 7, 147, 117],
        [21, 73, 45, 7, 74, 46],
        [1, 53, 23, 37, 54, 24],
        [19, 45, 15, 26, 46, 16],
        [5, 145, 115, 10, 146, 116],
        [19, 75, 47, 10, 76, 48],
        [15, 54, 24, 25, 55, 25],
        [23, 45, 15, 25, 46, 16],
        [13, 145, 115, 3, 146, 116],
        [2, 74, 46, 29, 75, 47],
        [42, 54, 24, 1, 55, 25],
        [23, 45, 15, 28, 46, 16],
        [17, 145, 115],
        [10, 74, 46, 23, 75, 47],
        [10, 54, 24, 35, 55, 25],
        [19, 45, 15, 35, 46, 16],
        [17, 145, 115, 1, 146, 116],
        [14, 74, 46, 21, 75, 47],
        [29, 54, 24, 19, 55, 25],
        [11, 45, 15, 46, 46, 16],
        [13, 145, 115, 6, 146, 116],
        [14, 74, 46, 23, 75, 47],
        [44, 54, 24, 7, 55, 25],
        [59, 46, 16, 1, 47, 17],
        [12, 151, 121, 7, 152, 122],
        [12, 75, 47, 26, 76, 48],
        [39, 54, 24, 14, 55, 25],
        [22, 45, 15, 41, 46, 16],
        [6, 151, 121, 14, 152, 122],
        [6, 75, 47, 34, 76, 48],
        [46, 54, 24, 10, 55, 25],
        [2, 45, 15, 64, 46, 16],
        [17, 152, 122, 4, 153, 123],
        [29, 74, 46, 14, 75, 47],
        [49, 54, 24, 10, 55, 25],
        [24, 45, 15, 46, 46, 16],
        [4, 152, 122, 18, 153, 123],
        [13, 74, 46, 32, 75, 47],
        [48, 54, 24, 14, 55, 25],
        [42, 45, 15, 32, 46, 16],
        [20, 147, 117, 4, 148, 118],
        [40, 75, 47, 7, 76, 48],
        [43, 54, 24, 22, 55, 25],
        [10, 45, 15, 67, 46, 16],
        [19, 148, 118, 6, 149, 119],
        [18, 75, 47, 31, 76, 48],
        [34, 54, 24, 34, 55, 25],
        [20, 45, 15, 61, 46, 16]
    ];
}

// Example usage:
// $qr = new QRCode('Hello World!', [
//     'typeNumber' => 4,
//     'errorCorrectLevel' => QRCode::ERROR_CORRECT_H
// ]);
// $qr->outputImage();  // Output to browser
// $qr->outputImage('qrcode.png', 4, 4);  // Save to file