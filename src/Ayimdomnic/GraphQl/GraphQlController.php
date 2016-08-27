<?php

namespace Ayimdomnic\GraphQl;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class GraphQlController extends Controller
{
    public function inquire(Request $request)
    {
        $query = $request->get('query');
        $params = $request->get('params');

        if (is_string($params)) {
            $params = json_decode($params, true);
        }

        return app('graphql')->query($query, $params);
    }
}
