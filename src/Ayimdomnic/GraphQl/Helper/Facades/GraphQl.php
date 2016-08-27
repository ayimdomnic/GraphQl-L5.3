<?php
/**
 * Created by PhpStorm.
 * User: Ayimdomnic
 * Date: 8/27/2016
 * Time: 3:24 AM
 */

namespace Ayimdomnic\GraphQl\Helper\Facades;


use Illuminate\Support\Facades\Facade;

class GraphQl extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'graphql';
    }
}