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

    protected function bootPublishes()
    {
        $configPath = __DIR__.'/../../config';

        $this->mergeConfigFrom($configPath.'/config.php', 'graphql');

        $this->publishes([
            $configPath.'/config.php' => config_path('graphql.php'),
        ], 'config');
    }

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

    public function registerGraphQL()
    {
        $this->app->singleton('graphql', function ($app) {
            return new GraphQL($app);
        });
    }

    public function register()
    {
        $this->registerGraphQL();
    }
}
