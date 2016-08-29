<?php


use Illuminate\Routing\Route;

Route::group([
    'prefix'     => config('graphql.prefix'),
    'middleware' => config('graphql.middleware', []),
], function () {

    ##########################GraphQL#############ROUTES################
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

    ##########################INQUIRE#####################################
    $controllers = config('graphql.controllers', '\Ayimdomnic\GraphQl\GraphQlController@inquire');
    $queryController = null;
    $mutationController = null;
    if (is_array($controllers)) {
        $queryController = array_get($controllers, 'query', null);
        $mutationController = array_get($controllers, 'mutation', null);
    } else {
        $queryController = $controllers;
        $mutationController = $controllers;
    }

    ########################################QUERY METHOD##########################
    if ($queryRoute) {
        Route::get($queryRoute, [
            'as'   => 'graphql.query',
            'uses' => $queryController,
        ]);
    }

    if ($mutationRoute) {
        ####################################MUTATION ROUTE##############################
        Route::post($mutationRoute, [
            'as'   => 'graphql.mutation',
            'uses' => $mutationController,
        ]);
    }
});
