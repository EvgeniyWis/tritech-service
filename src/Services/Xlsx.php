<?php
declare(strict_types=1);

namespace App\Services;

/** Минимальный генератор XLSX (один лист, ZIP без сжатия) — без внешних зависимостей. */
final class Xlsx
{
    /**
     * @param string[]     $headers
     * @param array[]      $rows
     */
    public static function build(string $sheetName, array $headers, array $rows): string
    {
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26) . $s;
            }
            return $s;
        };
        $cell = function (int $c, int $r, $v, bool $head = false) use ($col): string {
            $ref = $col($c) . $r;
            if ($v === null || $v === '') {
                return '';
            }
            if (!$head && (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $v) && !preg_match('/^0\d/', $v)))) {
                return '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            }
            $t = htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            return '<c r="' . $ref . '" t="inlineStr"' . ($head ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $t . '</t></is></c>';
        };
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';
        foreach ($headers as $i => $h) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(12, min(50, mb_strlen((string)$h) + 6)) . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData><row r="1">';
        foreach ($headers as $i => $h) {
            $xml .= $cell($i, 1, $h, true);
        }
        $xml .= '</row>';
        foreach ($rows as $ri => $row) {
            $xml .= '<row r="' . ($ri + 2) . '">';
            foreach (array_values($row) as $ci => $v) {
                $xml .= $cell($ci, $ri + 2, $v);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        $name = htmlspecialchars(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $sheetName), 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => $xml,
        ];
        return self::zip($files);
    }

    private static function zip(array $files): string
    {
        $out = '';
        $central = '';
        $n = 0;
        [$dt, $dd] = [0, 0x21]; // 1980-01-01
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $len = strlen($data);
            $offset = strlen($out);
            $hdr = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dt, $dd, $crc, $len, $len, strlen($name), 0) . $name;
            $out .= $hdr . $data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dt, $dd, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $n++;
        }
        $cdOffset = strlen($out);
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), $cdOffset, 0);
    }
}
