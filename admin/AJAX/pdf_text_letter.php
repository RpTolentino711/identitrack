<?php
/**
 * Tiny PDF generator (text-only) without external libraries.
 * Generates a single-page PDF with simple lines.
 *
 * This is enough for "admin can edit PDF text then email it".
 * Later we can replace with FPDF/TCPDF for better formatting.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

function pdf_make_simple(string $title, array $lines): string {
    $title = trim($title);
    if ($title === '') $title = 'Official Notice';

    // Initialize TCPDF
    $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('IdentiTrack');
    $pdf->SetAuthor('Student Discipline Office');
    $pdf->SetTitle($title);

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(TRUE, 20);

    // Add a page
    $pdf->AddPage();

    // Add Logo
    $logoPath = __DIR__ . '/../../assets/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 20, 20, 22, '', 'PNG');
    }

    // Letterhead Text
    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetTextColor(30, 58, 138); // Navy blue
    $pdf->Cell(26, 8, '', 0, 0); // Spacing for logo
    $pdf->Cell(0, 8, 'Student Discipline Office', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(26, 5, '', 0, 0);
    $pdf->Cell(0, 5, 'Official Student Conduct Notice', 0, 1, 'L');
    $pdf->Cell(26, 5, '', 0, 0);
    $pdf->Cell(0, 5, 'IdentiTrack System', 0, 1, 'L');

    // Line separator
    $pdf->Ln(10);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(8);
    
    // Title
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->Ln(5);

    // Process lines
    $pdf->SetFont('helvetica', '', 11);
    
    foreach ($lines as $ln) {
        $ln = trim((string)$ln);
        if ($ln === '') {
            $pdf->Ln(3);
            continue;
        }
        
        // Clean up common encoding artifacts from text input
        $ln = str_replace(['à', 'â€"'], '-', $ln);
        
        // Check for headers to bold
        if (strpos($ln, 'To:') === 0 || strpos($ln, 'Student:') === 0 || strpos($ln, 'Generated:') === 0) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->MultiCell(0, 6, $ln, 0, 'L');
            $pdf->SetFont('helvetica', '', 11);
            continue;
        }

        // MultiCell natively handles \n inside $ln
        $pdf->MultiCell(0, 6, $ln, 0, 'L');
        $pdf->Ln(3);
    }

    return $pdf->Output('letter.pdf', 'S');
}