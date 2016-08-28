<?php

namespace Ayimdomnic\GraphQl;

class GraphQlServiceProvider extends SeviceProvider
{
    public function boot()
    {
        $this->bootPublishes();

        $this->bootTypes();

        if (config('graphql.routes')) {
            include __DIR__.'/routes.php';
        }
    }

    /**
     * this publishes the GraphQl config file
     */
    protected function bootPublishes()
    {
        $configPath = __DIR__.'/../../config';

        $this->mergeConfigFrom($configPath.'/config.php', 'graphql');

        $this->publishes([
            $configPath.'/config.php' => config_path('graphql.php'),
        ], 'config');
    }

    /**
     * this handles the type
     */
    protected function bootTypes()
    {
        $configTypes = config('graphql.types');
        foreach ($configTypes as $name => $type) {
            if (is_numeric($name)) {
                $this->app['graphql']->addType($type);
            } else {
                $this->app['graphql']->addType($type, $name);
            }
        }
    }

    /**
     *
     */
    public function registerGraphQL()
    {
        $this->app->singleton('graphql', function ($app) {
            return new GraphQL($app);
        });
    }

    /**
     *
     */
    public function register()
    {
        $this->registerGraphQL();
    }

    // public function deregister()
    // {
    //     $this->deregisterGraphQl();
    // }

    // public function deregisterGraphQl()
    // {
    //     $this->app->singleton('graphql', function ($app){
    //         return is_null($app);
    //     });
    // }
}
