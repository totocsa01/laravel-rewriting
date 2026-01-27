<?php

namespace Totocsa01\Rewriting\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ReplaceInFile extends Command
{
    protected $signature  = 'rewriting:replace-in-file {filename : The file to be modified.}';
    protected $description = 'Modify a file.';

    public function handle(): int
    {
        $osEnvs = getenv();
        $projectEnvs = $this->getEnvsFromFile(base_path('../../.env'));
        $appEnvs = $this->getEnvsFromFile(base_path('.env'));
        $rewritingEnvs = $this->getRewritingEnvs();

        $allEnvs = $this->envsMerge([
            'os' => $osEnvs,
            'project' => $projectEnvs,
            'app' => $appEnvs,
            'rewriting' => $rewritingEnvs,
        ]);

        $allEnvsOK = true;
        foreach ($allEnvs as $k1 => $v1) {
            if (gettype($v1) === 'array') {
                $allEnvsOK = false;
                $this->line("<error> Error: </error> $k1 is unclear.");
                foreach ($v1 as $k2 => $v2) {
                    $this->line("$k2: '$v2'");
                }
            }
        }

        if ($allEnvsOK) {
            $filename = $this->argument('filename');

            if (is_file($filename)) {
                if (is_writable($filename)) {
                    $rules = [];
                    foreach ($allEnvs as $k1 => $v1) {
                        $rules["[[$k1]]"] = $v1;
                    }

                    $content = file_get_contents($filename);
                    $content = strtr($content, $rules);
                    file_put_contents($filename, $content);

                    return Command::SUCCESS;
                } else {
                    $this->error(" $filename is not a writable. ");
                    return Command::FAILURE;
                }

                $this->replacesInFile($filename, $rules);
            } else {
                $this->error(" $filename is not a file. ");
                return Command::FAILURE;
            }
        } else {
            return Command::FAILURE;
        }
    }

    protected function envsMerge(array $envs): array
    {
        $merged = [];

        foreach ($envs as $k1 => $v1) {
            foreach ($v1 as $k2 => $v2) {
                if (!in_array($v2, ["[[$k2]]", "\"[[$k2]]\""])) {
                    $merged[$k2][$k1] = $v2;
                }
            }
        }

        foreach ($merged as $k1 => $v1) {
            if (count($v1) === 1) {
                $merged[$k1] = $v1[key($v1)];
            } else {
                $allSame = true;
                $v3 = null;
                foreach ($v1 as $k2 => $v2) {
                    if (is_null($v3)) {
                        $v3 = $v2;
                    } else {
                        $same = $v2 === $v3
                            || "\"$v2\"" === $v3
                            || $v2 === "\"$v3\""
                            || preg_match('/^["]{0,1}[\[]{2}[0-9A-Z_]+[\]]{2}["]{0,1}$/', $v2) === 1;

                        if (!$same) {
                            $shellExec = shell_exec("printf $v2");
                            $same = $v3 === $shellExec;
                        }

                        $allSame = $allSame && $same;
                    }
                }

                if ($allSame) {
                    $merged[$k1] = env($k1);
                }
            }
        }

        $merged['COMPOSE_PROJECT_NAME'] = $this->getComposeProjectName($merged);
        $merged['APP_URL'] = "{$merged['APP_PROTOCOL']}://{$merged['APP_HOST']}.{$merged['APP_DOMAIN']}{$merged['APP_PATH']}";
        $merged['VITE_HOST'] = "{$merged['APP_HOST']}.{$merged['APP_DOMAIN']}";
        $merged['VITE_PROTOCOL'] = $merged['APP_PROTOCOL'];

        return $merged;
    }

    protected function getEnvsFromFile(string $filename): array
    {
        $values = [];
        $allLines = explode("\n", file_get_contents($filename));
        foreach ($allLines as $line) {
            $pos = strpos($line, '=');
            if ($pos !== false) {
                $values[substr($line, 0, $pos)] = substr($line, $pos + 1);
            }
        }

        return $values;
    }

    protected function getRewritingEnvs(): array
    {
        $rewritingEnvs = [];
        foreach (config('rewriting') as $k => $v) {
            $rewritingEnvs[Str::upper($k)] = $v;
        }

        return $rewritingEnvs;
    }

    protected function getComposeProjectName(array $envs): string
    {
        $host = str_replace('_', '-', $envs['APP_HOST']);
        $domain = str_replace('_', '-', $envs['APP_DOMAIN']);
        $path = str_replace('_', '-', $envs['APP_PATH']);
        $path = str_replace('/', '-', $envs['APP_PATH']);

        $name = "{$host}_{$domain}"
            . ($path == '-' ? '' : '_' . substr($path, 1));

        $name = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name));
        $name = preg_replace('/^[^a-z0-9]+|[^a-z0-9_-]+/', '-', strtolower($name));

        return $name;
    }

    protected function replacesInFile(string $filename, array $rules): void
    {
        $content = file_get_contents($filename);
        $content = strtr($content, $rules);
        file_put_contents($filename, $content);
    }
}
