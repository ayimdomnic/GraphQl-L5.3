# GraphQl-L5.3 [![StyleCI](https://styleci.io/repos/66704950/shield)](https://styleci.io/repos/66704950) [![Build Status](https://travis-ci.org/ayimdomnic/GraphQl-L5.3.svg?branch=master)](https://travis-ci.org/ayimdomnic/GraphQl-L5.3)
After the Developer Workshop in Nairobi, I have resolved to Move from Rest to GraphQL, This is a package to assit me with the same as I develop may laravel APIs

#Requirements

1. PHP 5.6 and Above
2. Laravel 5.3 

#Instalation

1. `composer require ayimdomnic/graph-ql-l5.3`
2. add `Ayimdomnic\GraphQl\GraphQlServiceProvider::class,` to `config/app`
3. add `'GraphQl' => 'Ayimdomnic\GraphQl\Helper\Facade\GraphQl',` to the Facades
4. publish `php artisan vendor:publish`

#Usage
- [Creating a query](#creating-a-query)
- [Creating a mutation](#creating-a-mutation)
- [Adding validation to mutation](#adding-validation-to-mutation)
 
#Creating a Query(#creating-a-query)
```php

	namespace App\GraphQL\Type;
	
	use GraphQL\Type\Definition\Type;
	use Ayimdomnic\GraphQL\Helper\Type as GraphQLType;
    
    class UserType extends GraphQLType {
        
        protected $attributes = [
			'name' => 'User',
			'description' => 'A user'
		];
		
		public function fields()
		{
			return [
				'id' => [
					'type' => Type::nonNull(Type::string()),
					'description' => 'The id of the user'
				],
				'email' => [
					'type' => Type::string(),
					'description' => 'The email of user'
				]
			];
		}
			
			
		// If you want to resolve the field yourself, you can declare a method
		// with the following format resolve[FIELD_NAME]Field()
		protected function resolveEmailField($root, $args)
		{
			return strtolower($root->email);
		}
        
    }

```



