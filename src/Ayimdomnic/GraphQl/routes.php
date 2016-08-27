<?php


use Illuminate\Routing\Route;

Route::group([
    'prefix'     => config('graphql.prefix'),
    'middleware' => config('graphql.middleware', []),
], function () {
    //Routes for GraphQl
    $routes = config('graphql.routes');
    $queryRoute = null;
    $mutationRoute = null;
    if (is_array($routes)) {
        $queryRoute = array_get($routes, 'query', null);
        $mutationRoute = array_get($routes, 'mutation', null);
    } else {
        $queryRoute = $routes;
        $mutationRoute = $routes;
    }

    //Controllers
    $controllers = config('graphql.controllers', '\Ayimdomnic\GraphQl\GraphQlController@query');
    $queryController = null;
    $mutationController = null;
    if (is_array($controllers)) {
        $queryController = array_get($controllers, 'query', null);
        $mutationController = array_get($controllers, 'mutation', null);
    } else {
        $queryController = $controllers;
        $mutationController = $controllers;
    }

    // this is the query route
    if ($queryRoute) {
        Route::get($queryRoute, [
            'as'   => 'graphql.query',
            'uses' => $queryController,
        ]);
    }

    if ($mutationRoute) {
        //this is the route for mutation
        Route::post($mutationRoute, [
            'as'   => 'graphql.mutation',
            'uses' => $mutationController,
        ]);
    }
});
