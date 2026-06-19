function pdf_escape(string $s): string {
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace('(', '\\(', $s);
  $s = str_replace(')', '\\)', $s);
  // Remove common UTF-8 artifacts
  $s = str_replace(['à', 'â€"'], '-', $s);
  // Convert other non-ASCII to ASCII approximation if needed, or strip
  $s = preg_replace('/[^\x20-\x7E]/', '', $s);
  return $s;
}

function pdf_make_simple(string $title, array $lines): string {
  $title = trim($title);
  if ($title === '') $title = 'Official Notice';

  $yStart = 750;
  $lineHeight = 16;
  $pageHeight = 792;
  $marginBottom = 60;
  $y = $yStart;
  
  $content = "BT\n/F1 12 Tf\n0 0 0 rg\n72 {$y} Td\n";
  
  // Header
  $content .= "/F2 16 Tf\n( Student Discipline Office ) Tj\n0 -18 Td\n";
  $content .= "/F1 10 Tf\n0.4 0.4 0.4 rg\n( Official Student Conduct Notice ) Tj\n0 -12 Td\n";
  $content .= "( IdentiTrack System ) Tj\n0 -30 Td\n";
  $y -= 60;
  
  $content .= "/F2 14 Tf\n0 0 0 rg\n( " . pdf_escape($title) . " ) Tj\n0 -25 Td\n";
  $y -= 25;
  $content .= "/F1 11 Tf\n";

  foreach ($lines as $rawBlock) {
    // A block might contain actual \n characters if it came from a textarea
    $paragraphs = explode("\n", (string)$rawBlock);
    
    foreach ($paragraphs as $p) {
        $p = rtrim($p);
        if ($p === '') {
            $content .= "0 -" . ($lineHeight) . " Td\n";
            $y -= $lineHeight;
            continue;
        }

        // word wrap (rough approximation: ~85 chars per line for 11pt font)
        $wrapped = explode("\n", wordwrap($p, 85, "\n", true));
        
        // Bold certain headers
        $isHeader = (strpos($p, 'To:') === 0 || strpos($p, 'Student:') === 0 || strpos($p, 'Generated:') === 0 || strpos($p, 'CURRENT OFFENSE:') === 0 || strpos($p, 'OFFENSE HISTORY') === 0);
        
        if ($isHeader) {
            $content .= "/F2 11 Tf\n";
        } else {
            $content .= "/F1 11 Tf\n";
        }

        foreach ($wrapped as $lineText) {
            $content .= "( " . pdf_escape($lineText) . " ) Tj\n0 -" . ($lineHeight) . " Td\n";
            $y -= $lineHeight;
            
            // basic page break
            if ($y < $marginBottom) {
                // Not ideal for a basic script to create new pages, so just stop or cram it
                // For simplicity, we just keep going, it will go off-page in basic generator
                // In a perfect world, we'd create another Page object
            }
        }
        
        // Paragraph spacing
        $content .= "0 -6 Td\n";
        $y -= 6;
    }
  }
  $content .= "ET\n";

  $stream = $content;
  $streamLen = strlen($stream);

  $objects = [];
  $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
  $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
  $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
  $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
  $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
  $objects[] = "<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream";

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