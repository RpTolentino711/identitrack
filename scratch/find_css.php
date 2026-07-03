<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/UPCC/case_view.php');
if (preg_match_all('/\.voting-modal[^{]*\{[^}]*\}/', $content, $matches)) {
    print_r($matches[0]);
} else {
    echo "No matching css rules found.\n";
}
