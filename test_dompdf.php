<?php
require 'vendor/autoload.php';
use Dompdf\Dompdf;
$dompdf = new Dompdf();
$dompdf->loadHtml('hello');
$dompdf->render();
$out = $dompdf->output();
file_put_contents('test.pdf', $out);
echo strlen($out);
