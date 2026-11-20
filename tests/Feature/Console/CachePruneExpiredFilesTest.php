<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CachePruneExpiredFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/cache_prune_' . uniqid();
        File::makeDirectory($this->dir . '/ab/cd', 0755, true);
        config(['cache.stores.file.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_only_expired_entries_are_removed(): void
    {
        file_put_contents($this->dir . '/ab/cd/expired', (time() - 10) . serialize(1));
        file_put_contents($this->dir . '/ab/cd/alive', (time() + 3600) . serialize(1));
        file_put_contents($this->dir . '/ab/cd/foreign', 'not a cache entry');
        file_put_contents($this->dir . '/ab/cd/empty', '');

        $this->artisan('cache:prune-expired-files')->assertSuccessful();

        $this->assertFileDoesNotExist($this->dir . '/ab/cd/expired');
        $this->assertFileExists($this->dir . '/ab/cd/alive');
        $this->assertFileExists($this->dir . '/ab/cd/foreign');
        $this->assertFileExists($this->dir . '/ab/cd/empty');
    }

    public function test_missing_cache_directory_is_not_an_error(): void
    {
        config(['cache.stores.file.path' => $this->dir . '/nope']);

        $this->artisan('cache:prune-expired-files')->assertSuccessful();
    }
}
