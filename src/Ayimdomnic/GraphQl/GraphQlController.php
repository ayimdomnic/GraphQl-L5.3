<?php

namespace Ayimdomnic\GraphQl;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

class GraphQlController extends Controller
{
    public function inquire(Request $request)
    {
        $query = $request->get('query');
        $params = $request->get('params');

        if(is_string($params))
        {
            $params = json_decode($params, true);
        }

        return app('graphql')->query($query, $params);
    }
}