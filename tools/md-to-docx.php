<?php

declare(strict_types=1);

/**
 * md-to-docx.php — converts the MSS-CRM markdown documents into genuine Word (.docx) files.
 *
 * No external dependencies: the OOXML parts are generated directly and packaged with ZipArchive.
 * Requires PHP 8 with the zip extension (both present in the Laragon PHP 8.3 build).
 *
 * Usage:
 *   php tools/md-to-docx.php --in=..\docs\05-Test-Cases-Unit.md --out=..\docs\docx\05-Test-Cases-Unit.docx \
 *       --title="MSS-CRM — Unit Test Case List" [--subtitle="Version 1.0 · 2026-09-29"] [--author="MSS"]
 *
 * Repeat --in to build a combined pack (each input starts on a new page, listed on the cover).
 */

const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

/** Headings that are re-rendered on the cover page are dropped from the body. */
const SKIP_COVER_HEADER = true;

final class Args
{
    public array $in = [];
    public string $out = '';
    public string $title = 'MSS-CRM';
    public string $subtitle = '';
    public string $author = 'MSS-CRM';
    public bool $validate = true;

    public static function parse(array $argv): self
    {
        $a = new self();
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            [$key, $value] = array_pad(explode('=', $arg, 2), 2, '');
            switch ($key) {
                case 'in':       $a->in[] = $value; break;
                case 'out':      $a->out = $value; break;
                case 'title':    $a->title = $value; break;
                case 'subtitle': $a->subtitle = $value; break;
                case 'author':   $a->author = $value; break;
                case 'no-validate': $a->validate = false; break;
            }
        }
        return $a;
    }
}

function xml(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Glyphs that render inconsistently in Calibri are replaced with plain wordings. */
function plainify(string $text): string
{
    return strtr($text, [
        '✅' => 'OK',
        '⛔' => 'TO WRITE',
        '⚠' => 'PARTIAL',
        '→' => '->',
    ]);
}

final class DocxBuilder
{
    private string $body = '';

    public function __construct(
        private string $title,
        private string $subtitle,
        private string $author,
        private array $sources = []
    ) {
    }

    // ---------------------------------------------------------------- runs

    private function run(string $text, array $o = []): string
    {
        $rPr = '<w:rPr>';
        if (!empty($o['b'])) {
            $rPr .= '<w:b/><w:bCs/>';
        }
        if (!empty($o['i'])) {
            $rPr .= '<w:i/><w:iCs/>';
        }
        if (!empty($o['font'])) {
            $font = xml($o['font']);
            $rPr .= '<w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:cs="' . $font . '"/>';
        }
        if (!empty($o['color'])) {
            $rPr .= '<w:color w:val="' . xml($o['color']) . '"/>';
        }
        if (!empty($o['sz'])) {
            $rPr .= '<w:sz w:val="' . (int) $o['sz'] . '"/><w:szCs w:val="' . (int) $o['sz'] . '"/>';
        }
        $rPr .= '</w:rPr>';

        $t = xml(plainify($text));
        $space = (trim($text) !== $text) ? ' xml:space="preserve"' : '';

        return '<w:r>' . $rPr . '<w:t' . $space . '>' . $t . '</w:t></w:r>';
    }

    /** Inline markdown: **bold**, `code`, *italic*. */
    private function inline(string $text, array $base = []): string
    {
        $parts = preg_split('/(\*\*[^*]+\*\*|`[^`]+`|\*[^*]+\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, '**') && str_ends_with($part, '**') && strlen($part) > 4) {
                $out .= $this->run(substr($part, 2, -2), $base + ['b' => true]);
            } elseif (str_starts_with($part, '`') && str_ends_with($part, '`') && strlen($part) > 2) {
                $out .= $this->run(substr($part, 1, -1), $base + ['font' => 'Consolas', 'sz' => 18, 'color' => '1F3864']);
            } elseif (str_starts_with($part, '*') && str_ends_with($part, '*') && strlen($part) > 2) {
                $out .= $this->run(substr($part, 1, -1), $base + ['i' => true]);
            } else {
                $out .= $this->run($part, $base);
            }
        }
        return $out;
    }

    // ----------------------------------------------------------- paragraphs

    private function p(string $runs, string $style = '', string $pPrExtra = ''): string
    {
        $pPr = '<w:pPr>';
        if ($style !== '') {
            $pPr .= '<w:pStyle w:val="' . $style . '"/>';
        }
        $pPr .= $pPrExtra . '</w:pPr>';

        return '<w:p>' . $pPr . ($runs !== '' ? $runs : '') . '</w:p>';
    }

    private function heading(int $level, string $text): string
    {
        $style = 'Heading' . min($level, 4);
        return $this->p($this->inline($text), $style);
    }

    private function pageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    private function rule(): string
    {
        return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="A9B4D0"/></w:pBdr></w:pPr></w:p>';
    }

    // --------------------------------------------------------------- tables

    private function tableCell(string $text, int $widthPct, bool $header = false): string
    {
        $base = $header ? ['b' => true, 'color' => 'FFFFFF', 'sz' => 18] : ['sz' => 18];
        $fill = $header ? '2456E6' : 'FFFFFF';

        return '<w:tc><w:tcPr><w:tcW w:w="' . $widthPct . '" w:type="pct"/>'
            . '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>'
            . '<w:vAlign w:val="center"/></w:tcPr>'
            . '<w:p><w:pPr><w:pStyle w:val="TableText"/></w:pPr>' . $this->inline($text, $base) . '</w:p></w:tc>';
    }

    /** Column widths proportional to the average content length (percent, min 6, max 42). */
    private function columnWidths(array $rows, int $cols): array
    {
        $totals = array_fill(0, $cols, 0);
        foreach ($rows as $row) {
            for ($c = 0; $c < $cols; $c++) {
                $totals[$c] += mb_strlen((string) ($row[$c] ?? ''), 'UTF-8');
            }
        }
        $weights = [];
        foreach ($totals as $i => $total) {
            $avg = count($rows) > 0 ? $total / count($rows) : 1;
            $weights[$i] = max(6.0, min(42.0, $avg));
        }
        $sum = array_sum($weights) ?: 1;

        return array_map(static fn (float $w): float => round($w / $sum * 100, 2), $weights);
    }

    private function table(array $rows): string
    {
        $cols = 0;
        foreach ($rows as $row) {
            $cols = max($cols, count($row));
        }
        $widths = $this->columnWidths($rows, $cols);

        $xml = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/>'
            . '<w:tblW w:w="5000" w:type="pct"/>'
            . '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="8FA0C0"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="8FA0C0"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="8FA0C0"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="8FA0C0"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="C7D0E4"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="C7D0E4"/>'
            . '</w:tblBorders><w:tblLayout w:type="fixed"/>'
            . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="85" w:type="dxa"/>'
            . '<w:bottom w:w="60" w:type="dxa"/><w:right w:w="85" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';

        $gridTotal = 9600;
        foreach ($widths as $w) {
            $xml .= '<w:gridCol w:w="' . (int) round($gridTotal * $w / 100) . '"/>';
        }
        $xml .= '</w:tblGrid>';

        foreach ($rows as $index => $row) {
            $isHead = ($index === 0);
            $xml .= '<w:tr><w:trPr>' . ($isHead ? '<w:tblHeader/>' : '') . '<w:cantSplit/></w:trPr>';
            for ($c = 0; $c < $cols; $c++) {
                $xml .= $this->tableCell((string) ($row[$c] ?? ''), (int) round($widths[$c] * 50), $isHead);
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl>';
    }

    /** Splits a markdown table row on pipes that are outside inline code spans. */
    private function splitRow(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|')) {
            $line = substr($line, 0, -1);
        }

        $cells = [];
        $buffer = '';
        $inCode = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '`') {
                $inCode = !$inCode;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '|' && !$inCode) {
                $cells[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        $cells[] = trim($buffer);

        return $cells;
    }

    private function isSeparatorRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (!preg_match('/^:?-{2,}:?$/', $cell)) {
                return false;
            }
        }
        return $cells !== [];
    }

    // ------------------------------------------------------------ structure

    private function cover(): string
    {
        $x = $this->p(
            $this->run('MSS-CRM', ['sz' => 20, 'b' => true, 'color' => '7A86A8']),
            '',
            '<w:spacing w:before="1400" w:after="80"/>'
        );
        $x .= $this->p(
            $this->run($this->title, ['sz' => 44, 'b' => true, 'color' => '12306E']),
            '',
            '<w:spacing w:after="120"/>'
        );
        if ($this->subtitle !== '') {
            $x .= $this->p($this->inline($this->subtitle, ['sz' => 22, 'color' => '44506E']), '', '<w:spacing w:after="260"/>');
        }
        $x .= $this->rule();

        if ($this->sources !== []) {
            $label = count($this->sources) > 1 ? 'Documents in this pack' : 'Source document';
            $x .= $this->p($this->run($label, ['b' => true, 'sz' => 21]), '', '<w:spacing w:before="260" w:after="120"/>');
            foreach ($this->sources as $source) {
                $x .= $this->p($this->run('•  ' . $source, ['sz' => 20]), 'ListParagraph');
            }
        }

        $x .= $this->p(
            $this->run('Prepared by ' . $this->author . ' · generated from markdown by tools/md-to-docx.php', ['sz' => 18, 'color' => '7A86A8']),
            '',
            '<w:spacing w:before="520"/>'
        );

        return $x . $this->pageBreak();
    }

    /** Converts markdown into body XML. Headings, tables, lists, rules, code fences and inline runs. */
    public function markdownToBody(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        if (SKIP_COVER_HEADER) {
            while ($lines !== [] && trim($lines[0]) === '') {
                array_shift($lines);
            }
            if ($lines !== [] && preg_match('/^#\s+/', trim($lines[0]))) {
                array_shift($lines);
                while ($lines !== []) {
                    $drop = trim($lines[0]);
                    array_shift($lines);
                    if ($drop === '---') {
                        break;
                    }
                }
            }
        }

        $out = '';
        $i = 0;
        $total = count($lines);

        while ($i < $total) {
            $trim = trim(rtrim($lines[$i]));

            if ($trim === '') {
                $i++;
                continue;
            }

            // html comments (and build markers) are never rendered
            if (str_starts_with($trim, '<!--')) {
                while ($i < $total && !str_contains($lines[$i], '-->')) {
                    $i++;
                }
                $i++;
                continue;
            }

            // fenced code block
            if (str_starts_with($trim, '```')) {
                $i++;
                $code = '';
                while ($i < $total && !str_starts_with(trim($lines[$i]), '```')) {
                    $code .= rtrim($lines[$i]) . "\n";
                    $i++;
                }
                $i++;
                foreach (explode("\n", rtrim($code, "\n")) as $codeLine) {
                    $out .= $this->p($this->run($codeLine === '' ? ' ' : $codeLine, ['font' => 'Consolas', 'sz' => 17]), 'CodeBlock');
                }
                continue;
            }

            // headings
            if (preg_match('/^(#{1,4})\s+(.*)$/', $trim, $m)) {
                $out .= $this->heading(strlen($m[1]), trim($m[2]));
                $i++;
                continue;
            }

            // horizontal rule
            if (preg_match('/^-{3,}$/', $trim)) {
                $out .= $this->rule();
                $i++;
                continue;
            }

            // table
            if (str_starts_with($trim, '|')) {
                $rows = [];
                while ($i < $total && str_starts_with(trim($lines[$i]), '|')) {
                    $cells = $this->splitRow(trim($lines[$i]));
                    if (!$this->isSeparatorRow($cells)) {
                        $rows[] = $cells;
                    }
                    $i++;
                }
                if ($rows !== []) {
                    $out .= $this->table($rows);
                    $out .= $this->p('', '', '<w:spacing w:after="0"/>');
                }
                continue;
            }

            // bullet
            if (preg_match('/^[-*]\s+(.*)$/', $trim, $m)) {
                $out .= $this->p($this->run('•  ', ['sz' => 21]) . $this->inline($m[1]), 'ListParagraph');
                $i++;
                continue;
            }

            // numbered item (the visible number is kept, no numbering part is required)
            if (preg_match('/^(\d+)\.\s+(.*)$/', $trim, $m)) {
                $out .= $this->p($this->run($m[1] . '.  ', ['b' => true, 'sz' => 21]) . $this->inline($m[2]), 'ListParagraph');
                $i++;
                continue;
            }

            // blockquote
            if (str_starts_with($trim, '>')) {
                $out .= $this->p($this->inline(ltrim($trim, '> ')), 'Quote');
                $i++;
                continue;
            }

            // paragraph — soft-wrapped lines are merged
            $buffer = [$trim];
            $i++;
            while ($i < $total) {
                $next = trim($lines[$i]);
                if ($next === '' || preg_match('/^(#{1,4}\s|\||[-*]\s|\d+\.\s|>|```|<!--|-{3,}$)/', $next)) {
                    break;
                }
                $buffer[] = $next;
                $i++;
            }
            $out .= $this->p($this->inline(implode(' ', $buffer)));
        }

        return $out;
    }

    /** Assembles word/document.xml for one or more markdown sources. */
    public function build(array $markdowns): string
    {
        $body = $this->cover();
        foreach ($markdowns as $index => $markdown) {
            if ($index > 0) {
                $body .= $this->pageBreak();
            }
            $body .= $this->markdownToBody($markdown);
        }

        $sectPr = '<w:sectPr><w:footerReference w:type="default" r:id="rId2"/>'
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/>'
            . '</w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<w:document xmlns:w="' . NS_W . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>' . $body . $sectPr . '</w:body></w:document>';
    }

    // ----------------------------------------------------------- OOXML parts

    private static function partContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function partRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function partDocumentRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
            . '</Relationships>';
    }

    private static function partFooter(string $title): string
    {
        $rPr = '<w:rPr><w:sz w:val="18"/><w:color w:val="7A86A8"/></w:rPr>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<w:ftr xmlns:w="' . NS_W . '">'
            . '<w:p><w:pPr><w:jc w:val="center"/>' . $rPr . '</w:pPr>'
            . '<w:r>' . $rPr . '<w:t xml:space="preserve">' . xml($title) . ' · page </w:t></w:r>'
            . '<w:fldSimple w:instr=" PAGE "><w:r>' . $rPr . '<w:t>1</w:t></w:r></w:fldSimple>'
            . '<w:r>' . $rPr . '<w:t xml:space="preserve"> of </w:t></w:r>'
            . '<w:fldSimple w:instr=" NUMPAGES "><w:r>' . $rPr . '<w:t>1</w:t></w:r></w:fldSimple>'
            . '</w:p></w:ftr>';
    }

    private static function partStyles(): string
    {
        $heading = static function (int $id, string $size, string $color, int $before, int $after, bool $italic = false, bool $rule = false): string {
            $pPr = '<w:pPr><w:keepNext/><w:spacing w:before="' . $before . '" w:after="' . $after . '"/>'
                . ($rule ? '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="2" w:color="2456E6"/></w:pBdr>' : '')
                . '<w:outlineLvl w:val="' . ($id - 1) . '"/></w:pPr>';
            $rPr = '<w:rPr><w:b/>' . ($italic ? '<w:i/>' : '') . '<w:color w:val="' . $color . '"/>'
                . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';

            return '<w:style w:type="paragraph" w:styleId="Heading' . $id . '"><w:name w:val="heading ' . $id . '"/>'
                . '<w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>' . $pPr . $rPr . '</w:style>';
        };

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<w:styles xmlns:w="' . NS_W . '">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="21"/><w:szCs w:val="21"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $heading(1, '32', '12306E', 360, 140, false, true)
            . $heading(2, '26', '1F3864', 300, 120)
            . $heading(3, '23', '2456E6', 240, 100)
            . $heading(4, '21', '44506E', 200, 80, true)
            . '<w:style w:type="paragraph" w:styleId="TableText"><w:name w:val="Table Text"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
            . '<w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:ind w:left="284" w:hanging="284"/><w:spacing w:after="60"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:ind w:left="284"/><w:pBdr><w:left w:val="single" w:sz="12" w:space="6" w:color="C7D0E4"/></w:pBdr></w:pPr>'
            . '<w:rPr><w:i/><w:color w:val="44506E"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="CodeBlock"><w:name w:val="Code Block"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/><w:ind w:left="170"/>'
            . '<w:shd w:val="clear" w:color="auto" w:fill="F4F6FA"/></w:pPr>'
            . '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="17"/></w:rPr></w:style>'
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/>'
            . '<w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="85" w:type="dxa"/>'
            . '<w:bottom w:w="60" w:type="dxa"/><w:right w:w="85" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:basedOn w:val="TableNormal"/>'
            . '<w:tblPr><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="8FA0C0"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="8FA0C0"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="8FA0C0"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="8FA0C0"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="C7D0E4"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="C7D0E4"/>'
            . '</w:tblBorders></w:tblPr></w:style>'
            . '</w:styles>';
    }

    private static function partCoreProps(string $title, string $author): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . xml($title) . '</dc:title>'
            . '<dc:creator>' . xml($author) . '</dc:creator>'
            . '<cp:lastModifiedBy>' . xml($author) . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function partAppProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>MSS-CRM md-to-docx</Application>'
            . '<AppVersion>1.0</AppVersion>'
            . '</Properties>';
    }

    /**
     * Writes the .docx package.
     *
     * @param  list<string>  $markdowns  markdown bodies
     * @param  list<string>  $sources    file names shown on the cover
     * @return list<string>  the parts written
     */
    public static function write(string $outPath, array $markdowns, Args $args, array $sources): array
    {
        $builder = new self($args->title, $args->subtitle, $args->author, $sources);

        $parts = [
            '[Content_Types].xml' => self::partContentTypes(),
            '_rels/.rels' => self::partRootRels(),
            'word/document.xml' => $builder->build($markdowns),
            'word/styles.xml' => self::partStyles(),
            'word/footer1.xml' => self::partFooter($args->title),
            'word/_rels/document.xml.rels' => self::partDocumentRels(),
            'docProps/core.xml' => self::partCoreProps($args->title, $args->author),
            'docProps/app.xml' => self::partAppProps(),
        ];

        $dir = dirname($outPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create output directory: ' . $dir);
        }

        $zip = new ZipArchive();
        if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open output file for writing: ' . $outPath);
        }
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return array_keys($parts);
    }
}

// ------------------------------------------------------------------ runtime

function docxStats(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['paragraphs' => 0, 'tables' => 0];
    }
    $document = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    return [
        'paragraphs' => substr_count($document, '<w:p>') + substr_count($document, '<w:p '),
        'tables' => substr_count($document, '<w:tbl>'),
    ];
}

/** @return list<string> */
function validateDocx(string $path): array
{
    $required = [
        '[Content_Types].xml',
        '_rels/.rels',
        'word/document.xml',
        'word/styles.xml',
        'word/footer1.xml',
        'word/_rels/document.xml.rels',
        'docProps/core.xml',
        'docProps/app.xml',
    ];

    $errors = [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['cannot open the generated package'];
    }

    foreach ($required as $name) {
        $content = $zip->getFromName($name);
        if ($content === false) {
            $errors[] = "missing part: {$name}";
            continue;
        }
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        if (!$doc->loadXML($content)) {
            foreach (libxml_get_errors() as $error) {
                $errors[] = $name . ': ' . trim($error->message);
            }
        }
        libxml_clear_errors();
    }
    $zip->close();

    return $errors;
}

function usage(): void
{
    echo "Usage: php tools/md-to-docx.php --in=<file.md> [--in=<file2.md> ...] --out=<file.docx>\n";
    echo "       [--title=\"Document title\"] [--subtitle=\"Version · Date\"] [--author=\"MSS\"] [--no-validate]\n";
}

/** @var list<string> $argv */
$args = Args::parse($argv);

if ($args->in === [] || $args->out === '') {
    usage();
    exit(1);
}

$markdowns = [];
$sources = [];
foreach ($args->in as $input) {
    $path = realpath($input);
    if ($path === false || !is_file($path)) {
        fwrite(STDERR, "Input not found: {$input}\n");
        exit(1);
    }
    $markdowns[] = (string) file_get_contents($path);
    $sources[] = basename($path);
}

try {
    $parts = DocxBuilder::write($args->out, $markdowns, $args, $sources);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$size = file_exists($args->out) ? filesize($args->out) : 0;
$stats = docxStats($args->out);

echo 'OUT  ' . $args->out . PHP_EOL;
echo 'SIZE ' . number_format($size / 1024, 1) . ' KB' . PHP_EOL;
echo 'PARTS ' . count($parts) . ' (' . implode(', ', $parts) . ')' . PHP_EOL;
echo 'BODY paragraphs=' . $stats['paragraphs'] . ' tables=' . $stats['tables'] . PHP_EOL;

$errors = $args->validate ? validateDocx($args->out) : [];
if ($args->validate) {
    echo $errors === [] ? 'VALIDATION ok — XML parts well-formed' . PHP_EOL : 'VALIDATION failed:' . PHP_EOL . implode(PHP_EOL, $errors) . PHP_EOL;
}

exit($errors === [] ? 0 : 1);
