<?php
$dirs = [
    __DIR__ . '/../uploads/letters/',
    __DIR__ . '/../uploads/explanations/',
    __DIR__ . '/../uploads/appeals/'
];
$targets = ['2023-184363', '2023-183482'];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    $files = scandir($dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'pdf') {
            $path = $dir . $file;
            $content = file_get_contents($path);
            foreach ($targets as $target) {
                if (strpos($content, $target) !== false) {
                    echo "Found target '$target' in $dir$file!\n";
                    preg_match_all('/\((.*?)\)/', $content, $matches);
                    foreach ($matches[1] as $m) {
                        if (trim($m) !== '') {
                            echo "  Text: $m\n";
                        }
                    }
                }
            }
        }
    }
}
exit;
