<?php
require('lib/tfpdf/tfpdf.php');

class PDF extends tFPDF {
    var $eventInfo;
    var $margins = 8;

    var $colEven = array(0xee, 0xee, 0xee);
    var $colOdd = array(0xff, 0xff, 0xff);

    function __construct($eventInfo, $orientation = "P", $unit= "mm", $size= "A4") {
        $this->eventInfo = $eventInfo;
        parent::__construct($orientation, $unit, $size);
        $this->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
        $this->AddFont('DejaVu', 'B', 'DejaVuSansCondensed-Bold.ttf', true);
        $this->AddFont('DejaVu', 'I', 'DejaVuSansCondensed-Oblique.ttf', true);
        $this->AddFont('DejaVu', 'BI', 'DejaVuSans-BoldOblique.ttf', true);
        $this->SetMargins($this->margins, $this->margins);
    }

    function Header() {
        $pageWidth = $this->GetPageWidth();
        $headerHeight = 10;
        $logoPath = __DIR__ . '/../static/img/tuebix-logo.png';
        $logoHeight = 8;
        $logoWidth = 0;
        if (file_exists($logoPath)) {
            list($imgW, $imgH) = getimagesize($logoPath);
            $logoWidth = $logoHeight * ($imgW / $imgH);
        }

        $this->SetXY($this->margins, 0);
        $this->SetFont('DejaVu', 'I', 12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($pageWidth - 2 * $this->margins - $logoWidth, $headerHeight,
            "Schichtplan " . $this->eventInfo["eventName"], 0, 0, 'L');

        if (file_exists($logoPath)) {
            $this->Image($logoPath, $pageWidth - $this->margins - $logoWidth, 1, $logoWidth, $logoHeight);
        }

        $this->Line($this->margins, $headerHeight, $pageWidth - $this->margins, $headerHeight);
        $this->Ln($headerHeight);
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetFont('DejaVu', 'I', 8);
        $this->Cell(0, 10, 
            "Druckzeitpunkt: " . date(DATE_ATOM) . " - erstellt mit ♥ vom Helfilisten Hosting Interface", 
            0, 0, 'C');
    }
}

function buildPdf($config, &$eventInfo) {
    $pdf = new PDF($eventInfo, 'L', 'mm', 'A4');
    foreach ($eventInfo["eventTasks"] as $task) {
        /* page creation and title */
        $pdf->AddPage();
        $pdf->SetFont("DejaVu", "B", 24);
        $pdf->Ln(6);
        $pdf->Cell(0, 10, "Schichtplan " . html_entity_decode($task["taskName"]), 0, 0, "C");
        $pdf->Ln(13);
        $pdf->SetFont("DejaVu", "I", 12);
        $pdf->MultiCell(0, 6, 
            strip_tags(html_entity_decode(str_replace(array("<br/>", "<br>"), "\n", $task["taskDesc"]))), 
            0, "C");
        $pdf->Ln(3);
        /* table header */
        /* scale to max width */
        $maxSlots = max(array_column($task['taskShifts'], 'shiftSlots'));
        $colWidth = ($pdf->GetPageWidth() - 2 * $pdf->margins) / ($maxSlots + 1);
        $pdf->Cell($colWidth, 10, "", "B", 0, "C");
        $pdf->SetFont("DejaVu", "B", 14);
        for ($slot = 0; $slot < $maxSlots; $slot++) {
            $pdf->Cell($colWidth, 10, $slot + 1, "B", 0, "C");
        }
        $pdf->Ln(10);
        /* table content */
        $initFontSize = 14; /* dynamic font size */
        $shiftIdx = 0;
        foreach($task["taskShifts"] as $shift) {
            /* alternating colors */
            $pdf->SetFillColor(
                ($shiftIdx % 2) ? $pdf->colOdd[0] : $pdf->colEven[0],
                ($shiftIdx % 2) ? $pdf->colOdd[1] : $pdf->colEven[1],
                ($shiftIdx % 2) ? $pdf->colOdd[2] : $pdf->colEven[2]
            );
            $fontSize = $initFontSize;
            $pdf->SetFont("DejaVu", "B", $fontSize);
            while($pdf->GetStringWidth($shift["shiftName"]) > $colWidth) {
                /* shrink until fit */
                $fontSize--;
                $pdf->SetFont("DejaVu", "B", $fontSize);
            }
            $pdf->Cell($colWidth, 10, $shift["shiftName"], "B", 0, "C", 1);
            $pdf->SetFont("DejaVu", "", 14);
            for ($slot = 0; $slot < $maxSlots; $slot++) {
                if($slot < $shift["shiftSlots"]) {
                    /* valid slot */
                    $pdf->Cell($colWidth, 10, $shift["entries"][$slot]["entryName"] ?? "",
                        "B", 0, "C", 1);
                } else {
                    /* invalid slot */
                    $pdf->Cell($colWidth, 10, "", 0, 0, "C", 0);
                }
            }
            $pdf->Ln(10);
            $shiftIdx++;
        }
    }
    return $pdf;
}
function handlePdfExport($config, &$eventInfo) {
    $pdf = buildPdf($config, $eventInfo);
    /* force uncached output */
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    $pdf->Output("I");
}

function exportPdfAsString($config, &$eventInfo) {
    $pdf = buildPdf($config, $eventInfo);
    return $pdf->Output("S");
}