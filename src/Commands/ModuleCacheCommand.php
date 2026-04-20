<?php

namespace Nwidart\Modules\Commands;

use Illuminate\Console\Command;
use Nwidart\Modules\ModuleManifest;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Compile the module manifest for faster boot times.
  *
   * The compiled manifest (bootstrap/cache/modules.php) is a PHP file that
    * returns an array of module data keyed by lower-case module name. On
     * subsequent boots, ModuleManifest::getModulesData() reads this file via
      * require (which benefits from the opcode cache) instead of globbing the
       * filesystem, reducing boot-time I/O proportionally to the number of modules.
        *
         * Relationship to the existing `module:cache` for service-provider names:
          * The ModulesServiceProvider already writes provider class names into
           * bootstrap/cache/modules.php via ModuleManifest::build(). This command
            * extends that by also pre-computing the full module data array that
             * FileRepository::scan() relies on, so a fully warm cache eliminates
              * filesystem globs on both the provider-registration path AND the
               * request-time module discovery path.
                *
                 * Usage:
                  *   php artisan module:cache          # compile the manifest
                   *   php artisan module:clear          # delete it
                    *
                     * @see \Nwidart\Modules\Commands\ModuleClearCommand
                      */
                      #[AsCommand(name: 'module:cache')]
                      class ModuleCacheCommand extends Command
                      {
                          /**
                               * The console command name.
                                    *
                                         * @var string
                                              */
                                                  protected $name = 'module:cache';

                                                      /**
                                                           * The console command description.
                                                                *
                                                                     * @var string
                                                                          */
                                                                              protected $description = 'Create a module manifest cache file for faster module discovery';

                                                                                  /**
                                                                                       * Execute the console command.
                                                                                            */
                                                                                                public function handle(ModuleManifest $manifest): int
                                                                                                    {
                                                                                                            $manifest->resetManifest();
                                                                                                                    $manifest->build();
                                                                                                                    
                                                                                                                            $this->components->info('Modules cached successfully.');
                                                                                                                            
                                                                                                                                    return self::SUCCESS;
                                                                                                                                        }
                                                                                                                                        }
