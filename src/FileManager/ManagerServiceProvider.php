<?php

namespace FileManager;

use Illuminate\Support\ServiceProvider;

/**
 * Class ServiceProvider
 */
class ManagerServiceProvider extends ServiceProvider {

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register() {
        $this->app->singleton('upload', Upload::class);
        $this->app->singleton('project', Bucket::class);
    }
}