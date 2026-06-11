<?php
$dir = __DIR__ . '/../uploads/letters/';
if (!is_dir($dir)) {
    echo "Directory $dir not found.\n";
    exit;
}
$files = scandir($dir);
$mapping = [];

foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'pdf') {
        $path = $dir . $file;
        $content = file_get_contents($path);
        // Find all parentheses content (which is how PDF stores text strings)
        preg_match_all('/\((.*?)\)/', $content, $matches);
        foreach ($matches[1] as $m) {
            $m = trim($m);
            // Look for "Student:" in the text
            if (stripos($m, 'Student:') !== false) {
                echo "File: $file -> Text: $m\n";
                // Try to parse the name and ID
                // Format could be: "Student: First Last (ID)" or similar
                // Let's strip backslashes
                $clean = str_replace('\\', '', $m);
                if (preg_match('/Student:\s*([^\(]+)\(([^)]+)\)/i', $clean, $parts)) {
                    $name = trim($parts[1]);
                    $id = trim($parts[2]);
                    $mapping[$id] = $name;
                } else if (preg_match('/Student:\s*([^\(]+)\((.*)/i', $clean, $parts)) {
                    // Sometimes trailing parenthesis is separate or missing in match
                    $name = trim($parts[1]);
                    $id = trim($parts[2]);
                    $mapping[$id] = $name;
                }
            }
        }
    }
}

echo "\n=== Extracted Mapping ===\n";
print_r($mapping);
