<?php
declare(strict_types=1);

/**
 * XLSX part builders for the spreadsheet-with-photos export.
 *
 * A minimal but standard Office Open XML workbook: one worksheet with the
 * item data in columns A–H and every photo embedded as an anchored picture
 * in column I (stacked down the item's row). Excel, LibreOffice, Apple
 * Numbers and Google Sheets (drag the file into sheets.google.com) all
 * render the embedded photos — no Google API, service account or external
 * service is involved.
 *
 * These functions are pure string builders so they can be unit-tested
 * directly. The workbook is assembled by export_spreadsheet.php, which wires
 * them into the existing ZIP writers (ZipArchive when available, the pure-PHP
 * writer otherwise).
 *
 * Coordinate model (shared by sheet XML and the drawing XML):
 *   - rows: row 0 = header (sheet row 1), data row i = sheet row i+2
 *   - anchors: xdr:col/xdr:row are 0-based, so an image for data row i is
 *     anchored at xdr:row = i + 1 (just below the header)
 *   - sizes: EMU, 9525 EMU per CSS pixel
 */

const SHEET_PX_TO_EMU = 9525;

/**
 * Escape a string for XML text nodes (also usable in attributes).
 */
function sheetEscapeXml(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * [Content_Types].xml for a workbook with an optional drawing/media set.
 */
function sheetBuildContentTypes(bool $hasImages): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
    if ($hasImages) {
        $xml .= '<Default Extension="jpg" ContentType="image/jpeg"/>';
    }
    $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $xml .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    if ($hasImages) {
        $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }
    $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    $xml .= '</Types>';
    return $xml;
}

/**
 * _rels/.rels — package root relationships.
 */
function sheetBuildRootRels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

/**
 * xl/workbook.xml — single sheet named "Inventory".
 */
function sheetBuildWorkbook(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Inventory" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

/**
 * xl/_rels/workbook.xml.rels — worksheet, styles and theme.
 */
function sheetBuildWorkbookRels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
        . '</Relationships>';
}

/**
 * xl/styles.xml — minimal styles: default + a bold header style (xf 1).
 */
function sheetBuildStyles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>'
        . '<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font>'
        . '</fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * xl/theme/theme1.xml — the standard Office theme, needed by some readers.
 */
function sheetBuildTheme(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme">'
        . '<a:themeElements>'
        . '<a:clrScheme name="Office">'
        . '<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>'
        . '<a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
        . '<a:dk2><a:srgbClr val="1F497D"/></a:dk2>'
        . '<a:lt2><a:srgbClr val="EEECE1"/></a:lt2>'
        . '<a:accent1><a:srgbClr val="4F81BD"/></a:accent1>'
        . '<a:accent2><a:srgbClr val="C0504D"/></a:accent2>'
        . '<a:accent3><a:srgbClr val="9BBB59"/></a:accent3>'
        . '<a:accent4><a:srgbClr val="8064A2"/></a:accent4>'
        . '<a:accent5><a:srgbClr val="4BACC6"/></a:accent5>'
        . '<a:accent6><a:srgbClr val="F79646"/></a:accent6>'
        . '<a:hlink><a:srgbClr val="0000FF"/></a:hlink>'
        . '<a:folHlink><a:srgbClr val="800080"/></a:folHlink>'
        . '</a:clrScheme>'
        . '<a:fontScheme name="Office">'
        . '<a:majorFont><a:latin typeface="Cambria"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
        . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
        . '</a:fontScheme>'
        . '<a:fmtScheme name="Office">'
        . '<a:fillStyleLst>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0"><a:schemeClr val="phClr"><a:tint val="50000"/><a:satMod val="300000"/></a:schemeClr></a:gs><a:gs pos="35000"><a:schemeClr val="phClr"><a:tint val="37000"/><a:satMod val="300000"/></a:schemeClr></a:gs><a:gs pos="100000"><a:schemeClr val="phClr"><a:tint val="15000"/><a:satMod val="350000"/></a:schemeClr></a:gs></a:gsLst><a:lin ang="16200000" scaled="1"/></a:gradFill>'
        . '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0"><a:schemeClr val="phClr"><a:shade val="51000"/><a:satMod val="130000"/></a:schemeClr></a:gs><a:gs pos="80000"><a:schemeClr val="phClr"><a:shade val="93000"/><a:satMod val="130000"/></a:schemeClr></a:gs><a:gs pos="100000"><a:schemeClr val="phClr"><a:shade val="94000"/><a:satMod val="135000"/></a:schemeClr></a:gs></a:gsLst><a:lin ang="16200000" scaled="0"/></a:gradFill>'
        . '</a:fillStyleLst>'
        . '<a:lnStyleLst>'
        . '<a:ln w="9525" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"><a:shade val="95000"/><a:satMod val="105000"/></a:schemeClr></a:solidFill><a:prstDash val="solid"/></a:ln>'
        . '<a:ln w="25400" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>'
        . '<a:ln w="38100" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>'
        . '</a:lnStyleLst>'
        . '<a:effectStyleLst>'
        . '<a:effectStyle><a:effectLst/></a:effectStyle>'
        . '<a:effectStyle><a:effectLst/></a:effectStyle>'
        . '<a:effectStyle><a:effectLst/></a:effectStyle>'
        . '</a:effectStyleLst>'
        . '<a:bgFillStyleLst>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0"><a:schemeClr val="phClr"><a:tint val="40000"/><a:satMod val="350000"/></a:schemeClr></a:gs><a:gs pos="40000"><a:schemeClr val="phClr"><a:tint val="45000"/><a:shade val="99000"/><a:satMod val="350000"/></a:schemeClr></a:gs><a:gs pos="100000"><a:schemeClr val="phClr"><a:shade val="20000"/><a:satMod val="255000"/></a:schemeClr></a:gs></a:gsLst><a:path path="circle"><a:fillToRect l="50000" t="-80000" r="50000" b="180000"/></a:path></a:gradFill>'
        . '<a:gradFill rotWithShape="1"><a:gsLst><a:gs pos="0"><a:schemeClr val="phClr"><a:tint val="80000"/><a:satMod val="300000"/></a:schemeClr></a:gs><a:gs pos="100000"><a:schemeClr val="phClr"><a:shade val="30000"/><a:satMod val="200000"/></a:schemeClr></a:gs></a:gsLst><a:path path="circle"><a:fillToRect l="50000" t="50000" r="50000" b="50000"/></a:path></a:gradFill>'
        . '</a:bgFillStyleLst>'
        . '</a:fmtScheme>'
        . '</a:themeElements>'
        . '<a:objectDefaults/><a:extraClrSchemeLst/>'
        . '</a:theme>';
}

/**
 * docProps/core.xml
 */
function sheetBuildCoreProps(string $title = 'Pinksheet Inventory'): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
        . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . sheetEscapeXml($title) . '</dc:title>'
        . '<dc:creator>Pinksheet</dc:creator>'
        . '<cp:lastModifiedBy>Pinksheet</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

/**
 * docProps/app.xml
 */
function sheetBuildAppProps(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Pinksheet</Application>'
        . '<DocSecurity>0</DocSecurity>'
        . '<ScaleCrop>false</ScaleCrop>'
        . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
        . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Inventory</vt:lpstr></vt:vector></TitlesOfParts>'
        . '<Company>Dispo.Tech</Company>'
        . '<LinksUpToDate>false</LinksUpToDate>'
        . '<SharedDoc>false</SharedDoc>'
        . '<HyperlinksChanged>false</HyperlinksChanged>'
        . '<AppVersion>1.0</AppVersion>'
        . '</Properties>';
}

/**
 * xl/worksheets/sheet1.xml — the data grid plus a reference to the drawing
 * part when photos are embedded.
 *
 * @param array  $rows       Rows of cells; row 0 is the header. Cells may be
 *                           int|float (emitted as numbers), string (inline
 *                           string), or '' (cell omitted).
 * @param bool   $hasImages  Whether a drawing part exists and must be referenced.
 * @param array  $rowHeights Optional custom row heights, keyed by row index (0-based).
 * @param array  $colWidths  Column widths, keyed by 1-based column number.
 */
function sheetBuildSheetXml(array $rows, bool $hasImages, array $rowHeights = [], array $colWidths = []): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    // Freeze the header row so the boss can scroll thousands of rows and
    // still see what each column means.
    $xml .= '<sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
        . '</sheetView></sheetViews>';

    if ($colWidths) {
        $xml .= '<cols>';
        foreach ($colWidths as $col => $width) {
            $xml .= '<col min="' . (int)$col . '" max="' . (int)$col . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }

    $xml .= '<sheetData>';
    foreach ($rows as $i => $row) {
        $sheetRow = $i + 1;
        $ht = $rowHeights[$i] ?? null;
        $xml .= $ht !== null
            ? '<row r="' . $sheetRow . '" ht="' . $ht . '" customHeight="1">'
            : '<row r="' . $sheetRow . '">';

        foreach ($row as $col => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ref = chr(65 + (int)$col) . $sheetRow; // A..I (9 columns max)
            $bold = $sheetRow === 1 ? ' s="1"' : '';
            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $ref . '"' . $bold . '><v>' . $value . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . $bold . '><is><t>' . sheetEscapeXml((string)$value) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';

    if ($hasImages) {
        $xml .= '<drawing r:id="rId1"/>';
    }
    $xml .= '</worksheet>';
    return $xml;
}

/**
 * xl/worksheets/_rels/sheet1.xml.rels — worksheet → drawing relationship.
 */
function sheetBuildSheetRels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
        . '</Relationships>';
}

/**
 * xl/drawings/_rels/drawing1.xml.rels — drawing → media images.
 */
function sheetBuildDrawingRels(int $imageCount): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    for ($i = 1; $i <= $imageCount; $i++) {
        $xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $i . '.jpg"/>';
    }
    $xml .= '</Relationships>';
    return $xml;
}

/**
 * xl/drawings/drawing1.xml — one anchored picture per embedded photo,
 * stacked down column I of the item's row.
 *
 * @param array $images [ ['row' => dataRow0, 'yOffEmu' => int, 'wEmu' => int, 'hEmu' => int, 'rId' => int], ... ]
 */
function sheetBuildDrawing(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
        . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';

    foreach ($images as $img) {
        $id = (int)$img['rId'];
        $xml .= '<xdr:oneCellAnchor editAs="oneCell">';
        $xml .= '<xdr:from>'
            . '<xdr:col>8</xdr:col><xdr:colOff>0</xdr:colOff>'
            . '<xdr:row>' . (int)$img['row'] . '</xdr:row><xdr:rowOff>' . (int)$img['yOffEmu'] . '</xdr:rowOff>'
            . '</xdr:from>';
        $xml .= '<xdr:ext cx="' . (int)$img['wEmu'] . '" cy="' . (int)$img['hEmu'] . '"/>';
        $xml .= '<xdr:pic>';
        $xml .= '<xdr:nvPicPr>'
            . '<xdr:cNvPr id="' . $id . '" name="Photo ' . $id . '"/>'
            . '<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr>'
            . '</xdr:nvPicPr>';
        $xml .= '<xdr:blipFill>'
            . '<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId' . $id . '"/>'
            . '<a:stretch><a:fillRect/></a:stretch>'
            . '</xdr:blipFill>';
        $xml .= '<xdr:spPr>'
            . '<a:xfrm><a:off x="0" y="0"/><a:ext cx="' . (int)$img['wEmu'] . '" cy="' . (int)$img['hEmu'] . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            . '</xdr:spPr>';
        $xml .= '</xdr:pic>';
        $xml .= '<xdr:clientData/>';
        $xml .= '</xdr:oneCellAnchor>';
    }

    $xml .= '</xdr:wsDr>';
    return $xml;
}

/**
 * Produce a 320px JPEG thumbnail for a photo, reusing the same on-disk cache
 * that photo.php?thumb=1 writes (data/thumbs/{photoId}-{mtime}-t320.jpg) so
 * repeat exports cost nothing. Falls back to generating + caching the thumb
 * when it is missing.
 *
 * @return array{0:string,1:int,2:int}|null [path, widthPx, heightPx] or null on failure
 */
function sheetThumbnail(string $srcPath, string $thumbsDir, int $photoId, int $maxDim = 320): ?array
{
    $mtime = (int)@filemtime($srcPath);
    $thumbPath = rtrim($thumbsDir, '/\\') . '/' . $photoId . '-' . $mtime . '-t' . $maxDim . '.jpg';

    if (is_file($thumbPath)) {
        $dims = @getimagesize($thumbPath);
        if (is_array($dims) && ($dims[0] ?? 0) > 0 && ($dims[1] ?? 0) > 0) {
            return [$thumbPath, (int)$dims[0], (int)$dims[1]];
        }
        @unlink($thumbPath); // corrupt cache entry; regenerate
    }

    if (!function_exists('imagecreatefromstring')) {
        return null;
    }

    $src = @imagecreatefromstring((string)@file_get_contents($srcPath));
    if ($src === false) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= 0 || $h <= 0) {
        return null;
    }

    $scale = min(1.0, $maxDim / max($w, $h));
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($tw, $th);
    if ($dst === false) {
        return null;
    }
    // White background so transparent PNGs don't render black in Excel.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagejpeg($dst, $thumbPath, 82);

    if (!is_file($thumbPath)) {
        return null;
    }
    return [$thumbPath, $tw, $th];
}
