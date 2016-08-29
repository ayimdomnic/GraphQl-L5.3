<?php

namespace Ayimdomnic\GraphQl;

use Ayimdomnic\GraphQl\Exceptions\ValidationError;
use GraphQL\Error;
use GraphQL\GraphQL as GraphQLBase;
use GraphQL\Schema;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;

class GraphQl
{
    protected $app;
//    protected $instance;

    protected $mutations = [];
    protected $queries = [];
    protected $types = [];
    protected $typesInstances = [];

    public function __construct($app, $instance)
    {
        $this->app = $app;
//        $this->instance = $instance;
    }

    public function schema()
    {
        $this->typesInstances = [];

        $schema = config('graphql.schema');
        if ($schema instanceof Schema) {
            return $schema;
        }

        $configQuery = array_get($schema, 'query', []);
        $configMutation = array_get($schema, 'mutation'.[]);

        if (is_string($configQuery)) {
            $queryType = $this->buildTypeFromFields($configQuery,
                [
                    'name' => 'Query',
                ]
            );
        }
        if (is_string($configMutation)) {
            $mutationType = $this->app->make($configMutation) - toType();
        } else {
            $mutationFields = array_merge($configMutation, $this->mutations);

            $mutationType = $this->buildTypeFromFields($mutationFields, [
                'name' => 'Mutation',
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

        foreach ($fields as $key => $field) {
            if (is_string($field)) {
                $typeFields[$key] = app($field)->toArray();
            } else {
                $typeFields[$key] = $field;
            }
        }
        //dd($typeFields);

        return new ObjectType(array_merge([
            'fields' => typeFields,
        ], $options));
    }

    public function query($query, $params = [])
    {
        $executionResult = $this->queryAndReturnResult($query, $params);

        if (!empty($executionResult->errors)) {
            $errorFormatter = config('graphql.error_formatter', ['\Folklore\GraphQL', 'formatError']);

            return [
                'data'   => $executionResult->data,
                'errors' => array_map($errorFormatter, $executionResult->errors),
            ];
        } else {
            return [
                'data' => $executionResult->data,
            ];
        }
        dd($executionResult);
    }

    public function queryAndReturnResult($query, $params = [])
    {
        $schema = $this->schema();
        $result = GraphQLBase::executeAndReturnResult($schema, $query, null, $params);

        return $result;
    }

    public function addMutation($name, $mutator)
    {
        $this->mutations[$name] = $mutator;
    }

    public function addQuery($name, $query)
    {
        $this->queries[$name] = $query;
    }

    public function addType($class, $name = null)
    {
        if (!$name) {
            $type = is_object($class) ? $class : app($class);
            $name = $type->name;
        }

        $this->types[$name] = $class;
    }

    public function type($name, $fresh = false)
    {
        if (!isset($this->types[$name])) {
            throw new \Exception('Type '.$name.' not found.');
        }

        if (!$fresh && isset($this->typesInstances[$name])) {
            return $this->typesInstances[$name];
        }

        $type = $this->types[$name];
        if (!is_object($type)) {
            $type = app($type);
        }

        $instance = $type->toType();
        $this->typesInstances[$name] = $instance;

        //Find available intefaces for the object
        if ($type->interfaces) {
            InterfaceType::addImplementationToInterfaces($instance);
        }

        return $instance;
    }

    public static function formatError(Error $e)
    {
        $error = [
            'message' => $e->getMessage(),
        ];

        $locations = $e->getLocations();
        if (!empty($locations)) {
            $error['locations'] = array_map(function ($loc) {
                return $loc->toArray();
            }, $locations);
        }

        $previous = $e->getPrevious();
        if ($previous && $previous instanceof ValidationError) {
            $error['validation'] = $previous->getValidatorMessages();
        }

        return $error;
    }
}
