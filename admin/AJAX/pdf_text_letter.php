<?php
/**
 * Tiny PDF generator (text-only) without external libraries.
 * Generates a single-page PDF with simple lines.
 *
 * This is enough for "admin can edit PDF text then email it".
 * Later we can replace with FPDF/TCPDF for better formatting.
 */

function pdf_escape(string $s): string {
  // escape backslash and parentheses
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace('(', '\\(', $s);
  $s = str_replace(')', '\\)', $s);
  return $s;
}

function pdf_make_simple(string $title, array $lines): string {
  // Basic PDF objects:
  // 1) Catalog
  // 2) Pages
  // 3) Page
  // 4) Font
  // 5) Content stream

  $title = trim($title);
  if ($title === '') $title = 'Notice';

  $yStart = 760;
  $lineHeight = 14;

  $content = "BT\n/F1 12 Tf\n72 {$yStart} Td\n";
  $content .= "( " . pdf_escape($title) . " ) Tj\n0 -20 Td\n";

  $y = $yStart - 20;
  foreach ($lines as $ln) {
    $ln = rtrim((string)$ln);
    if ($ln === '') {
      $content .= "0 -" . ($lineHeight) . " Td\n";
      continue;
    }
    // split long lines (rough)
    $chunks = str_split($ln, 95);
    foreach ($chunks as $c) {
      $content .= "( " . pdf_escape($c) . " ) Tj\n0 -" . ($lineHeight) . " Td\n";
      $y -= $lineHeight;
      if ($y < 80) break 2;
    }
  }
  $content .= "ET\n";

  $stream = $content;
  $streamLen = strlen($stream);

  $objects = [];

  // 1 Catalog
  $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

  // 2 Pages
  $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";

  // 3 Page
  $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";

  // 4 Font (Helvetica)
  $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

  // 5 Contents
  $objects[] = "<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream";

  // Build PDF
  $pdf = "%PDF-1.4\n";
  $xref = [];
  $offset = strlen($pdf);

  foreach ($objects as $i => $obj) {
    $objNum = $i + 1;
    $xref[$objNum] = $offset;
    $pdf .= "{$objNum} 0 obj\n{$obj}\nendobj\n";
    $offset = strlen($pdf);
  }

  $xrefStart = strlen($pdf);
  $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i <= count($objects); $i++) {
    $pdf .= str_pad((string)$xref[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
  }

  $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
  $pdf .= "startxref\n{$xrefStart}\n%%EOF";

  return $pdf;
}