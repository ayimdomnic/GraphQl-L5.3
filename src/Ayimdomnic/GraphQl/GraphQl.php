<?php



namespace Ayimdomnic\GraphQl;

use GraphQL\GraphQL as GraphQLBase;

use GraphQL\Schema;
use GraphQL\Error;

use GraphQL\Type\Definition\ObjectType;

use GraphQL\Type\Definition\InterfaceType;

use Ayimdomnic\GraphQl\Exceptions\ValidationError;

class GraphQl
{
    protected $app;

    protected $mutations =[];
    protected $queries =[];
    protected $types = [];
    protected $typesInstances =[];


    public function __construct($app)
    {
        $this->app = $app;
        //return an instance of the sms
    }

    public function schema()
    {
        $this->typesInstances = [];

        $schema = config('graphql.schema');
        if($schema instanceof Schema)
        {
            return $schema;
        }

        $configQuery = array_get($schema, 'query',[]);
        $configMutation = array_get($schema,'mutation'.[]);

        if(is_string($configQuery))
        {
            $queryType = $this->buildTypeFormFields($configQuery,
                [
                    'name'=> 'Query'
                ]
            );
        }
        if(is_string($configMutation))
        {
            
        }
    }
}