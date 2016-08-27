<?php
namespace GraphQL\Tests\Executor;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Executor;
use GraphQL\Language\Parser;
use GraphQL\Schema;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;

class AbstractTest extends \PHPUnit_Framework_TestCase
{

    // Execute: Handles execution of abstract types
    public function testIsTypeOfUsedToResolveRuntimeTypeForInterface()
    {
        // isTypeOf used to resolve runtime type for Interface
        $petType = new InterfaceType([
            'name' => 'Pet',
            'fields' => [
                'name' => ['type' => Type::string()]
            ]
        ]);

        // Added to interface type when defined
        $dogType = new ObjectType([
            'name' => 'Dog',
            'interfaces' => [$petType],
            'isTypeOf' => function($obj) { return $obj instanceof Dog; },
            'fields' => [
                'name' => ['type' => Type::string()],
                'woofs' => ['type' => Type::boolean()]
            ]
        ]);

        $catType = new ObjectType([
            'name' => 'Cat',
            'interfaces' => [$petType],
            'isTypeOf' => function ($obj) {
                return $obj instanceof Cat;
            },
            'fields' => [
                'name' => ['type' => Type::string()],
                'meows' => ['type' => Type::boolean()],
            ]
        ]);

        $schema = new Schema(
            new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'pets' => [
                        'type' => Type::listOf($petType),
                        'resolve' => function () {
                            return [new Dog('Odie', true), new Cat('Garfield', false)];
                        }
                    ]
                ]
            ])
        );

        $query = '{
          pets {
            name
            ... on Dog {
              woofs
            }
            ... on Cat {
              meows
            }
          }
        }';

        $expected = new ExecutionResult([
            'pets' => [
                ['name' => 'Odie', 'woofs' => true],
                ['name' => 'Garfield', 'meows' => false]
            ]
        ]);

        $this->assertEquals($expected, Executor::execute($schema, Parser::parse($query)));
    }

    public function testIsTypeOfUsedToResolveRuntimeTypeForUnion()
    {
        // isTypeOf used to resolve runtime type for Union

        $dogType = new ObjectType([
            'name' => 'Dog',
            'isTypeOf' => function($obj) { return $obj instanceof Dog; },
            'fields' => [
                'name' => ['type' => Type::string()],
                'woofs' => ['type' => Type::boolean()]
            ]
        ]);

        $catType = new ObjectType([
            'name' => 'Cat',
            'isTypeOf' => function ($obj) {
                return $obj instanceof Cat;
            },
            'fields' => [
                'name' => ['type' => Type::string()],
                'meows' => ['type' => Type::boolean()],
            ]
        ]);

        $petType = new UnionType([
            'name' => 'Pet',
            'types' => [$dogType, $catType]
        ]);

        $schema = new Schema(new ObjectType([
            'name' => 'Query',
            'fields' => [
                'pets' => [
                    'type' => Type::listOf($petType),
                    'resolve' => function() {
                        return [ new Dog('Odie', true), new Cat('Garfield', false) ];
                    }
                ]
            ]
        ]));

        $query = '{
          pets {
            name
            ... on Dog {
              woofs
            }
            ... on Cat {
              meows
            }
          }
        }';

        $expected = new ExecutionResult([
            'pets' => [
                ['name' => 'Odie', 'woofs' => true],
                ['name' => 'Garfield', 'meows' => false]
            ]
        ]);

        $this->assertEquals($expected, Executor::execute($schema, Parser::parse($query)));
    }

    function testResolveTypeOnInterfaceYieldsUsefulError()
    {
        $DogType = null;
        $CatType = null;
        $HumanType = null;

        $PetType = new InterfaceType([
            'name' => 'Pet',
            'resolveType' => function ($obj) use (&$DogType, &$CatType, &$HumanType) {
                if ($obj instanceof Dog) {
                    return $DogType;
                }
                if ($obj instanceof Cat) {
                    return $CatType;
                }
                if ($obj instanceof Human) {
                    return $HumanType;
                }
                return null;
            },
            'fields' => [
                'name' => ['type' => Type::string()]
            ]
        ]);

        $HumanType = new ObjectType([
            'name' => 'Human',
            'fields' => [
                'name' => ['type' => Type::string()],
            ]
        ]);

        $DogType = new ObjectType([
            'name' => 'Dog',
            'interfaces' => [$PetType],
            'fields' => [
                'name' => ['type' => Type::string()],
                'woofs' => ['type' => Type::boolean()],
            ]
        ]);

        $CatType = new ObjectType([
            'name' => 'Cat',
            'interfaces' => [$PetType],
            'fields' => [
                'name' => ['type' => Type::string()],
                'meows' => ['type' => Type::boolean()],
            ]
        ]);

        $schema = new Schema(new ObjectType([
            'name' => 'Query',
            'fields' => [
                'pets' => [
                    'type' => Type::listOf($PetType),
                    'resolve' => function () {
                        return [
                            new Dog('Odie', true),
                            new Cat('Garfield', false),
                            new Human('Jon')
                        ];
                    }
                ]
            ]
        ]));


        $query = '{
          pets {
            name
            ... on Dog {
              woofs
            }
            ... on Cat {
              meows
            }
          }
        }';

        $expected = [
            'data' => [
                'pets' => [
                    ['name' => 'Odie', 'woofs' => true],
                    ['name' => 'Garfield', 'meows' => false],
                    null
                ]
            ],
            'errors' => [
                [ 'message' => 'Runtime Object type "Human" is not a possible type for "Pet".' ]
            ]
        ];

        $this->assertEquals($expected, Executor::execute($schema, Parser::parse($query))->toArray());
    }

}
