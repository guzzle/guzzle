<?php

/**
 * Packages the zip and phar file using a staging directory.
 */
class Packager
{
    public $baseDir;

    /**
     * @param string $baseDir Staging base directory
     */
    public function __construct($baseDir)
    {
        $this->baseDir = $baseDir;
        $this->debug("Creating staging directory at: $this->baseDir");
        $this->debug("  Cleaning $this->baseDir");
        exec("rm -rf $this->baseDir");
        mkdir($this->baseDir);
        chdir(__DIR__ . '/..');
    }

    /**
     * Prints a debug messge
     *
     * @param $message
     */
    public function debug($message)
    {
        fwrite(STDERR, $message . "\n");
    }

    /**
     * Copies a file and any necessary directories.
     *
     * @param $from
     * @param $to
     */
    public function deepCopy($from, $to)
    {
        $dir = dirname($to);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        copy($from, $to);
    }

    /**
     * Recursively copy a dependency to a subfolder.
     *
     * @param string $fromDir    Directory of the dependency
     * @param string $baseDir    Base directory to remove from the path
     * @param array  $extensions File extensions to copy
     */
    function recursiveCopy(
        $fromDir,
        $baseDir,
        $extensions = ['php', 'pem']
    ) {
        // File extensions added to the output
        $exts = array_fill_keys($extensions, true);
        $fromDir = realpath($fromDir);
        $iter = new RecursiveDirectoryIterator($fromDir);
        $iter = new RecursiveIteratorIterator($iter);
        $this->debug("    > From $fromDir");
        $total = 0;

        foreach ($iter as $file) {
            if (isset($exts[$file->getExtension()])
                || $file->getBaseName() == 'LICENSE'
            ) {
                $toPath = str_replace($fromDir, '', (string) $file);
                $toPath = $baseDir . '/' . $toPath;
                $this->deepCopy((string) $file, $toPath);
                $total++;
            }
        }

        $this->debug("      Copied $total files");
    }

    /**
     * Creates a class-map autoloader to the staging directory in a file
     * named autoloader.php
     *
     * @param array $files Files to explicitly require in the autoloader
     */
    function createAutoloader($files = [])
    {
        $fromDir = realpath($this->baseDir);
        $iter = new RecursiveDirectoryIterator($fromDir);
        $iter = new RecursiveIteratorIterator($iter);
        $this->debug("  Creating classmap autoloader");

        $classMap = [];
        foreach ($iter as $file) {
            if ($file->getExtension() == 'php') {
                $location = str_replace($this->baseDir . '/', '', (string) $file);
                $className = str_replace('/', '\\', $location);
                $className = substr($className, 0, -4);
                $classMap[$className] = "__DIR__ . '/$location'";
            }
        }

        $h = fopen($this->baseDir . '/autoloader.php', 'w');
        fwrite($h, "<?php\n\n");
        fwrite($h, "\$mapping = [\n");
        foreach ($classMap as $c => $f) {
            fwrite($h, "    '$c' => $f,\n");
        }
        fwrite($h, "];\n\n");
        fwrite($h, <<<EOT
spl_autoload_register(function (\$class) use (\$mapping) {
    if (isset(\$mapping[\$class])) {
        include \$mapping[\$class];
        return true;
    }
}, true);

EOT
        );

        fwrite($h, "\n");

        foreach ($files as $file) {
            fwrite($h, "require __DIR__ . '/$file';\n");
        }

        fclose($h);
    }

    /**
     * Creates a default stub for the phar.
     *
     * @param $dest
     *
     * @return string
     */
    private function createStub($dest)
    {
        $alias = basename($dest);
        $project = str_replace('.phar', '', strtoupper($alias));

        $stub  = "<?php\n";
        $stub .= "define('$project', true);\n";
        $stub .= "require 'phar://$alias/autoloader.php';\n";
        $stub .= "__HALT_COMPILER();\n";

        return $stub;
    }

    /**
     * Creates a phar
     *
     * @param string $dest Where to save the file. The basename of the file is
     *                     used as the alias name in the phar.
     * @param null   $stub The path to the phar stub file.
     */
    public function createPhar($dest, $stub = null)
    {
        $this->debug('Creating phar file');
        $phar = new Phar($dest, 0, basename($dest));
        $phar->buildFromDirectory($this->baseDir);

        if (!$stub) {
            $stub = $this->createStub($dest);
        }

        $phar->setStub($stub);
        $this->debug("  > Created at $dest");
    }

    /**
     * Creates a zip file containing the staging files and an autoloader.
     *
     * @param string $dest Where to save the zip file
     */
    public function createZip($dest)
    {
        $this->debug('Creating zip file');
        chdir($this->baseDir);
        exec("zip -r $dest ./");
        $this->debug("  > Created at $dest");
        chdir(__DIR__);
    }
}

$packager = new Packager(
    realpath(__DIR__ . '/..') . '/build/artifacts/staging'
);

/**** Do the actual Guzzle specific packaging ****/
$packager->debug("  Copying meta-files to $packager->baseDir");
foreach (['README.md', 'LICENSE'] as $file) {
    $packager->deepCopy($file, $packager->baseDir . '/' . $file);
}

$packager->debug("  Copying source files");
$packager->recursiveCopy('src', $packager->baseDir . '/GuzzleHttp');
$packager->recursiveCopy(
    'vendor/guzzlehttp/streams/src',
    $packager->baseDir . '/GuzzleHttp/Stream'
);

$packager->debug("  Created staging directory at: $packager->baseDir");
/**** Do the actual Guzzle specific packaging ****/

$packager->createAutoloader(['GuzzleHttp/functions.php']);
$packager->createPhar(__DIR__ . '/artifacts/guzzle.phar');
$packager->createZip(__DIR__ . '/artifacts/guzzle.zip');
