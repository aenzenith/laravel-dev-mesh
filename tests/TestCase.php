<?php

namespace Aenzenith\DevMesh\Tests;

use Aenzenith\DevMesh\DevMeshServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DevMeshServiceProvider::class];
    }
}
