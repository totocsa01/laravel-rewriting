<?php

namespace Totocsa01\Rewriting\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class RewritingConfig extends Command
{
    private const DS = DIRECTORY_SEPARATOR;
    private const EXIT_CODE_MODIFIED_APPLICATION = 3;
    private const EXIT_CODE_DIRECTORY_CREATION = 4;
    private const EXIT_CODE_NO_MATCHING_ORIGINAL_DIRECTORY = 5;
    private const EXIT_CODE_COPY_ERROR = 6;
    private const EXIT_CODE_VALIDATE_ERROR = 7;

    protected $signature = 'rewriting:config'
        . ' {--ni : Non-interactive.}'
        . ' {--versions_base_directory=[[versions_base_directory]] : Versions base directory.}'
        . ' {--app_name=[[app_name]] : Application name.}'
        . ' {--app_protocol=[[app_protocol]] : Application protocol. Valid values: https or http.}'
        . ' {--app_host=[[app_host]] : Application host.}'
        . ' {--app_domain=[[app_domain]] : Application domain.}'
        . ' {--app_path=[[app_path]] : Application path.}'
        . ' {--vite_port=[[vite_port]] : Vite port. Valid values: 10000-65535.}'
        . ' {--vite_strictport=[[vite_strictport]] : Vite strictport. Valid values: true or false }'
        . ' {--vite_https_key=[[vite_https_key]] : Vite https key. Path of the key file.}'
        . ' {--vite_https_cert=[[vite_https_cert]] : Vite https cert. Path of the cert file.}'
        . ' {--xdebug_port=[[xdebug_port]] : Xdebug port. Valid values: 10000-65535.}';

    protected $description = 'Modify the application';

    private array $optionsDefault = [
        'versions_base_directory' => '',
        'app_name' => '',
        'app_protocol' => 'https',
        'app_host' => '',
        'app_domain' => '',
        'app_path' => '/',
        'vite_port' => '',
        'vite_strictport' => 'true',
        'vite_https_key' => '',
        'vite_https_cert' => '',
        'xdebug_port' => '',
    ];

    //protected array $projectEnvs;
    protected int $exitCode = Command::SUCCESS;
    protected array $exitMessages = [];
    private string $versions_base_directory;
    private string $app_name;
    private string $app_protocol;
    private string $app_host;
    private string $app_domain;
    private string $app_path;
    private string $appKey;
    private string $vite_port;
    private string $vite_strictport;
    private string $vite_https_key;
    private string $vite_https_cert;
    private string $xdebug_port;

    public function __construct()
    {
        $config = config('rewriting');

        if (!empty($config)) {
            foreach ($this->optionsDefault as $k => $v) {
                if (isset($config[$k])) {
                    $this->optionsDefault[$k] = (string) $config[$k];
                }
            }
        }

        foreach ($this->optionsDefault as $k => $v) {
            $this->signature = strtr($this->signature, ["[[$k]]" => $v]);
        }

        parent::__construct();
    }

    public function handle(): int
    {
        config(['rewriting.xdebug_port' => '2346']);
        var_dump(config('rewriting'));
        return 0;
        $this->checkModifiedApplication();

        if (!$this->hasError()) {
            //$this->setProjectEnvs();

            if ($this->option('ni')) {
                $this->setByOptions();
            } else {
                $this->getMissingOptions();
            }

            $validator = $this->validatorOptions();

            if ($validator->passes()) {
                $config = config('rewriting');
                foreach (array_keys($config) as $v) {
                    config(["rewriting.$v" => $this->{$v}]);
                }
            } else {
                $this->exitCode = self::EXIT_CODE_VALIDATE_ERROR;
                $this->exitMessages[] = ['error' => 'Validation error.'];

                foreach ($validator->errors()->all() as $message) {
                    $this->exitMessages[] = ['line' => $message];
                }
            }
        }

        if ($this->hasError()) {
            $this->showError();
            return $this->exitCode;
        } else {
            return Command::SUCCESS;
        }
    }

    protected function doIt(string $versionDir): void
    {
        $this->copies($versionDir);

        /*if (!$this->hasError()) {
            $this->replacesInFile(base_path('../../.env'), [
                '[[COMPOSE_PROJECT_NAME]]' => $this->getComposeProjectName(),
                '[[APP_HOST]]' => $this->app_host,
                '[[APP_DOMAIN]]' => $this->app_domain,
                '[[APP_PATH]]' => $this->app_path,
                '[[XDEBUG_PORT]]' => $this->xdebug_port,
            ]);
        }

        if (!$this->hasError()) {
            $this->replacesInFile(base_path('.env'), [
                '[[PROJECT_BASE_PATH]]' => $this->projectEnvs['PROJECT_BASE_PATH'],
                '[[APP_NAME]]' => $this->app_name,
                '[[APP_URL]]' => "{$this->app_protocol}://{$this->app_host}.{$this->app_domain}{$this->app_path}",
                '[[APP_KEY]]' => $this->appKey,
                '[[VITE_PROTOCOL]]' => $this->app_protocol,
                '[[VITE_HOST]]' => "{$this->app_host}.{$this->app_domain}",
                '[[VITE_PORT]]' => $this->vite_port,
                '[[VITE_STRICTPORT]]' => $this->vite_strictport,
                '[[VITE_HTTPS_KEY]]' => $this->vite_https_key,
                '[[VITE_HTTPS_CERT]]' => $this->vite_https_cert,
            ]);
        }

        if (!$this->hasError()) {
            $this->replacesInFile(base_path('.vscode/launch.json'), [
                '[[URL]]' => "{$this->app_protocol}://{$this->app_host}.{$this->app_domain}{$this->app_path}",
                '[[XDEBUG_PORT]]' => $this->xdebug_port,
                '[[PROJECT_BASE_PATH]]' => $this->projectEnvs['PROJECT_BASE_PATH'],
            ]);
        }*/
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

    protected function checkModifiedApplication(): void
    {
        $base_path = base_path();
        $env = File::lastModified("$base_path/.env");
        $database = File::lastModified("$base_path/database");
        $diff = abs($env - $database);

        if (0 && $diff > 2) {
            $this->setError(self::EXIT_CODE_MODIFIED_APPLICATION, [
                ['line' => 'More than 2 seconds have passed between the last modification of the .env file and the database directory.'],
                ['error' => 'The operation cannot be performed.'],
            ]);
        }
    }

    /**
     *
     * @return string
     */
    protected function findVersionDir(): string
    {
        $versionDir = '';
        if (!$this->hasError()) {
            $files = array_diff(scandir($this->versions_base_directory, SCANDIR_SORT_DESCENDING), ['.', '..']);

            reset($files);
            $fi = current($files);
            $allSame = false;
            while ($fi !== false && $allSame === false) {
                $dir = $this->versions_base_directory . self::DS . $fi;

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
        $this->line("Verification: $version");

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

        ksort($allItems, SORT_STRING);

        foreach ($allItems as $k => $v) {
            if (in_array($v, ['Not same', 'No original file'])) {
                $this->error("$v: $k");
            } else {
                $this->info("$v: $k");
            }
        }

        $this->newLine();
        return $allSame;
    }

    protected function copies(string $versionDir): bool
    {
        $modifiedDir = realpath($this->versions_base_directory . self::DS . $versionDir . self::DS . 'modified');
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

    protected function getComposeProjectName(): string
    {
        $host = str_replace('_', '-', $this->app_host);
        $domain = str_replace('_', '-', $this->app_domain);
        $path = str_replace('_', '-', $this->app_path);
        $path = str_replace('/', '-', $this->app_path);

        $name = "{$host}_{$domain}"
            . ($path == '-' ? '' : '_' . substr($path, 1));

        $name = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name));
        $name = preg_replace('/^[^a-z0-9]+|[^a-z0-9_-]+/', '-', strtolower($name));
        $this->info('Név: ' . $name);
        return $name;
    }

    /*    protected function setProjectEnvs(): void
    {
        $this->projectEnvs = [];
        $allLines = explode("\n", file_get_contents(base_path('../../.env')));
        foreach ($allLines as $line) {
            $pos = strpos($line, '=');
            if ($pos !== false) {
                $this->projectEnvs[substr($line, 0, $pos)] = substr($line, $pos + 1);
            }
        }
    }*/

    protected function replacesInFile(string $filename, array $rules): void
    {
        $content = file_get_contents($filename);
        $content = strtr($content, $rules);

        file_put_contents($filename, $content);
    }

    protected function setByOptions(): void
    {
        $options = [
            'versions_base_directory',
            'app_name',
            'app_protocol',
            'app_host',
            'app_domain',
            'app_path',
            'vite_port',
            'vite_strictport',
            'vite_https_key',
            'vite_https_cert',
            'xdebug_port',
        ];

        foreach ($options as $v) {
            $this->{$v} = $this->option($v) ?: '';
        }
    }

    protected function getMissingOptions(): void
    {
        if (!$this->hasError()) {
            $nonInteractive = $this->option('ni');
            $rules = $this->rules();

            $opt = 'versions_base_directory';
            $label = 'Versions base directory';
            $this->{$opt} = $nonInteractive ? '' : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'app_name';
            $label = 'Application name';
            $this->{$opt} = $nonInteractive ? '' : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'app_protocol';
            $label = 'Application protocol';
            $this->{$opt} = $nonInteractive ? $this->option($opt) : select(
                label: $label,
                options: ['https', 'http'],
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'app_host';
            $label = 'Application host';
            $this->{$opt} = $nonInteractive ? '' : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'app_domain';
            $label = 'Application domain';
            $this->{$opt} = $nonInteractive ? '' : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'app_path';
            $this->{$opt} = $this->option($opt)[0] == '/' ? $this->option($opt) : '/' . $this->option($opt);
            $label = 'Application path';
            $this->{$opt} = $nonInteractive ? $this->option($opt) : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );
            $this->{$opt} = $this->{$opt}[0] == '/' ? $this->{$opt} : '/' . $this->{$opt};

            $opt = 'vite_port';
            $label = 'Vite port';
            $this->{$opt} = $nonInteractive ? '' : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'vite_strictport';
            $label = 'Vite strictport';
            $this->{$opt} = $nonInteractive ? $this->option($opt) : select(
                label: $label,
                options: ['true', 'false'],
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'vite_https_key';
            $label = 'Vite https key';
            $this->{$opt} = $nonInteractive ? $this->option($opt) : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'vite_https_cert';
            $label = 'Vite https cert';
            $this->{$opt} = $nonInteractive ? $this->option($opt) : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: $this->optionsDefault[$opt],
            );

            $opt = 'xdebug_port';
            $label = 'Xdebug port';
            $this->{$opt} = $nonInteractive ? '' : text(
                label: $label,
                validate: $rules[$opt],
                default: $this->option($opt) ?: (empty($this->optionsDefault[$opt]) ? $this->vite_port + 1 : $this->optionsDefault[$opt]),
            );
        }
    }

    protected function rules(): array
    {
        return [
            'versions_base_directory' => ['required', 'string', 'min:1', 'max:4096'],
            'app_name' => ['required', 'string', 'min:3', 'max:255'],
            'app_protocol' => ['required', 'in:http,https'],
            'app_host' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[A-Za-z0-9._-]*[A-Za-z0-9]$/'],
            'app_domain' => ['required', 'string', 'min:1', 'max:255', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            'app_path' => ['required', 'string', 'min:1', 'max:255'],
            'vite_port' => ['required', 'integer', 'min:10000', 'max:65535'],
            'vite_strictport' => ['required',  'in:true,false'],
            'vite_https_key' => ['required', 'string', 'min:0', 'max:255'],
            'vite_https_cert' => ['required', 'string', 'min:0', 'max:255'],
            'xdebug_port' => ['required', 'integer', 'min:10000', 'max:65535'],
        ];
    }

    protected function validatorOptions(): \Illuminate\Validation\Validator
    {
        $data = [
            'versions_base_directory' => $this->versions_base_directory,
            'app_name' => $this->app_name,
            'app_protocol' => $this->app_protocol,
            'app_host' => $this->app_host,
            'app_domain' => $this->app_domain,
            'app_path' => $this->app_path,
            'vite_port' => $this->vite_port,
            'vite_strictport' => $this->vite_strictport,
            'vite_https_key' => $this->vite_https_key,
            'vite_https_cert' => $this->vite_https_cert,
            'xdebug_port' => $this->xdebug_port,
        ];

        return Validator::make($data, $this->rules());
    }
}
