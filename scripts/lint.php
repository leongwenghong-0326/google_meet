<?php
$rootDir = dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir));
$errorCount = 0;
$checkedCount = 0;

foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getRealPath();
        $output = [];
        $returnVar = 0;
        exec("php -l \"$path\"", $output, $returnVar);
        if ($returnVar !== 0) {
            echo "ERROR in $path: " . implode("\n", $output) . "\n";
            $errorCount++;
        } else {
            $checkedCount++;
        }
    }
}

echo "Linted $checkedCount PHP files across the entire project with $errorCount errors.\n";
if ($errorCount > 0) exit(1);
