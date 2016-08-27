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
    protected $instance;

    protected $mutations =[];
    protected $queries =[];
    protected $types = [];
    protected $typesInstances =[];


    public function __construct($app, $instance)
    {
        $this->app = $app;
        $this->instance = $instance;
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
            $queryType = $this->buildTypeFromFields($configQuery,
                [
                    'name'=> 'Query'
                ]
            );
        }
        if(is_string($configMutation))
        {
            $mutationType = $this->app->make($configMutation)-toType();
        } else {
            $mutationFields = array_merge($configMutation, $this->mutations);

            $mutationType = $this->buildTypeFromFields($mutationFields, [
                'name' => 'Mutation'
            ]);
        }

        return new Schema($queryType, $mutationType);
    }

    protected function buildTypeFromFields($filds, $options = [])
    {
        //excute the results of the queue
//        $excecutionResult = $this->queryAndReturnResult()
//        dd($excecuteResult);

        $typeFields = [];

        foreach($fields as $key => $field) {

            if (is_string($field)) {
                $typeFields[$key] = app($field)->toArray();
            } else {
                $typeFields[$key] = $field;
            }
        }

        return new ObjectType(array_merge([
            'fields' => typefields
        ], $options));
    }
}