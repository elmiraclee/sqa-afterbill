<?php
/**
 * AfterBill - Styled Excel (.xlsx) export, written directly with ZipArchive (no library).
 *
 * One sheet in the AfterBill look: title + subtitle, navy header row with white text, peso
 * amounts and real dates (sortable / summable in Excel), coloured status pills, striped rows,
 * a totals row, frozen header and filter buttons.
 *
 * Text is always written as a string cell, so a bill named "=HYPERLINK(...)" is shown as text and
 * never run as a formula (no CSV-style escaping needed).
 */

// Column types => style ids (plain row, striped row). See xlsxStyles() for the definitions.
const XLSX_STYLE = [
    'text'   => [4, 5],
    'strong' => [16, 17],
    'money'  => [6, 7],
    'date'   => [8, 9],
    'number' => [18, 19],
];
// Status text (lower case) => style id
const XLSX_STATUS_STYLE = [
    'paid'           => 10,
    'on time'        => 10,
    'unpaid'         => 11,
    'overdue'        => 12,
    'late'           => 12,
    'partially paid' => 13,
];

function xlsxEsc(string $s): string
{
    // Characters XML 1.0 can't contain are dropped; the rest is escaped.
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsxCol(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** Excel's date number for a Y-m-d string (days since 1899-12-30), or null. */
function xlsxDate(?string $ymd): ?int
{
    if ($ymd === null || !preg_match('/^\d{4}-\d{2}-\d{2}/', $ymd)) {
        return null;
    }
    return (int)((new DateTime(substr($ymd, 0, 10) . ' UTC'))->getTimestamp() / 86400) + 25569;
}

function xlsxStyles(): string
{
    $navy = 'FF17233C'; $blue = 'FF3B82F6'; $muted = 'FF6B7280'; $line = 'FFE5E7EB'; $stripe = 'FFF8FAFC';
    $fonts = [
        '<font><sz val="10"/><color rgb="FF1F2937"/><name val="Calibri"/></font>',            // 0 body
        '<font><b/><sz val="18"/><color rgb="' . $navy . '"/><name val="Calibri"/></font>',  // 1 title
        '<font><sz val="10"/><color rgb="' . $muted . '"/><name val="Calibri"/></font>',      // 2 muted
        '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>',        // 3 header
        '<font><b/><sz val="10"/><color rgb="FF047857"/><name val="Calibri"/></font>',        // 4 green
        '<font><b/><sz val="10"/><color rgb="FFB45309"/><name val="Calibri"/></font>',        // 5 amber
        '<font><b/><sz val="10"/><color rgb="FFB91C1C"/><name val="Calibri"/></font>',        // 6 red
        '<font><b/><sz val="10"/><color rgb="FF1D4ED8"/><name val="Calibri"/></font>',        // 7 blue
        '<font><b/><sz val="10"/><color rgb="' . $navy . '"/><name val="Calibri"/></font>',  // 8 bold navy
    ];
    $fill = fn($rgb) => '<fill><patternFill patternType="solid"><fgColor rgb="' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
    $fills = [
        '<fill><patternFill patternType="none"/></fill>',     // 0 (required)
        '<fill><patternFill patternType="gray125"/></fill>',  // 1 (required)
        $fill($navy),       // 2 header
        $fill($stripe),     // 3 stripe
        $fill('FFECFDF5'),  // 4 green
        $fill('FFFFFBEB'),  // 5 amber
        $fill('FFFEF2F2'),  // 6 red
        $fill('FFEFF6FF'),  // 7 blue / totals
    ];
    $borders = [
        '<border><left/><right/><top/><bottom/><diagonal/></border>',
        '<border><left/><right/><top/><bottom style="thin"><color rgb="' . $line . '"/></bottom><diagonal/></border>',
        '<border><left/><right/><top style="medium"><color rgb="' . $blue . '"/></top><bottom style="medium"><color rgb="' . $blue . '"/></bottom><diagonal/></border>',
        '<border><left/><right/><top/><bottom style="medium"><color rgb="' . $blue . '"/></bottom><diagonal/></border>',
    ];
    // numFmt 164 = pesos, 165 = dates
    $numFmts = '<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/>'
        . '<numFmt numFmtId="165" formatCode="mmm d, yyyy"/></numFmts>';
    $xf = function (int $font, int $fill, int $border, int $numFmt = 0, string $h = 'left') {
        return '<xf numFmtId="' . $numFmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
            . ($numFmt ? ' applyNumberFormat="1"' : '') . ' applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="' . $h . '" vertical="center"/></xf>';
    };
    $xfs = [
        $xf(0, 0, 0),             // 0 default
        $xf(1, 0, 0),             // 1 title
        $xf(2, 0, 0),             // 2 subtitle
        $xf(3, 2, 3, 0, 'left'),  // 3 header
        $xf(0, 0, 1),             // 4 text
        $xf(0, 3, 1),             // 5 text, striped
        $xf(0, 0, 1, 164, 'right'), // 6 money
        $xf(0, 3, 1, 164, 'right'), // 7 money, striped
        $xf(0, 0, 1, 165),        // 8 date
        $xf(0, 3, 1, 165),        // 9 date, striped
        $xf(4, 4, 1, 0, 'center'), // 10 status green
        $xf(5, 5, 1, 0, 'center'), // 11 status amber
        $xf(6, 6, 1, 0, 'center'), // 12 status red
        $xf(7, 7, 1, 0, 'center'), // 13 status blue
        $xf(8, 7, 2),             // 14 totals label
        $xf(8, 7, 2, 164, 'right'), // 15 totals money
        $xf(8, 0, 1),             // 16 strong text
        $xf(8, 3, 1),             // 17 strong text, striped
        $xf(0, 0, 1, 0, 'right'), // 18 number
        $xf(0, 3, 1, 0, 'right'), // 19 number, striped
        $xf(8, 7, 2, 0, 'right'), // 20 totals number
        $xf(3, 2, 3, 0, 'right'), // 21 header, right-aligned (over money / number columns)
    ];
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $numFmts
        . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
        . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
        . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * Builds the .xlsx and returns its path (a temp file the caller sends and deletes).
 *
 * $columns: list of ['label' => string, 'type' => text|strong|money|date|number|status, 'width' => float]
 * $rows:    list of value lists in column order (dates as 'Y-m-d', money/number as numbers, null = empty)
 * $totals:  optional value list for a totals row (label in the first column), or null
 */
function xlsxBuild(string $sheetName, string $title, string $subtitle, array $columns, array $rows, ?array $totals = null): string
{
    $n = count($columns);
    $last = xlsxCol($n - 1);
    $headerRow = 4;

    $cell = function (int $col, int $row, $value, string $type, int $style): string {
        $ref = xlsxCol($col) . $row;
        if ($value === null || $value === '') {
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }
        if ($type === 'date' && ($d = xlsxDate((string)$value)) !== null) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . $d . '</v></c>';
        }
        if (($type === 'money' || $type === 'number') && is_numeric($value)) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . (0 + $value) . '</v></c>';
        }
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEsc((string)$value) . '</t></is></c>';
    };

    $xml = [];
    $xml[] = '<row r="1" ht="30" customHeight="1">' . $cell(0, 1, $title, 'text', 1) . '</row>';
    $xml[] = '<row r="2" ht="16" customHeight="1">' . $cell(0, 2, $subtitle, 'text', 2) . '</row>';
    $hdr = '';
    foreach ($columns as $i => $c) {
        $hdr .= $cell($i, $headerRow, strtoupper($c['label']), 'text', in_array($c['type'], ['money', 'number'], true) ? 21 : 3);
    }
    $xml[] = '<row r="' . $headerRow . '" ht="24" customHeight="1">' . $hdr . '</row>';

    $r = $headerRow;
    foreach ($rows as $k => $values) {
        $r++;
        $striped = $k % 2 === 1 ? 1 : 0;
        $line = '';
        foreach ($columns as $i => $c) {
            $v = $values[$i] ?? null;
            if ($c['type'] === 'status') {
                $style = XLSX_STATUS_STYLE[strtolower((string)$v)] ?? XLSX_STYLE['text'][$striped];
                $line .= $cell($i, $r, $v, 'text', $style);
            } else {
                $line .= $cell($i, $r, $v, $c['type'], XLSX_STYLE[$c['type']][$striped]);
            }
        }
        $xml[] = '<row r="' . $r . '" ht="20" customHeight="1">' . $line . '</row>';
    }
    $lastDataRow = $r;

    if ($totals !== null) {
        $r++;
        $line = '';
        foreach ($columns as $i => $c) {
            $v = $totals[$i] ?? null;
            $style = $c['type'] === 'money' ? 15 : ($c['type'] === 'number' ? 20 : 14);
            $line .= $cell($i, $r, $v, $i === 0 ? 'text' : $c['type'], $style);
        }
        $xml[] = '<row r="' . $r . '" ht="22" customHeight="1">' . $line . '</row>';
    }

    $cols = '';
    foreach ($columns as $i => $c) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($c['width'] ?? 14) . '" customWidth="1"/>';
    }
    $filterRef = 'A' . $headerRow . ':' . $last . max($headerRow, $lastDataRow);

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:' . $last . $r . '"/>'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
        . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A' . ($headerRow + 1) . '" sqref="A' . ($headerRow + 1) . '"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . implode('', $xml) . '</sheetData>'
        . ($rows ? '<autoFilter ref="' . $filterRef . '"/>' : '')
        . '<mergeCells count="2"><mergeCell ref="A1:' . $last . '1"/><mergeCell ref="A2:' . $last . '2"/></mergeCells>'
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
        . '<headerFooter><oddFooter>&amp;L&amp;8AfterBill&amp;R&amp;8Page &amp;P of &amp;N</oddFooter></headerFooter>'
        . '</worksheet>';

    $sheetName = mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $sheetName), 0, 31);
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsxEsc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . ($rows ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''
            . xlsxEsc(str_replace("'", "''", $sheetName)) . '\'!$A$' . $headerRow . ':$' . $last . '$' . $lastDataRow . '</definedName>'
            . '<definedName name="_xlnm.Print_Titles" localSheetId="0">\'' . xlsxEsc(str_replace("'", "''", $sheetName)) . '\'!$' . $headerRow . ':$' . $headerRow . '</definedName></definedNames>' : '')
        . '</workbook>';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . xlsxEsc($title) . '</dc:title><dc:creator>AfterBill</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created></cp:coreProperties>',
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => xlsxStyles(),
        'xl/worksheets/sheet1.xml' => $sheet,
    ];

    $path = tempnam(sys_get_temp_dir(), 'afterbill-xlsx-');
    $zip = new ZipArchive();
    if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create the Excel file');
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    if (!$zip->close()) {
        @unlink($path);
        throw new RuntimeException('Cannot write the Excel file');
    }
    return $path;
}

/** Builds the file and sends it as a download, then exits. */
function xlsxDownload(string $filename, string $sheetName, string $title, string $subtitle, array $columns, array $rows, ?array $totals = null): void
{
    $path = xlsxBuild($sheetName, $title, $subtitle, $columns, $rows, $totals);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    @unlink($path);
    exit;
}
