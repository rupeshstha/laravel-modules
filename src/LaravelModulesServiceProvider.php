<?php

namespace Nwidart\Modules;

use Composer\InstalledVersions;
use Illuminate\Contracts\Translation\Translator as TranslatorContract;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Blade;
use Illuminate\Translation\Translator;
use Nwidart\Modules\Commands\ModuleCacheCommand;
use Nwidart\Modules\Commands\ModuleClearCommand;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Exceptions\InvalidActivatorClass;
use Nwidart\Modules\Facades\Module;
use Nwidart\Modules\Support\Stub;

class LaravelModulesServiceProvider extends ModulesServiceProvider
{
    /**
     * Booting the package.
     */
    public function boot()
    {
        $this->registerNamespaces();
        $this->registerModules();
    }

    /**
     * Register the package.
     */
    public function register()
    {
        $this->registerServices();
        $this->setupStubPath();
        $this->registerProviders();

        $this->registerMigrations();
        $this->registerTranslations();

        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'modules');

        $this->registerModules();
    }

    /**
     * Setup stub path.
     */
    public function setupStubPath()
    {
        $path = $this->app['config']->get('modules.stubs.path') ?? __DIR__ . '/Commands/stubs';
        Stub::setBasePath($path);

        $this->app->booted(function ($app) {
            /** @var RepositoryInterface $moduleRepository */
            $moduleRepository = $app[RepositoryInterface::class];
            if ($moduleRepository->config('stubs.enabled') === true) {
                Stub::setBasePath($moduleRepository->config('stubs.path'));
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function registerServices()
    {
        $this->app->singleton(RepositoryInterface::class, function ($app) {
            $path = $app['config']->get('modules.paths.modules');

            return new Laravel\LaravelFileRepository($app, $path);
        });
        $this->app->singleton(ActivatorInterface::class, function ($app) {
            $activator = $app['config']->get('modules.activator');
            $class = $app['config']->get('modules.activators.' . $activator)['class'];

            if ($class === null) {
                throw InvalidActivatorClass::missingConfig();
            }

            return new $class($app);
        });
        $this->app->alias(RepositoryInterface::class, 'modules');

        $this->app->singleton(
            ModuleManifest::class,
            function ($app) {
                /** @var RepositoryInterface $repository */
                $repository = $app[RepositoryInterface::class];

                $manifest = new ModuleManifest(
                    new Filesystem,
                    $this->getCachedModulePath()
                );

                // Populate the scan paths from the repository so the manifest
                // knows where to glob when the compiled cache is absent.
                $manifest->paths = collect($repository->getScanPaths());

                return $manifest;
            }
        );

        // Register the module:cache and module:clear artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ModuleCacheCommand::class,
                ModuleClearCommand::class,
            ]);
        }
    }

    protected function registerMigrations(): void
    {
        if (! $this->app['config']->get('modules.auto-discover.migrations', true)) {
            return;
        }

        $this->app->resolving(Migrator::class, function (Migrator $migrator) {
            $migration_path = $this->app['config']->get('modules.paths.generator.migration.path');
            collect(Module::allEnabled())
                ->each(function (Laravel\Module $module) use ($migration_path, $migrator) {
                    $migrator->path($module->getExtraPath($migration_path));
                });
        });
    }

    protected function registerTranslations(): void
    {
        if (! $this->app['config']->get('modules.auto-discover.translations', true)) {
            return;
        }

        $this->app->resolving(TranslatorContract::class, function (Translator $translator) {
            collect(Module::allEnabled())
                ->each(function (Laravel\Module $module) use ($translator) {
                    $translator->addNamespace($module->getLowerName(), $module->getExtraPath('Resources/lang'));
                });
        });
    }
}
