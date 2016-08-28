<?php
namespace Ayimdomnic\GraphQl\Tests;

abstract class BaseTest extends \Orchestra\Testbench\TestCase
{
    /**
     * Generate GraphQL Response.
     *
     * @param  string  $query
     * @param  array   $variables
     * @param  boolean $encode
     * @return array|string
     */
    protected function graphqlResponse($query, $variables = [], $encode = false)
    {
        $response = $this->app['graphql']->query($query, $variables);
        if ($encode) {
            return json_encode($response);
        }
        return $response;
    }

    /**
     * Get default service providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            \Ayimdomnic\GraphQl\LaravelServiceProvider::class,
        ];
    }
    /**
     * Get list of package aliases.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageAliases($app)
    {
        return [
            'GraphQl' => \Ayimdomnic\GraphQl\Helper\Facades\GraphQL::class,
            
    }
    //define environment Set-Up
}