<?php

namespace Nwidart\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nwidart\Modules\ModuleManifest;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Remove the compiled module manifest cache file.
 *
 * Run this after adding, removing, or renaming modules so that the next
 * request or artisan command rebuilds the manifest from the filesystem.
 * Pairing this with `module:cache` gives you the same cache/clear lifecycle
 * as `route:cache` / `route:clear` and `config:cache` / `config:clear`.
 *
 * Usage:
 *   php artisan module:clear          # delete compiled manifest
 *   php artisan module:cache          # recompile
 *
 * @see \Nwidart\Modules\Commands\ModuleCacheCommand
 */
#[AsCommand(name: 'module:clear')]
class ModuleClearCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'module:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove the module manifest cache file';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $files, ModuleManifest $manifest): int
    {
        $manifestPath = $manifest->manifestPath;

        if ($manifestPath && $files->exists($manifestPath)) {
            $files->delete($manifestPath);
        }

        // Reset in-memory cache so the current process picks up the cleared state
        $manifest->resetManifest();

        $this->components->info('Module cache cleared successfully.');

        return self::SUCCESS;
    }
}
