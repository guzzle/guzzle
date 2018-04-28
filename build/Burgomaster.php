<?php

/**
 * Packages the zip and phar file using a staging directory.
 *
 * @license MIT, Michael Dowling https://github.com/mtdowling
 * @license https://github.com/mtdowling/Burgomaster/LICENSE
 */
class Burgomaster
{
    /** @var string Base staging directory of the project */
    public $stageDir;

    /** @var string Root directory of the project */
    public $projectRoot;

    /** @var array stack of sections */
    private $sections = array();

    /**
     * @param string $stageDir    Staging base directory where your packaging
     *                            takes place. This folder will be created for
     *                            you if it does not exist. If it exists, it
     *                            will be deleted and recreated to start fresh.
     * @param string $projectRoot Root directory of the project.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __construct($stageDir, $projectRoot = null)
    {
        $this->startSection('setting_up');
        $this->stageDir = $stageDir;
        $this->projectRoot = $projectRoot;

        if (!$this->stageDir || $this->stageDir == '/') {
            throw new \InvalidArgumentException('Invalid base directory');
        }

        if (is_dir($this->stageDir)) {
            $this->debug("Removing existing directory: $this->stageDir");
            echo $this->exec("rm -rf $this->stageDir");
        }

        $this->debug("Creating staging directory: $this->stageDir");

        if (!mkdir($this->stageDir, 0777, true)) {
            throw new \RuntimeException("Could not create {$this->stageDir}");
        }

        $this->stageDir = realpath($this->stageDir);
        $this->debug("Creating staging directory at: {$this->stageDir}");

        if (!is_dir($this->projectRoot)) {
            throw new \InvalidArgumentException(
                "Project root not found: $this->projectRoot"
            );
        }

        $this->endSection();
        $this->startSection('staging');

        chdir($this->projectRoot);
    }

    /**
     * Cleanup if the last section was not already closed.
     */
    public function __destruct()
    {
        if ($this->sections) {
            $this->endSection();
        }
    }

    /**
     * Call this method when starting a specific section of the packager.
     *
     * This makes the debug messages used in your script more meaningful and
     * adds context when things go wrong. Be sure to call endSection() when
     * you have finished a section of your packaging script.
     *
     * @param string $section Part of the packager that is running
     */
    public function startSection($section)
    {
        $this->sections[] = $section;
        $this->debug('Starting');
    }

    /**
     * Call this method when leaving the last pushed section of the packager.
     */
    public function endSection()
    {
        if ($this->sections) {
            $this->debug('Completed');
            array_pop($this->sections);
        }
    }

    /**
     * Prints a debug message to STDERR bound to the current section.
     *
     * @param string $message Message to echo to STDERR
     */
    public function debug($message)
    {
        $prefix = date('c') . ': ';

        if ($this->sections) {
            $prefix .= '[' . end($this->sections) . '] ';
        }

        fwrite(STDERR, $prefix . $message . "\n");
    }

    /**
     * Copies a file and creates the destination directory if needed.
     *
     * @param string $from File to copy
     * @param string $to   Destination to copy the file to, relative to the
     *                     base staging directory.
     * @throws \InvalidArgumentException if the file cannot be found
     * @throws \RuntimeException if the directory cannot be created.
     * @throws \RuntimeException if the file cannot be copied.
     */
    public function deepCopy($from, $to)
    {
        if (!is_file($from)) {
            throw new \InvalidArgumentException("File not found: {$from}");
        }

        $to = str_replace('//', '/', $this->stageDir . '/' . $to);
        $dir = dirname($to);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \RuntimeException("Unable to create directory: $dir");
            }
        }

        if (!copy($from, $to)) {
            throw new \RuntimeException("Unable to copy $from to $to");
        }
    }

    /**
     * Recursively copy one folder to another.
     *
     * Any LICENSE file is automatically copied.
     *
     * @param string $sourceDir  Source directory to copy from
     * @param string $destDir    Directory to copy the files to that is relative
     *                           to the the stage base directory.
     * @param array  $extensions File extensions to copy from the $sourceDir.
     *                           Defaults to "php" files only (e.g., ['php']).
     * @throws \InvalidArgumentException if the source directory is invalid.
     */
    public function recursiveCopy(
        $sourceDir,
        $destDir,
        $extensions = array('php')
    ) {
        if (!realpath($sourceDir)) {
            throw new \InvalidArgumentException("$sourceDir not found");
        }

        if (!$extensions) {
            throw new \InvalidArgumentException('$extensions is empty!');
        }

        $sourceDir = realpath($sourceDir);
        $exts = array_fill_keys($extensions, true);
        $iter = new \RecursiveDirectoryIterator($sourceDir);
        $iter = new \RecursiveIteratorIterator($iter);
        $total = 0;

        $this->startSection('copy');
        $this->debug("Starting to copy files from $sourceDir");

        foreach ($iter as $file) {
            if (isset($exts[$file->getExtension()])
                || $file->getBaseName() == 'LICENSE'
            ) {
                // Remove the source directory from the destination path
                $toPath = str_replace($sourceDir, '', (string) $file);
                $toPath = $destDir . '/' . $toPath;
                $toPath = str_replace('//', '/', $toPath);
                $this->deepCopy((string) $file, $toPath);
                $total++;
            }
        }

        $this->debug("Copied $total files from $sourceDir");
        $this->endSection();
    }

    /**
     * Execute a command and throw an exception if the return code is not 0.
     *
     * @param string $command Command to execute
     *
     * @return string Returns the output of the command as a string
     * @throws \RuntimeException on error.
     */
    public function exec($command)
    {
        $this->debug("Executing: $command");
        $output = $returnValue = null;
        exec($command, $output, $returnValue);

        if ($returnValue != 0) {
            throw new \RuntimeException('Error executing command: '
                . $command . ' : ' . implode("\n", $output));
        }

        return implode("\n", $output);
    }

    /**
     * Creates a class-map autoloader to the staging directory in a file
     * named autoloader.php
     *
     * @param array $files Files to explicitly require in the autoloader. This
     *                     is similar to Composer's "files" "autoload" section.
     * @param string $filename Name of the autoloader file.
     * @throws \RuntimeException if the file cannot be written
     */
    public function createAutoloader($files = array(), $filename = 'autoloader.php')
    {
        $sourceDir = realpath($this->stageDir);
        $iter = new \RecursiveDirectoryIterator($sourceDir);
        $iter = new \RecursiveIteratorIterator($iter);

        $this->startSection('autoloader');
        $this->debug('Creating classmap autoloader');
        $this->debug("Collecting valid PHP files from {$this->stageDir}");

        $classMap = array();
        foreach ($iter as $file) {
            if ($file->getExtension() == 'php') {
                $location = str_replace($this->stageDir . '/', '', (string) $file);
                $className = str_replace('/', '\\', $location);
                $className = substr($className, 0, -4);

                // Remove "src\" or "lib\"
                if (strpos($className, 'src\\') === 0
                    || strpos($className, 'lib\\') === 0
                ) {
                    $className = substr($className, 4);
                }

                $classMap[$className] = "__DIR__ . '/$location'";
                $this->debug("Found $className");
            }
        }

        $destFile = $this->stageDir . '/' . $filename;
        $this->debug("Writing autoloader to {$destFile}");

        if (!($h = fopen($destFile, 'w'))) {
            throw new \RuntimeException('Unable to open file for writing');
        }

        $this->debug('Writing classmap files');
        fwrite($h, "<?php\n\n");
        fwrite($h, "\$mapping = array(\n");
        foreach ($classMap as $c => $f) {
            fwrite($h, "    '$c' => $f,\n");
        }
        fwrite($h, ");\n\n");
        fwrite($h, <<<EOT
spl_autoload_register(function (\$class) use (\$mapping) {
    if (isset(\$mapping[\$class])) {
        require \$mapping[\$class];
    }
}, true);

EOT
        );

        fwrite($h, "\n");

        $this->debug('Writing automatically included files');
        foreach ($files as $file) {
            fwrite($h, "require __DIR__ . '/$file';\n");
        }

        fclose($h);

        $this->endSection();
    }

    /**
     * Creates a default stub for the phar that includeds the generated
     * autoloader.
     *
     * This phar also registers a constant that can be used to check if you
     * are running the phar. The constant is the basename of the $dest variable
     * without the extension, with "_PHAR" appended, then converted to all
     * caps (e.g., "/foo/guzzle.phar" gets a contant defined as GUZZLE_PHAR.
     *
     * @param $dest
     * @param string $autoloaderFilename Name of the autoloader file.
     *
     * @return string
     */
    private function createStub($dest, $autoloaderFilename = 'autoloader.php')
    {
        $this->startSection('stub');
        $this->debug("Creating phar stub at $dest");

        $alias = basename($dest);
        $constName = str_replace('.phar', '', strtoupper($alias)) . '_PHAR';
        $stub  = "<?php\n";
        $stub .= "define('$constName', true);\n";
        $stub .= "require 'phar://$alias/{$autoloaderFilename}';\n";
        $stub .= "__HALT_COMPILER();\n";
        $this->endSection();

        return $stub;
    }

    /**
     * Creates a phar that automatically registers an autoloader.
     *
     * Call this only after your staging directory is built.
     *
     * @param string $dest Where to save the file. The basename of the file
     *     is also used as the alias name in the phar
     *     (e.g., /path/to/guzzle.phar => guzzle.phar).
     * @param string|bool|null $stub The path to the phar stub file. Pass or
     *      leave null to automatically have one created for you. Pass false
     *      to no use a stub in the generated phar.
     * @param string $autoloaderFilename Name of the autolaoder filename.
     */
    public function createPhar(
        $dest,
        $stub = null,
        $autoloaderFilename = 'autoloader.php'
    ) {
        $this->startSection('phar');
        $this->debug("Creating phar file at $dest");
        $this->createDirIfNeeded(dirname($dest));
        $phar = new \Phar($dest, 0, basename($dest));
        $phar->buildFromDirectory($this->stageDir);

        if ($stub !== false) {
            if (!$stub) {
                $stub = $this->createStub($dest, $autoloaderFilename);
            }
            $phar->setStub($stub);
        }

        $this->debug("Created phar at $dest");
        $this->endSection();
    }

    /**
     * Creates a zip file containing the staged files of your project.
     *
     * Call this only after your staging directory is built.
     *
     * @param string $dest Where to save the zip file
     */
    public function createZip($dest)
    {
        $this->startSection('zip');
        $this->debug("Creating a zip file at $dest");
        $this->createDirIfNeeded(dirname($dest));
        chdir($this->stageDir);
        $this->exec("zip -r $dest ./");
        $this->debug("  > Created at $dest");
        chdir(__DIR__);
        $this->endSection();
    }

    private function createDirIfNeeded($dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Could not create dir: $dir");
        }
    }
}
