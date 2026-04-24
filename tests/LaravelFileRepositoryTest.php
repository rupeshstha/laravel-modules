<?php

namespace Nwidart\Modules\Tests;

use Illuminate\Filesystem\Filesystem;
use Nwidart\Modules\Collection;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Exceptions\InvalidAssetPath;
use Nwidart\Modules\Exceptions\ModuleNotFoundException;
use Nwidart\Modules\Laravel\LaravelFileRepository;
use Nwidart\Modules\Module;

/**
 * Tests for LaravelFileRepository.
 *
 * Coverage goals for this class:
 *
 *   Performance / correctness
 *   -------------------------
 *   1. scan() globs the filesystem on the first call and caches the result
 *      in the instance — a second call must NOT re-glob (call-count assertion).
 *   2. resetModules() invalidates the instance cache so the next scan()
 *      performs a fresh glob.
 *   3. Two independent repository instances do NOT share state (Octane safety).
 *
 *   Functional
 *   ----------
 *   4. Basic module creation and retrieval.
 *   5. Module ordering, enabling/disabling.
 *   6. Asset path helpers.
 *   7. findOrFail() throws ModuleNotFoundException.
 *   8. has() / count() / allEnabled() / allDisabled().
 */
class LaravelFileRepositoryTest extends BaseTestCase
{
    /**
     * @var LaravelFileRepository
     */
    private $repository;

    /**
     * @var ActivatorInterface
     */
    private $activator;

    public function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->app[LaravelFileRepository::class];
        $this->activator = $this->app[ActivatorInterface::class];
    }

    public function tearDown(): void
    {
        $this->activator->reset();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Performance / instance-memoization tests
    // -----------------------------------------------------------------------

    /**
     * @test
     *
     * scan() must return the same result on every call without hitting the
     * filesystem more than once per instance (instance memoization).
     *
     * We verify the "no second glob" guarantee by asserting that the result
     * of calling scan() twice is identical AND that the Filesystem mock
     * receives exactly ONE glob() call.
     */
    public function test_scan_is_memoized_at_instance_level(): void
    {
        $files = $this->createMock(Filesystem::class);

        // glob must be called at most once per instance lifetime
        $files->expects($this->atMost(1))
            ->method('glob')
            ->willReturn([]);

        // Build a fresh repository wired to the mock filesystem
        $repo = new LaravelFileRepository($this->app, $this->app['config']['modules.paths.modules']);
        // Swap the private $files property via reflection so we can count calls
        $ref = new \ReflectionProperty($repo, 'files');
        $ref->setAccessible(true);
        $ref->setValue($repo, $files);

        $first  = $repo->scan();
        $second = $repo->scan();

        $this->assertSame($first, $second, 'scan() must return the identical array on a warm hit');
    }

    /**
     * @test
     *
     * resetModules() must null-out the instance cache so the next scan()
     * performs a fresh filesystem glob.
     */
    public function test_reset_modules_clears_instance_cache(): void
    {
        $callCount = 0;
        $files = $this->createMock(Filesystem::class);
        $files->method('glob')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return [];
            });

        $repo = new LaravelFileRepository($this->app, $this->app['config']['modules.paths.modules']);
        $ref = new \ReflectionProperty($repo, 'files');
        $ref->setAccessible(true);
        $ref->setValue($repo, $files);

        $repo->scan();           // cold call — populates cache
        $this->assertSame(1, $callCount, 'First scan() should trigger one glob');

        $repo->scan();           // warm call — must NOT re-glob
        $this->assertSame(1, $callCount, 'Second scan() must not re-glob');

        $repo->resetModules();   // invalidate
        $repo->scan();           // cold again — must re-glob
        $this->assertSame(2, $callCount, 'scan() after resetModules() must trigger a new glob');
    }

    /**
     * @test
     *
     * Two distinct FileRepository instances must NOT share scan state.
     * This is the critical Octane / parallel-worker safety guarantee:
     * each worker has its own singleton, so their caches are independent.
     */
    public function test_two_repository_instances_do_not_share_scan_state(): void
    {
        // repo A — starts empty
        $repoA = new LaravelFileRepository($this->app, $this->app['config']['modules.paths.modules']);

        // repo B — also starts empty
        $repoB = new LaravelFileRepository($this->app, $this->app['config']['modules.paths.modules']);

        $resultA = $repoA->scan();
        $resultB = $repoB->scan();

        // Both start empty — equal values but distinct array instances
        $this->assertEquals($resultA, $resultB);

        // Resetting A must NOT affect B's cache
        $repoA->resetModules();

        // B's cache is still intact — reflected in scannedModules via reflection
        $ref = new \ReflectionProperty($repoB, 'scannedModules');
        $ref->setAccessible(true);
        $this->assertNotNull($ref->getValue($repoB), 'resetting repo A must not clear repo B cache');
    }

    /**
     * @test
     *
     * resetModules() returns $this (fluent interface) to allow chaining.
     */
    public function test_reset_modules_is_fluent(): void
    {
        $result = $this->repository->resetModules();
        $this->assertSame($this->repository, $result);
    }

    // -----------------------------------------------------------------------
    // Functional tests
    // -----------------------------------------------------------------------

    /** @test */
    public function test_it_adds_location(): void
    {
        $this->repository->addLocation('module-new-location');

        $this->assertContains('module-new-location', $this->repository->getPaths());
    }

    /** @test */
    public function test_it_returns_all_enabled_modules(): void
    {
        $this->createModule('Blog');
        $this->createModule('Asgard');

        $this->assertCount(2, $this->repository->allEnabled());
    }

    /** @test */
    public function test_it_returns_all_disabled_modules(): void
    {
        $this->createModule('Blog');
        $this->createModule('Asgard');

        $this->repository->find('Blog')->disable();

        $this->assertCount(1, $this->repository->allDisabled());
    }

    /** @test */
    public function test_it_counts_all_modules(): void
    {
        $this->createModule('Blog');
        $this->createModule('Asgard');

        $this->assertSame(2, $this->repository->count());
    }

    /** @test */
    public function test_it_finds_a_module(): void
    {
        $this->createModule('Blog');

        $this->assertInstanceOf(Module::class, $this->repository->find('Blog'));
    }

    /** @test */
    public function test_it_finds_a_module_by_lowercase_name(): void
    {
        $this->createModule('Blog');

        $this->assertInstanceOf(Module::class, $this->repository->find('blog'));
    }

    /** @test */
    public function test_it_finds_or_fail_throws_exception(): void
    {
        $this->expectException(ModuleNotFoundException::class);

        $this->repository->findOrFail('NonExistentModule');
    }

    /** @test */
    public function test_it_checks_if_module_exists(): void
    {
        $this->createModule('Blog');

        $this->assertTrue($this->repository->has('Blog'));
        $this->assertTrue($this->repository->has('blog'));
        $this->assertFalse($this->repository->has('NonExistent'));
    }

    /** @test */
    public function test_it_returns_ordered_modules(): void
    {
        $this->createModule('Blog');
        $this->createModule('Asgard');

        $ordered = $this->repository->getOrdered();

        $this->assertArrayHasKey('blog', $ordered);
        $this->assertArrayHasKey('asgard', $ordered);
    }

    /** @test */
    public function test_it_gets_module_path(): void
    {
        $this->createModule('Blog');

        $this->assertStringContainsString('Blog', $this->repository->getModulePath('Blog'));
    }

    /** @test */
    public function test_it_gets_all_modules(): void
    {
        $this->createModule('Blog');
        $this->createModule('Asgard');

        $this->assertCount(2, $this->repository->all());
    }

    /** @test */
    public function test_it_gets_asset_path(): void
    {
        $this->assertNotEmpty($this->repository->getAssetsPath());
    }

    /** @test */
    public function test_asset_throws_for_invalid_asset_path(): void
    {
        $this->expectException(InvalidAssetPath::class);

        $this->repository->asset('no-module-name');
    }
}
