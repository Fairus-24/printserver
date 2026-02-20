<?php
// Create a simple PDF for testing using FPDF library OR output raw PDF
// Since FPDF might not be available, we'll use a simple method

// Raw PDF Header
$pdf = "%PDF-1.4\n";
$pdf .= "1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n";
$pdf .= "2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n";
$pdf .= "3 0 obj\n<</Type/Page/Parent 2 0 R/Resources<</Font<</F1 4 0 R>>>>/MediaBox[0 0 612 792]/Contents 5 0 R>>\nendobj\n";
$pdf .= "4 0 obj\n<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>\nendobj\n";
$pdf .= "5 0 obj\n<</Length 125>>\nstream\nBT\n/F1 12 Tf\n50 750 Td\n(FIK Smart Print Server - Test Document) Tj\n0 -30 Td\n(Created: " . date('Y-m-d H:i:s') . ") Tj\n0 -30 Td\n(This is a test PDF for the print server.) Tj\nET\nendstream\nendobj\n";
$pdf .= "xref\n0 6\n";
$pdf .= "0000000000 65535 f\n";
$pdf .= "0000000009 00000 n\n";
$pdf .= "0000000058 00000 n\n";
$pdf .= "0000000115 00000 n\n";
$pdf .= "0000000273 00000 n\n";
$pdf .= "0000000352 00000 n\n";
$pdf .= "trailer\n<</Size 6/Root 1 0 R>>\n";
$pdf .= "startxref\n528\n%%EOF\n";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="FIK_Test_Document.pdf"');
echo $pdf;
?>
