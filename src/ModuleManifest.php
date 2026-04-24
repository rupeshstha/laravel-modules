<?php

namespace Nwidart\Modules;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

class ModuleManifest
{
    /**
     * The filesystem instance.
     */
    private Filesystem $files;

    /**
     * The base path.
     */
    public Collection $paths;

    /**
     * The manifest path.
     */
    public ?string $manifestPath;

    /**
     * The loaded manifest array.
     */
    public ?array $manifest;

    /**
     * Instance-level cache for module manifest data (provider class names).
     *
     * Intentionally NOT static. See FileRepository::$scannedModules for
     * the full rationale. In short: instance scope prevents Octane worker
     * cross-contamination, test pollution, and artisan command bleed-through.
     *
     * Lifecycle: populated once per build() call, reset by resetManifest().
     *
     * @var Collection|null
     */
    private ?Collection $manifestData = null;

    public function __construct(Filesystem $files, ?string $manifestPath)
    {
        $this->files = $files;
        $this->manifestPath = $manifestPath;
        $this->manifest = null;
        $this->paths = new Collection;
    }

    /**
     * Get all of the service provider class names for all modules.
     */
    public function getProviders(): Collection
    {
        return $this->getModulesData()->flatMap(function (array $configuration) {
            return (array) ($configuration['providers'] ?? []);
        });
    }

    /**
     * Build the manifest and write it to disk.
     */
    public function build(): void
    {
        $modules = $this->getModulesData();

        $this->write($modules);
    }

    /**
     * Register the files defined in the manifest.
     */
    public function registerFiles(): void
    {
        $this->getModulesData()->each(function ($module) {
            foreach ((array) ($module['files'] ?? []) as $file) {
                require_once $file;
            }
        });
    }

    /**
     * Get the current manifest data.
     *
     * Returns the instance-level cache on repeated calls (warm path = array_get,
     * no I/O). On the first call for this instance it either reads the compiled
     * manifest file (bootstrap/cache/modules.php) or globs the filesystem.
     *
     * No runningUnitTests() guard is needed: because this is instance state,
     * each test that re-binds the container or creates a fresh ModuleManifest
     * gets a clean cache automatically.
     *
     * @return Collection<string, array>
     */
    public function getModulesData(): Collection
    {
        if ($this->manifestData !== null) {
            return $this->manifestData;
        }

        if (is_file($this->manifestPath)) {
            $this->manifestData = collect(require $this->manifestPath);

            return $this->manifestData;
        }

        $this->manifestData = $this->getModulesDataFromFilesystem();

        return $this->manifestData;
    }

    /**
     * Get the manifest data by scanning the filesystem.
     *
     * @return Collection<string, array>
     */
    private function getModulesDataFromFilesystem(): Collection
    {
        $data = [];

        foreach ($this->paths as $path) {
            $manifests = $this->files->glob("{$path}/*/module.json");

            if (! is_array($manifests)) {
                continue;
            }

            foreach ($manifests as $manifest) {
                $moduleData = json_decode($this->files->get($manifest), true);
                if (! is_array($moduleData)) {
                    continue;
                }
                $name = $moduleData['name'] ?? basename(dirname($manifest));
                $data[strtolower($name)] = $moduleData;
            }
        }

        return collect($data);
    }

    /**
     * Reset the instance-level manifest cache.
     *
     * Call this after writing a new manifest file (module:cache) so the
     * next getModulesData() call reloads from disk.
     */
    public function resetManifest(): void
    {
        $this->manifestData = null;
        $this->manifest = null;
    }

    /**
     * Write the given manifest array to disk.
     */
    private function write(Collection $manifest): void
    {
        if (! is_writable($directory = dirname($this->manifestPath))) {
            throw new \RuntimeException("The {$directory} directory must be present and writable.");
        }

        $this->files->replace(
            $this->manifestPath,
            '<?php return ' . var_export($manifest->all(), true) . ';' . PHP_EOL
        );
    }
}
