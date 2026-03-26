<?php
/**
 * Export Resume from SQLite to PDF + Word
 *
 * Fixes applied:
 *  1. All sections included (Work, Education, Skills, Projects, Awards, Certificates, Profiles)
 *  2. PHPWord HTML compatibility — uses native PHPWord API instead of Html::addHtml()
 *  3. Portable DB path — resolved relative to this script file
 *  4. Highlights rendered as proper bullet lists in both PDF and Word
 */

// Determine context: CLI, Web UI Include, or Web Export Request
$isCli = (php_sapi_name() === 'cli');
$format = $_GET['format'] ?? null;
$isExportRequest = in_array($format, ['pdf', 'docx']);

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\SimpleType\Jc;

// ====================== CONFIG ======================

// Resolve DB path relative to this script so it works on any OS / machine.
// Change this one line if your DB lives elsewhere.
$dbFile = __DIR__ . '/database/profile.db';

// Fallback: allow override via env variable (useful for CI / Docker)
if (getenv('RESUME_DB_PATH')) {
    $dbFile = getenv('RESUME_DB_PATH');
}

$outputPdf  = __DIR__ . '/Deepak_Kumar_Vasudevan_Resume.pdf';
$outputDocx = __DIR__ . '/Deepak_Kumar_Vasudevan_Resume.docx';

// ====================== DB ======================

if (!file_exists($dbFile)) {
    die("Error: Database file not found at: $dbFile\n");
}

try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

$dateStr = date('d/m/Y');
$footerText = "Generated with IDhwani by Deepak Kumar Vasudevan ('Lavanya Deepak') on $dateStr"; // Using your custom assistant name

// ====================== FETCH DATA ======================

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$basics = fetchOne($pdo, "SELECT * FROM basics LIMIT 1");
if (!$basics) {
    die("No data in basics table.\n");
}

$id = $basics['id'];

// Fetch highlights for a given parent table
function fetchHighlights(PDO $pdo, string $parentTable, int $parentId): array {
    // Highlights table is assumed to be named "<parent>_highlights"
    // with columns: id, {parent}_id, highlight
    // Adjust column/table names to match your actual schema.
    $table  = $parentTable . '_highlights';
    $fkCol  = $parentTable . '_id';
    try {
        return fetchAll($pdo, "SELECT highlight FROM $table WHERE $fkCol = ? ORDER BY id", [$parentId]);
    } catch (Exception $e) {
        return []; // table may not exist
    }
}

$works        = fetchAll($pdo, "SELECT * FROM work         WHERE basics_id = ? ORDER BY startDate DESC", [$id]);
$educations   = fetchAll($pdo, "SELECT * FROM education    WHERE basics_id = ? ORDER BY startDate DESC", [$id]);
$skills       = fetchAll($pdo, "SELECT * FROM skills       WHERE basics_id = ?", [$id]);
$projects     = fetchAll($pdo, "SELECT * FROM projects     WHERE basics_id = ? ORDER BY startDate DESC", [$id]);
$awards       = fetchAll($pdo, "SELECT * FROM awards       WHERE basics_id = ? ORDER BY date DESC",      [$id]);
$certificates = fetchAll($pdo, "SELECT * FROM certificates WHERE basics_id = ? ORDER BY date DESC",      [$id]);
$profiles     = fetchAll($pdo, "SELECT * FROM profiles     WHERE basics_id = ?", [$id]);

// Skill keywords (may be in a separate table)
foreach ($skills as &$skill) {
    try {
        $kws = fetchAll($pdo, "SELECT keyword FROM skill_keywords WHERE skill_id = ? ORDER BY id", [$skill['id']]);
        $skill['keywords'] = array_column($kws, 'keyword');
    } catch (Exception $e) {
        $skill['keywords'] = [];
    }
}
unset($skill);

// Work highlights
foreach ($works as &$work) {
    $work['highlights'] = fetchHighlights($pdo, 'work', $work['id']);
}
unset($work);

// Project highlights
foreach ($projects as &$project) {
    $project['highlights'] = fetchHighlights($pdo, 'projects', $project['id']);
}
unset($project);

if ($isCli || $format === 'pdf') {
// ====================== PDF via mPDF ======================

$htmlFull = generateFullHTML($basics, $works, $educations, $skills, $projects, $awards, $certificates, $profiles);

try {
    $mpdf = new \Mpdf\Mpdf([
        'format'        => 'A4',
        'margin_left'   => 15,
        'margin_right'  => 15,
        'margin_top'    => 20,
        'margin_bottom' => 20,
        'default_font'  => 'dejavusans',
    ]);
    $mpdf->SetTitle($basics['name'] . ' - Resume');
	$dateStr = date('d/m/Y');

    // Define the footer for mPDF
    $footerHtml = '
    <htmlpagefooter name="LastPageFooter">
        <div style="text-align: center; font-size: 9pt; color: #666; font-style: italic;">' . e($footerText) . '</div>
    </htmlpagefooter>';

    // In your generateFullHTML function, ensure the body ends with:
    // <footer name="LastPageFooter" content="LastPageFooter"></footer>

    $mpdf->SetHTMLFooter('<div style="text-align:center;font-size:9pt;color:#666;font-style:italic;">' . htmlspecialchars($footerText) . '</div>');
    $mpdf->WriteHTML($htmlFull);
    
    if ($isCli) {
        $mpdf->Output($outputPdf, 'F');
        echo "✅ PDF saved: $outputPdf\n";
    } else {
        $mpdf->Output($basics['name'] . '_Resume.pdf', 'D');
        exit;
    }
} catch (Exception $e) {
    if ($isCli) echo "⚠️  PDF export failed: " . $e->getMessage() . "\n";
}
}

if ($isCli || $format === 'docx') {
// ====================== Word via native PHPWord API ======================
// We avoid Html::addHtml() entirely because it silently drops most CSS.
// Instead we build the document using PHPWord's object API for reliable output.
try {
    $phpWord = new PhpWord();

    // --- Global font defaults ---
    $phpWord->setDefaultFontName('Calibri');
    $phpWord->setDefaultFontSize(11);

    // --- Paragraph styles ---
    $phpWord->addParagraphStyle('center',   ['alignment' => Jc::CENTER]);
    $phpWord->addParagraphStyle('justify',  ['alignment' => Jc::BOTH, 'spaceAfter' => 80]);
    $phpWord->addParagraphStyle('noSpace',  ['spaceAfter' => 0, 'spaceBefore' => 0]);

    // --- Font styles ---
    $phpWord->addFontStyle('h1',      ['bold' => true, 'size' => 20, 'color' => '1e40af']);
    $phpWord->addFontStyle('h2',      ['bold' => true, 'size' => 14, 'color' => '1e40af']);
    $phpWord->addFontStyle('h3',      ['bold' => true, 'size' => 12, 'color' => '1a1a1a']);
    $phpWord->addFontStyle('italic',  ['italic' => true, 'color' => '555555', 'size' => 10]);
    $phpWord->addFontStyle('normal',  ['size' => 11]);
    $phpWord->addFontStyle('bold',    ['bold' => true, 'size' => 11]);
    $phpWord->addFontStyle('small',   ['size' => 10, 'color' => '555555']);

    // --- Section ---
    $section = $phpWord->addSection([
        'marginLeft'   => 1080,   // 0.75 inch
        'marginRight'  => 1080,
        'marginTop'    => 1080,
        'marginBottom' => 1080,
    ]);

    // Helper: add a horizontal rule using a bottom border on an empty paragraph
    $hrStyle = [
        'borderBottomSize'  => 6,
        'borderBottomColor' => 'cccccc',
        'spaceAfter'        => 80,
        'spaceBefore'       => 0,
    ];

    // Helper: section heading with underline rule
    $addSectionHeading = function(string $title) use ($section, $phpWord, $hrStyle) {
        $section->addText($title, 'h2', $hrStyle);
    };

    // ---- NAME & CONTACT ----
    $section->addText(e($basics['name']), 'h1', 'center');

    if (!empty($basics['label'])) {
        $section->addText(e($basics['label']), ['size' => 13, 'color' => '444444'], 'center');
    }

    // Contact line
    $contactParts = array_filter([
        $basics['email']  ?? '',
        $basics['phone']  ?? '',
        $basics['url']    ?? '',
    ]);
    if ($contactParts) {
        $section->addText(implode('  •  ', array_map('e', $contactParts)), 'small', 'center');
    }

    // Social profiles
    if ($profiles) {
        $profileLine = implode('  |  ', array_map(fn($p) => e($p['network']) . ': ' . e($p['url']), $profiles));
        $section->addText($profileLine, 'small', 'center');
    }

    $section->addTextBreak(1);

    // ---- SUMMARY ----
    if (!empty($basics['summary'])) {
        $addSectionHeading('Professional Summary');
        $section->addText(e($basics['summary']), 'normal', 'justify');
        $section->addTextBreak(1);
    }

// ---- WORK EXPERIENCE ----
if ($works) {
    $addSectionHeading('Work Experience');
    foreach ($works as $work) {
        $startYear = (int)substr($work['startDate'], 0, 4);
        $yearsAgo = $currentYear - $startYear;

        // Header (Always shown)
        $title = e($work['position']) . ' — ' . e($work['name']);
        $section->addText($title, 'h3', ['spaceAfter' => 0]);

        $dateRange = e($work['startDate'] ?? '') . ' – ' . e($work['endDate'] ?? 'Present');
        if (!empty($work['location'])) {
            $dateRange .= '  |  ' . e($work['location']);
        }
        $section->addText($dateRange, 'italic', 'noSpace');

        // Logic Gate for Details
        if ($yearsAgo <= 7) {
            // TIER 1: Full Detail
            if (!empty($work['summary'])) {
                $section->addText(e($work['summary']), 'normal', ['spaceAfter' => 40]);
            }
            foreach ($work['highlights'] as $hl) {
                addBullet($section, e($hl['highlight']));
            }
        } elseif ($yearsAgo <= 15) {
            // TIER 2: Summary Only
            if (!empty($work['summary'])) {
                $section->addText(e($work['summary']), 'normal', ['spaceAfter' => 40]);
            }
        } 
        // TIER 3: Older than 15 years (No additional text added)

        $section->addTextBreak(1);
    }
}

    // ---- EDUCATION ----
    if ($educations) {
        $addSectionHeading('Education');
        foreach ($educations as $edu) {
            $degree = trim(implode(', ', array_filter([
                $edu['studyType']  ?? '',
                $edu['area']       ?? '',
            ])));
            $section->addText(e($degree), 'h3', ['spaceAfter' => 0]);
            $section->addText(e($edu['institution'] ?? ''), 'bold', 'noSpace');

            $dateRange = e($edu['startDate'] ?? '') . ' – ' . e($edu['endDate'] ?? 'Present');
            if (!empty($edu['score'])) {
                $dateRange .= '  |  GPA/Score: ' . e($edu['score']);
            }
            $section->addText($dateRange, 'italic', 'noSpace');

            if (!empty($edu['url'])) {
                $section->addText(e($edu['url']), 'small', 'noSpace');
            }

            $section->addTextBreak(1);
        }
    }

    // ---- SKILLS ----
    if ($skills) {
        $addSectionHeading('Skills');
        foreach ($skills as $skill) {
            $kwLine = !empty($skill['keywords']) ? implode(', ', array_map('e', $skill['keywords'])) : '';
            $line   = e($skill['name'] ?? '') . ($kwLine ? ': ' . $kwLine : '');
            addBullet($section, $line);
        }
        $section->addTextBreak(1);
    }

    // ---- PROJECTS ----
    if ($projects) {
        $addSectionHeading('Projects');
        foreach ($projects as $proj) {
            $section->addText(e($proj['name'] ?? 'Unnamed Project'), 'h3', ['spaceAfter' => 0]);

            $meta = array_filter([
                !empty($proj['startDate']) ? (e($proj['startDate']) . ' – ' . e($proj['endDate'] ?? 'Ongoing')) : '',
                $proj['entity']   ?? '',
                $proj['type']     ?? '',
            ]);
            if ($meta) {
                $section->addText(implode('  |  ', $meta), 'italic', 'noSpace');
            }

            if (!empty($proj['url'])) {
                $section->addText(e($proj['url']), 'small', 'noSpace');
            }

            if (!empty($proj['description'])) {
                $section->addText(e($proj['description']), 'normal', ['spaceAfter' => 40]);
            }

            foreach ($proj['highlights'] as $hl) {
                addBullet($section, e($hl['highlight']));
            }

            $section->addTextBreak(1);
        }
    }

    // ---- AWARDS ----
    if ($awards) {
        $addSectionHeading('Awards & Honours');
        foreach ($awards as $award) {
            $section->addText(e($award['title'] ?? ''), 'h3', ['spaceAfter' => 0]);
            $meta = array_filter([
                $award['date']    ?? '',
                $award['awarder'] ?? '',
            ]);
            if ($meta) {
                $section->addText(implode('  |  ', array_map('e', $meta)), 'italic', 'noSpace');
            }
            if (!empty($award['summary'])) {
                $section->addText(e($award['summary']), 'normal', ['spaceAfter' => 40]);
            }
            $section->addTextBreak(1);
        }
    }

    // ---- CERTIFICATES ----
    if ($certificates) {
        $addSectionHeading('Certifications');
        foreach ($certificates as $cert) {
            $section->addText(e($cert['name'] ?? ''), 'h3', ['spaceAfter' => 0]);
            $meta = array_filter([
                $cert['date']   ?? '',
                $cert['issuer'] ?? '',
            ]);
            if ($meta) {
                $section->addText(implode('  |  ', array_map('e', $meta)), 'italic', 'noSpace');
            }
            if (!empty($cert['url'])) {
                $section->addText(e($cert['url']), 'small', 'noSpace');
            }
            $section->addTextBreak(1);
        }
    }

// Add footer immediately after section creation
$footer = $section->addFooter();
$footer->addText(
    $footerText,
    ['size' => 9, 'italic' => true, 'color' => '666666'],
    ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
);

    // ---- SAVE ----
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    
    if ($isCli) {
        $writer->save($outputDocx);
        echo "✅ Word saved: $outputDocx\n";
    } else {
        header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
        header("Content-Disposition: attachment; filename=\"" . $basics['name'] . "_Resume.docx\"");
        $writer->save('php://output');
        exit;
    }

} catch (Exception $e) {
    if ($isCli) echo "⚠️  Word export failed: " . $e->getMessage() . "\n";
}
}

if ($isCli) echo "\nDone. Open the files above to review your resume.\n";

// ====================== HELPERS ======================

/** HTML-safe string */
function e(?string $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Add a bullet point to a PHPWord section.
 * Uses a simple dash prefix since PHPWord list styles are unreliable
 * without a full numbering definition XML block.
 */
function addBullet(\PhpOffice\PhpWord\Element\Section $section, string $text): void {
    $section->addListItem($text, 0, ['size' => 11], ['listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED]);
}

// ====================== HTML FOR PDF ======================

function generateFullHTML(
    array $basics,
    array $works,
    array $educations,
    array $skills,
    array $projects,
    array $awards,
    array $certificates,
    array $profiles
): string {
    $css = '
        body { font-family: Arial, sans-serif; font-size: 11pt; color: #1a1a1a; margin: 0; padding: 0; }
        h1   { text-align: center; color: #1e40af; font-size: 20pt; margin-bottom: 4px; }
        h2   { color: #1e40af; font-size: 13pt; border-bottom: 2px solid #ddd; padding-bottom: 4px; margin-top: 18px; margin-bottom: 6px; }
        h3   { font-size: 11pt; margin: 8px 0 2px 0; }
        p    { margin: 4px 0; text-align: justify; }
        .center  { text-align: center; }
        .meta    { font-style: italic; color: #555; font-size: 10pt; margin: 0; }
        .small   { font-size: 10pt; color: #555; }
        ul   { margin: 4px 0 6px 20px; padding: 0; }
        li   { margin-bottom: 3px; }
        .section { margin-bottom: 14px; }
    ';

    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html .= '<style>' . $css . '</style></head><body>';

    // Name & contact
    $html .= '<h1>' . e($basics['name']) . '</h1>';
    if (!empty($basics['label'])) {
        $html .= '<p class="center" style="font-size:13pt;color:#444;">' . e($basics['label']) . '</p>';
    }
    $contactParts = array_filter([
        $basics['email'] ?? '', $basics['phone'] ?? '', $basics['url'] ?? ''
    ]);
    if ($contactParts) {
        $html .= '<p class="center small">' . implode(' &nbsp;•&nbsp; ', array_map('e', $contactParts)) . '</p>';
    }
    if ($profiles) {
        $pLinks = array_map(fn($p) => '<strong>' . e($p['network']) . ':</strong> <a href="' . e($p['url']) . '">' . e($p['url']) . '</a>', $profiles);
        $html .= '<p class="center small">' . implode(' &nbsp;|&nbsp; ', $pLinks) . '</p>';
    }

    // Summary
    if (!empty($basics['summary'])) {
        $html .= '<div class="section"><h2>Professional Summary</h2>';
        $html .= '<p>' . nl2br(e($basics['summary'])) . '</p></div>';
    }

// Work
if ($works) {
    $currentYear = (int)date('Y');
    $html .= '<div class="section"><h2>Work Experience</h2>';
    foreach ($works as $w) {
        $startYear = (int)substr($w['startDate'], 0, 4);
        $yearsAgo = $currentYear - $startYear;

        $html .= '<h3>' . e($w['position']) . ' &mdash; ' . e($w['name']) . '</h3>';
        $meta  = e($w['startDate'] ?? '') . ' &ndash; ' . e($w['endDate'] ?? 'Present');
        if (!empty($w['location'])) $meta .= '  |  ' . e($w['location']);
        $html .= '<p class="meta">' . $meta . '</p>';

        if ($yearsAgo <= 7) {
            // TIER 1
            if (!empty($w['summary'])) $html .= '<p>' . nl2br(e($w['summary'])) . '</p>';
            if ($w['highlights']) {
                $html .= '<ul>';
                foreach ($w['highlights'] as $hl) {
                    $html .= '<li>' . e($hl['highlight']) . '</li>';
                }
                $html .= '</ul>';
            }
        } elseif ($yearsAgo <= 15) {
            // TIER 2
            if (!empty($w['summary'])) $html .= '<p>' . nl2br(e($w['summary'])) . '</p>';
        }
        // TIER 3 remains just a header and meta line
    }
    $html .= '</div>';
}
    // Education
    if ($educations) {
        $html .= '<div class="section"><h2>Education</h2>';
        foreach ($educations as $edu) {
            $degree = trim(implode(', ', array_filter([$edu['studyType'] ?? '', $edu['area'] ?? ''])));
            $html .= '<h3>' . e($degree) . '</h3>';
            $html .= '<p><strong>' . e($edu['institution'] ?? '') . '</strong></p>';
            $meta  = e($edu['startDate'] ?? '') . ' &ndash; ' . e($edu['endDate'] ?? 'Present');
            if (!empty($edu['score'])) $meta .= '  |  GPA/Score: ' . e($edu['score']);
            $html .= '<p class="meta">' . $meta . '</p>';
            if (!empty($edu['url'])) $html .= '<p class="small"><a href="' . e($edu['url']) . '">' . e($edu['url']) . '</a></p>';
        }
        $html .= '</div>';
    }

    // Skills
    if ($skills) {
        $html .= '<div class="section"><h2>Skills</h2><ul>';
        foreach ($skills as $skill) {
            $kws  = !empty($skill['keywords']) ? implode(', ', array_map('e', $skill['keywords'])) : '';
            $html .= '<li><strong>' . e($skill['name'] ?? '') . '</strong>' . ($kws ? ': ' . $kws : '') . '</li>';
        }
        $html .= '</ul></div>';
    }

    // Projects
    if ($projects) {
        $html .= '<div class="section"><h2>Passionate Initiatives/Freelance/Upskilling</h2>';
        foreach ($projects as $proj) {
            $html .= '<h3>' . e($proj['name'] ?? 'Unnamed Project') . '</h3>';
            $meta  = array_filter([
                !empty($proj['startDate']) ? (e($proj['startDate']) . ' &ndash; ' . e($proj['endDate'] ?? 'Ongoing')) : '',
                $proj['entity'] ?? '',
                $proj['type']   ?? '',
            ]);
            if ($meta) $html .= '<p class="meta">' . implode('  |  ', $meta) . '</p>';
            if (!empty($proj['url']))         $html .= '<p class="small"><a href="' . e($proj['url']) . '">' . e($proj['url']) . '</a></p>';
            if (!empty($proj['description'])) $html .= '<p>' . nl2br(e($proj['description'])) . '</p>';
            if ($proj['highlights']) {
                $html .= '<ul>';
                foreach ($proj['highlights'] as $hl) {
                    $html .= '<li>' . e($hl['highlight']) . '</li>';
                }
                $html .= '</ul>';
            }
        }
        $html .= '</div>';
    }

    // Awards
    if ($awards) {
        $html .= '<div class="section"><h2>Awards &amp; Honours</h2>';
        foreach ($awards as $award) {
            $html .= '<h3>' . e($award['title'] ?? '') . '</h3>';
            $meta  = array_filter([$award['date'] ?? '', $award['awarder'] ?? '']);
            if ($meta) $html .= '<p class="meta">' . implode('  |  ', array_map('e', $meta)) . '</p>';
            if (!empty($award['summary'])) $html .= '<p>' . nl2br(e($award['summary'])) . '</p>';
        }
        $html .= '</div>';
    }

    // Certificates
    if ($certificates) {
        $html .= '<div class="section"><h2>Certifications</h2>';
        foreach ($certificates as $cert) {
            $html .= '<h3>' . e($cert['name'] ?? '') . '</h3>';
            $meta  = array_filter([$cert['date'] ?? '', $cert['issuer'] ?? '']);
            if ($meta) $html .= '<p class="meta">' . implode('  |  ', array_map('e', $meta)) . '</p>';
            if (!empty($cert['url'])) $html .= '<p class="small"><a href="' . e($cert['url']) . '">' . e($cert['url']) . '</a></p>';
        }
        $html .= '</div>';
    }

    $html .= '</body></html>';
    return $html;
}