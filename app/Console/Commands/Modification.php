<?php

namespace Totocsa01\Rewriting\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class Modification extends Command
{
    private const DS = DIRECTORY_SEPARATOR;
    private const EXIT_CODE_VERSIONS_DIRECTORY_NOT_DIRECTORY = 3;
    private const EXIT_CODE_VERSIONS_DIRECTORY_NOT_READABLE = 4;
    private const EXIT_CODE_DIRECTORY_CREATION = 5;
    private const EXIT_CODE_NO_MATCHING_ORIGINAL_DIRECTORY = 6;
    private const EXIT_CODE_COPY_ERROR = 7;

    protected $signature = 'rewriting:modification'
        . ' {versions-dir : Versions base directory.}';

    protected $description = 'Modify the application files.';

    protected int $exitCode = Command::SUCCESS;
    protected array $exitMessages = [];
    private string $versions_dir;
    private string $appKey;

    public function handle(): int
    {
        $this->versions_dir = $this->argument('versions-dir');

        if (!is_dir($this->versions_dir)) {
            $this->exitCode = self::EXIT_CODE_VERSIONS_DIRECTORY_NOT_DIRECTORY;
            $this->exitMessages[] = ['error' => 'Versions directory is not a directory.'];
        } else if (!is_readable($this->versions_dir)) {
            $this->exitCode = self::EXIT_CODE_VERSIONS_DIRECTORY_NOT_DIRECTORY;
            $this->exitMessages[] = ['error' => 'Versions directory is not readable.'];
        }

        if (!$this->hasError()) {
            $versionDir = $this->findVersionDir();

            if ($versionDir != '') {
                $this->line("Versions dir: $versionDir");

                $this->copies($versionDir);

                if (!$this->hasError() && !empty($this->appKey)) {
                    $this->replacesInFile(base_path('.env'), ['[[APP_KEY]]' => $this->appKey]);
                }
            } else {
                $this->exitCode = self::EXIT_CODE_NO_MATCHING_ORIGINAL_DIRECTORY;
                $this->exitMessages[] = ['error' => 'There is no matching original directory.'];
            }
        }

        if ($this->hasError()) {
            $this->showError();
            return $this->exitCode;
        } else {
            return Command::SUCCESS;
        }
    }

    protected function setError(int $code, array $messages): void
    {
        if ($this->exitCode !== Command::SUCCESS) {
            return; // first error wins
        }

        $this->exitCode = $code;
        $this->exitMessages = $messages;
    }

    protected function hasError(): bool
    {
        return $this->exitCode !== Command::SUCCESS;
    }

    protected function showError(): void
    {
        foreach ($this->exitMessages as $v) {
            foreach ($v as $type => $msg) {

                $this->{$type}($msg);
            }
        }
    }

    protected function replacesInFile(string $filename, array $rules): void
    {
        $content = file_get_contents($filename);
        $content = strtr($content, $rules);

        file_put_contents($filename, $content);
    }

    /**
     *
     * @return string
     */
    protected function findVersionDir(): string
    {
        $versionDir = '';
        if (!$this->hasError()) {
            $files = array_diff(scandir($this->versions_dir, SCANDIR_SORT_DESCENDING), ['.', '..']);

            reset($files);
            $fi = current($files);
            $allSame = false;
            while ($fi !== false && $allSame === false) {
                $dir = $this->versions_dir . self::DS . $fi;

                if (is_dir($dir) && preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/', $fi) === 1) {
                    $allSame = $this->verification($fi, $dir);
                    if ($allSame) {
                        $versionDir = $fi;
                    }
                }

                next($files);
                $fi = current($files);
            }
        }

        return $versionDir;
    }

    protected function verification(string $version, string $versionDir): bool
    {
        $this->line("Verification. Version: $version");

        $originalDir = realpath($versionDir . self::DS . 'original');
        $allItems = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($originalDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $allSame = true;
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $subDir = substr($item->getRealPath(), strlen($originalDir));
                if ($subDir == self::DS . '.env') {
                    $appKeyPrefix = 'APP_KEY=';

                    $appContent = file_get_contents(base_path('.env'));
                    $appKeyAppLine = preg_match("/^{$appKeyPrefix}.*/m", $appContent, $m) ? $m[0] : null;

                    $originalContent = file_get_contents($item->getRealPath());
                    $originalAppKeyLine = preg_match("/^{$appKeyPrefix}.*/m", $originalContent, $m) ? $m[0] : null;

                    $appContent = str_replace($appKeyAppLine, $originalAppKeyLine, $appContent);
                    $this->appKey = substr($appKeyAppLine, strlen($appKeyPrefix));

                    file_put_contents(base_path('.env'), $appContent);
                }

                $compare = strcmp(file_get_contents($item->getRealPath()), file_get_contents(base_path($subDir)));
                if ($compare == 0) {
                    $allItems[$subDir] = 'Same';
                } else {
                    $allSame = false;
                    $allItems[$subDir] = 'Not same';
                }
            }
        }

        if ($allSame) {
            $modifiedDir = realpath($versionDir . self::DS . 'modified');
            $modifiedDirLen = strlen($modifiedDir);

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($modifiedDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                if ($item->isFile()) {
                    $relativePath = substr($item->getRealPath(), $modifiedDirLen);
                    $originalFile = realpath($versionDir . self::DS . 'original' . substr($item->getRealPath(), $modifiedDirLen));

                    if (!is_file($originalFile)) {
                        if (is_file(base_path($relativePath))) {
                            $allSame = false;
                            $allItems[$relativePath] = 'No original file';
                        } else {
                            $allItems[$relativePath] = 'New';
                        }
                    }
                }
            }
        }

        if ($this->option('verbose')) {
            ksort($allItems, SORT_STRING);

            foreach ($allItems as $k => $v) {
                if (in_array($v, ['Not same', 'No original file'])) {
                    $this->error("$v: $k");
                } else {
                    $this->info("$v: $k");
                }
            }

            $this->newLine();
        }


        return $allSame;
    }

    protected function copies(string $versionDir): bool
    {
        $modifiedDir = realpath($this->versions_dir . self::DS . $versionDir . self::DS . 'modified');
        $modifiedDirLen = strlen($modifiedDir);
        $this->line("Source: $versionDir");

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modifiedDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $pathSuffix = substr($item->getRealPath(), $modifiedDirLen);
                $target = base_path() . $pathSuffix;
                $targetDirArray = explode(self::DS, $target);
                array_pop($targetDirArray);
                $targetDir = implode(self::DS, $targetDirArray);

                if (!is_dir($targetDir)) {
                    if (@mkdir($targetDir, 0755, true)) {
                        $this->info("Directory created: $targetDir");
                    } else {
                        $error = error_get_last();

                        $this->setError(self::EXIT_CODE_DIRECTORY_CREATION, [
                            ['line' => $targetDir],
                            ['error' => 'Directory creation error: ' . ($error['message'] ?? 'unknown error')],
                        ]);

                        break;
                    }
                }

                if (!$this->hasError() && $this->copy($item->getPathname(), $target)) {
                    $this->info("Copied: $pathSuffix");
                };
            }
        }

        return $this->exitCode === self::SUCCESS;
    }

    protected function copy(string $from, string $to): bool
    {
        $isSuccess = @File::copy($from, $to);

        if (!$isSuccess) {
            $error = error_get_last();
            $this->setError(self::EXIT_CODE_COPY_ERROR, [
                ['line' => $to],
                ['error' => "Copy failed: " . ($error['message'] ?? 'unknown error')],
            ]);
        }

        return $isSuccess;
    }
}
