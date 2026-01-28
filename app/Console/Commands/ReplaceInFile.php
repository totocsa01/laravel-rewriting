<?php

namespace Totocsa01\Rewriting\app\Console\Commands;

use Illuminate\Console\Command;

class ReplaceInFile extends Command
{
    protected $signature  = 'rewriting:replaces-in-file {filename : The file to be modified.}';
    protected $description = 'Replacement in the file.';

    public function handle(): int
    {
        $replaces = config('rewriting');

        if (is_array($replaces) && count($replaces) > 0) {
            $filename = $this->argument('filename');

            if (is_file($filename)) {
                if (is_writable($filename)) {
                    $content = file_get_contents($filename);
                    $content = strtr($content, $replaces);
                    file_put_contents($filename, $content);

                    return Command::SUCCESS;
                } else {
                    $this->error(" $filename is not a writable. ");
                    return Command::FAILURE;
                }
            } else {
                $this->error(" $filename is not a file. ");
                return Command::FAILURE;
            }
        } else {
            $this->info('There are no replaces.');
            return Command::SUCCESS;
        }
    }
}
