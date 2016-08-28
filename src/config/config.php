<?php

/**
 * Created by PhpStorm.
 * User: ayimdomnic
 * Date: 8/27/2016
 * Time: 12:51 AM.
 *
 */

return [

    'prefix' => 'graphql',

    'routes' => '/',

    'controllers' => 'Ayimdomnic\GraphQl\GraphQlController@inquire',

    'middleware' => [],

    'schema' => [
        'query' => [

        ],
        'mutation' => [

        ],
    ],


    'types' => [

    ],


    'error_formatter' => ['\Ayimdomnic\GraphQL\GraphQL', 'formatError'],


];
