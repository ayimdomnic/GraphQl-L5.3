<?php
namespace GraphQL\Executor;

use GraphQL\Error;
use GraphQL\Language\AST\Document;
use GraphQL\Language\AST\Field;
use GraphQL\Language\AST\FragmentDefinition;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\OperationDefinition;
use GraphQL\Language\AST\SelectionSet;
use GraphQL\Schema;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Introspection;
use GraphQL\Utils;

/**
 * @deprecated Use regular executor instead
 *
 * Terminology
 *
 * "Definitions" are the generic name for top-level statements in the document.
 * Examples of this include:
 * 1) Operations (such as a query)
 * 2) Fragments
 *
 * "Operations" are a generic name for requests in the document.
 * Examples of this include:
 * 1) query,
 * 2) mutation
 *
 * "Selections" are the statements that can appear legally and at
 * single level of the query. These include:
 * 1) field references e.g "a"
 * 2) fragment "spreads" e.g. "...c"
 * 3) inline fragment "spreads" e.g. "...on Type { a }"
 */
class MappingExecutor
{
    private static $UNDEFINED;

    private static $defaultResolveFn = [__CLASS__, 'defaultResolveFn'];

    /**
     * Custom default resolve function
     *
     * @param $fn
     * @throws \Exception
     */
    public function setDefaultResolveFn($fn)
    {
        Utils::invariant(is_callable($fn), 'Expecting callable, but got ' . Utils::getVariableType($fn));
        self::$defaultResolveFn = $fn;
    }

    /**
     * @param Schema $schema
     * @param Document $ast
     * @param $rootValue
     * @param array|\ArrayAccess $variableValues
     * @param null $operationName
     * @return ExecutionResult
     */
    public static function execute(Schema $schema, Document $ast, $rootValue = null, $variableValues = null, $operationName = null)
    {
        if (!self::$UNDEFINED) {
            self::$UNDEFINED = new \stdClass();
        }

        if (null !== $variableValues) {
            Utils::invariant(
                is_array($variableValues) || $variableValues instanceof \ArrayAccess,
                "Variable values are expected to be array or instance of ArrayAccess, got " . Utils::getVariableType($variableValues)
            );
        }
        if (null !== $operationName) {
            Utils::invariant(
                is_string($operationName),
                "Operation name is supposed to be string, got " . Utils::getVariableType($operationName)
            );
        }

        $exeContext = self::buildExecutionContext($schema, $ast, $rootValue, $variableValues, $operationName);

        try {
            $data = self::executeOperation($exeContext, $exeContext->operation, $rootValue);
        } catch (Error $e) {
            $exeContext->addError($e);
            $data = null;
        }

        return new ExecutionResult($data, $exeContext->errors);
    }

    /**
     * Constructs a ExecutionContext object from the arguments passed to
     * execute, which we will pass throughout the other execution methods.
     */
    private static function buildExecutionContext(Schema $schema, Document $documentAst, $rootValue, $rawVariableValues, $operationName = null)
    {
        $errors = [];
        $operations = [];
        $fragments = [];

        foreach ($documentAst->definitions as $statement) {
            switch ($statement->kind) {
                case Node::OPERATION_DEFINITION:
                    $operations[$statement->name ? $statement->name->value : ''] = $statement;
                    break;
                case Node::FRAGMENT_DEFINITION:
                    $fragments[$statement->name->value] = $statement;
                    break;
            }
        }

        if (!$operationName && count($operations) !== 1) {
            throw new Error(
                'Must provide operation name if query contains multiple operations.'
            );
        }

        $opName = $operationName ?: key($operations);
        if (empty($operations[$opName])) {
            throw new Error('Unknown operation named ' . $opName);
        }
        $operation = $operations[$opName];

        $variableValues = Values::getVariableValues($schema, $operation->variableDefinitions ?: [], $rawVariableValues ?: []);
        $exeContext = new ExecutionContext($schema, $fragments, $rootValue, $operation, $variableValues, $errors);
        return $exeContext;
    }

    /**
     * Implements the "Evaluating operations" section of the spec.
     */
    private static function executeOperation(ExecutionContext $exeContext, OperationDefinition $operation, $rootValue)
    {
        $type = self::getOperationRootType($exeContext->schema, $operation);
        $fields = self::collectFields($exeContext, $type, $operation->selectionSet, new \ArrayObject(), new \ArrayObject());

        if ($operation->operation === 'mutation') {
            $result = self::executeFieldsSerially($exeContext, $type, [$rootValue], $fields);
        } else {
            $result = self::executeFields($exeContext, $type, [$rootValue], $fields);
        }

        return null === $result || $result === [] ? [] : $result[0];
    }


    /**
     * Extracts the root type of the operation from the schema.
     *
     * @param Schema $schema
     * @param OperationDefinition $operation
     * @return ObjectType
     * @throws Error
     */
    private static function getOperationRootType(Schema $schema, OperationDefinition $operation)
    {
        switch ($operation->operation) {
            case 'query':
                return $schema->getQueryType();
            case 'mutation':
                $mutationType = $schema->getMutationType();
                if (!$mutationType) {
                    throw new Error(
                        'Schema is not configured for mutations',
                        [$operation]
                    );
                }
                return $mutationType;
            default:
                throw new Error(
                    'Can only execute queries and mutations',
                    [$operation]
                );
        }
    }

    /**
     * Implements the "Evaluating selection sets" section of the spec
     * for "write" mode.
     *
     * @param ExecutionContext $exeContext
     * @param ObjectType $parentType
     * @param $sourceList
     * @param $fields
     * @return array
     * @throws Error
     * @throws \Exception
     */
    private static function executeFieldsSerially(ExecutionContext $exeContext, ObjectType $parentType, $sourceList, $fields)
    {
        $results = [];
        foreach ($fields as $responseName => $fieldASTs) {
            self::resolveField($exeContext, $parentType, $sourceList, $fieldASTs, $responseName, $results);
        }

        return $results;
    }

    /**
     * Implements the "Evaluating selection sets" section of the spec
     * for "read" mode.
     * @param ExecutionContext $exeContext
     * @param ObjectType $parentType
     * @param $sourceList
     * @param $fields
     * @return array
     */
    private static function executeFields(ExecutionContext $exeContext, ObjectType $parentType, $sourceList, $fields)
    {
        // Native PHP doesn't support promises.
        // Custom executor should be built for platforms like ReactPHP
        return self::executeFieldsSerially($exeContext, $parentType, $sourceList, $fields);
    }


    /**
     * Given a selectionSet, adds all of the fields in that selection to
     * the passed in map of fields, and returns it at the end.
     *
     * @return \ArrayObject
     */
    private static function collectFields(
        ExecutionContext $exeContext,
        ObjectType $type,
        SelectionSet $selectionSet,
        $fields,
        $visitedFragmentNames
    )
    {
        for ($i = 0; $i < count($selectionSet->selections); $i++) {
            $selection = $selectionSet->selections[$i];
            switch ($selection->kind) {
                case Node::FIELD:
                    if (!self::shouldIncludeNode($exeContext, $selection->directives)) {
                        continue;
                    }
                    $name = self::getFieldEntryKey($selection);
                    if (!isset($fields[$name])) {
                        $fields[$name] = new \ArrayObject();
                    }
                    $fields[$name][] = $selection;
                    break;
                case Node::INLINE_FRAGMENT:
                    if (!self::shouldIncludeNode($exeContext, $selection->directives) ||
                        !self::doesFragmentConditionMatch($exeContext, $selection, $type)
                    ) {
                        continue;
                    }
                    self::collectFields(
                        $exeContext,
                        $type,
                        $selection->selectionSet,
                        $fields,
                        $visitedFragmentNames
                    );
                    break;
                case Node::FRAGMENT_SPREAD:
                    $fragName = $selection->name->value;
                    if (!empty($visitedFragmentNames[$fragName]) || !self::shouldIncludeNode($exeContext, $selection->directives)) {
                        continue;
                    }
                    $visitedFragmentNames[$fragName] = true;

                    /** @var FragmentDefinition|null $fragment */
                    $fragment = isset($exeContext->fragments[$fragName]) ? $exeContext->fragments[$fragName] : null;
                    if (!$fragment ||
                        !self::shouldIncludeNode($exeContext, $fragment->directives) ||
                        !self::doesFragmentConditionMatch($exeContext, $fragment, $type)
                    ) {
                        continue;
                    }
                    self::collectFields(
                        $exeContext,
                        $type,
                        $fragment->selectionSet,
                        $fields,
                        $visitedFragmentNames
                    );
                    break;
            }
        }
        return $fields;
    }

    /**
     * Determines if a field should be included based on the @include and @skip
     * directives, where @skip has higher precedence than @include.
     */
    private static function shouldIncludeNode(ExecutionContext $exeContext, $directives)
    {
        $skipDirective = Directive::skipDirective();
        $includeDirective = Directive::includeDirective();

        /** @var \GraphQL\Language\AST\Directive $skipAST */
        $skipAST = $directives
            ? Utils::find($directives, function(\GraphQL\Language\AST\Directive $directive) use ($skipDirective) {
                return $directive->name->value === $skipDirective->name;
            })
            : null;

        if ($skipAST) {
            $argValues = Values::getArgumentValues($skipDirective->args, $skipAST->arguments, $exeContext->variableValues);
            return empty($argValues['if']);
        }

        /** @var \GraphQL\Language\AST\Directive $includeAST */
        $includeAST = $directives
            ? Utils::find($directives, function(\GraphQL\Language\AST\Directive $directive) use ($includeDirective) {
                return $directive->name->value === $includeDirective->name;
            })
            : null;

        if ($includeAST) {
            $argValues = Values::getArgumentValues($includeDirective->args, $includeAST->arguments, $exeContext->variableValues);
            return !empty($argValues['if']);
        }

        return true;
    }

    /**
     * Determines if a fragment is applicable to the given type.
     */
    private static function doesFragmentConditionMatch(ExecutionContext $exeContext,/* FragmentDefinition | InlineFragment*/ $fragment, ObjectType $type)
    {
        $conditionalType = Utils\TypeInfo::typeFromAST($exeContext->schema, $fragment->typeCondition);
        if ($conditionalType === $type) {
            return true;
        }
        if ($conditionalType instanceof InterfaceType ||
            $conditionalType instanceof UnionType
        ) {
            return $conditionalType->isPossibleType($type);
        }
        return false;
    }

    /**
     * Implements the logic to compute the key of a given fields entry
     */
    private static function getFieldEntryKey(Field $node)
    {
        return $node->alias ? $node->alias->value : $node->name->value;
    }

    /**
     * Given list of parent type values returns corresponding list of field values
     *
     * In particular, this
     * figures out the value that the field returns by calling its `resolve` or `map` function,
     * then calls `completeValue` on each value to serialize scalars, or execute the sub-selection-set
     * for objects.
     *
     * @param ExecutionContext $exeContext
     * @param ObjectType $parentType
     * @param $sourceValueList
     * @param $fieldASTs
     * @return array
     * @throws Error
     */
    private static function resolveField(ExecutionContext $exeContext, ObjectType $parentType, $sourceValueList, $fieldASTs, $responseName, &$resolveResult)
    {
        $fieldAST = $fieldASTs[0];
        $fieldName = $fieldAST->name->value;

        $fieldDef = self::getFieldDef($exeContext->schema, $parentType, $fieldName);

        if (!$fieldDef) {
            return ;
        }

        $returnType = $fieldDef->getType();

        // Build hash of arguments from the field.arguments AST, using the
        // variables scope to fulfill any variable references.
        // TODO: find a way to memoize, in case this field is within a List type.
        $args = Values::getArgumentValues(
            $fieldDef->args,
            $fieldAST->arguments,
            $exeContext->variableValues
        );

        // The resolve function's optional third argument is a collection of
        // information about the current execution state.
        $info = new ResolveInfo([
            'fieldName' => $fieldName,
            'fieldASTs' => $fieldASTs,
            'returnType' => $returnType,
            'parentType' => $parentType,
            'schema' => $exeContext->schema,
            'fragments' => $exeContext->fragments,
            'rootValue' => $exeContext->rootValue,
            'operation' => $exeContext->operation,
            'variableValues' => $exeContext->variableValues,
        ]);

        $mapFn = $fieldDef->mapFn;

        // If an error occurs while calling the field `map` or `resolve` function, ensure that
        // it is wrapped as a GraphQLError with locations. Log this error and return
        // null if allowed, otherwise throw the error so the parent field can handle
        // it.
        if ($mapFn) {
            try {
                $mapped = call_user_func($mapFn, $sourceValueList, $args, $info);
                $validType = is_array($mapped) || ($mapped instanceof \Traversable && $mapped instanceof \Countable);
                $mappedCount = count($mapped);
                $sourceCount = count($sourceValueList);

                Utils::invariant(
                    $validType && count($mapped) === count($sourceValueList),
                    "Function `map` of $parentType.$fieldName is expected to return array or " .
                    "countable traversable with exact same number of items as list being mapped. ".
                    "Got '%s' with count '$mappedCount' against '$sourceCount' expected.",
                    Utils::getVariableType($mapped)
                );

            } catch (\Exception $error) {
                $reportedError = Error::createLocatedError($error, $fieldASTs);

                if ($returnType instanceof NonNull) {
                    throw $reportedError;
                }

                $exeContext->addError($reportedError);
                return null;
            }

            foreach ($mapped as $index => $value) {
                $resolveResult[$index][$responseName] = self::completeValueCatchingError(
                    $exeContext,
                    $returnType,
                    $fieldASTs,
                    $info,
                    $value
                );
            }
        } else {
            if (isset($fieldDef->resolveFn)) {
                $resolveFn = $fieldDef->resolveFn;
            } else if (isset($parentType->resolveFieldFn)) {
                $resolveFn = $parentType->resolveFieldFn;
            } else {
                $resolveFn = self::$defaultResolveFn;
            }

            foreach ($sourceValueList as $index => $value) {
                try {
                    $resolved = call_user_func($resolveFn, $value, $args, $info);
                } catch (\Exception $error) {
                    $reportedError = Error::createLocatedError($error, $fieldASTs);

                    if ($returnType instanceof NonNull) {
                        throw $reportedError;
                    }

                    $exeContext->addError($reportedError);
                    $resolved = null;
                }

                $resolveResult[$index][$responseName] = self::completeValueCatchingError(
                    $exeContext,
                    $returnType,
                    $fieldASTs,
                    $info,
                    $resolved
                );
            }
        }
    }


    public static function completeValueCatchingError(
        ExecutionContext $exeContext,
        Type $returnType,
        $fieldASTs,
        ResolveInfo $info,
        $result
    )
    {
        // If the field type is non-nullable, then it is resolved without any
        // protection from errors.
        if ($returnType instanceof NonNull) {
            return self::completeValue($exeContext, $returnType, $fieldASTs, $info, $result);
        }

        // Otherwise, error protection is applied, logging the error and resolving
        // a null value for this field if one is encountered.
        try {
            return self::completeValue($exeContext, $returnType, $fieldASTs, $info, $result);
        } catch (Error $err) {
            $exeContext->addError($err);
            return null;
        }
    }

    /**
     * Implements the instructions for completeValue as defined in the
     * "Field entries" section of the spec.
     *
     * If the field type is Non-Null, then this recursively completes the value
     * for the inner type. It throws a field error if that completion returns null,
     * as per the "Nullability" section of the spec.
     *
     * If the field type is a List, then this recursively completes the value
     * for the inner type on each item in the list.
     *
     * If the field type is a Scalar or Enum, ensures the completed value is a legal
     * value of the type by calling the `serialize` method of GraphQL type
     * definition.
     *
     * Otherwise, the field type expects a sub-selection set, and will complete the
     * value by evaluating all sub-selections.
     */
    private static function completeValue(ExecutionContext $exeContext, Type $returnType,/* Array<Field> */ $fieldASTs, ResolveInfo $info, &$result)
    {
        // If field type is NonNull, complete for inner type, and throw field error
        // if result is null.
        if ($returnType instanceof NonNull) {
            $completed = self::completeValue(
                $exeContext,
                $returnType->getWrappedType(),
                $fieldASTs,
                $info,
                $result
            );
            if ($completed === null) {
                throw new Error(
                    'Cannot return null for non-nullable type.',
                    $fieldASTs instanceof \ArrayObject ? $fieldASTs->getArrayCopy() : $fieldASTs
                );
            }
            return $completed;
        }

        // If result is null-like, return null.
        if (null === $result) {
            return null;
        }

        // If field type is Scalar or Enum, serialize to a valid value, returning
        // null if serialization is not possible.
        if ($returnType instanceof ScalarType ||
            $returnType instanceof EnumType) {
            return $returnType->serialize($result);
        }

        // If field type is List, and return type is Composite - complete by executing these fields with list value as parameter
        if ($returnType instanceof ListOfType) {
            $itemType = $returnType->getWrappedType();

            Utils::invariant(
                is_array($result) || $result instanceof \Traversable,
                'User Error: expected iterable, but did not find one.'
            );

            // For Object[]:
            // Allow all object fields to process list value in it's `map` callback:
            if ($itemType instanceof ObjectType) {
                // Filter out nulls (as `map` doesn't expect it):
                $list = [];
                foreach ($result as $index => $item) {
                    if (null !== $item) {
                        $list[] = $item;
                    }
                }

                $subFieldASTs = self::collectSubFields($exeContext, $itemType, $fieldASTs);
                $mapped = self::executeFields($exeContext, $itemType, $list, $subFieldASTs);

                $i = 0;
                $completed = [];
                foreach ($result as $index => $item) {
                    if (null === $item) {
                        // Complete nulls separately
                        $completed[] = self::completeValueCatchingError($exeContext, $itemType, $fieldASTs, $info, $item);
                    } else {
                        // Assuming same order of mapped values
                        $completed[] = $mapped[$i++];
                    }
                }
                return $completed;

            } else if ($itemType instanceof AbstractType) {

                // Values sharded by ObjectType
                $listPerObjectType = [];

                // Helper structures to restore ordering after resolve calls
                $resultTypeMap = [];
                $typeNameMap = [];
                $cursors = [];
                $copied = [];

                foreach ($result as $index => $item) {
                    $copied[$index] = $item;

                    if (null !== $item) {
                        $objectType = $itemType->getObjectType($item, $info);

                        if ($objectType && !$itemType->isPossibleType($objectType)) {
                            $exeContext->addError(new Error(
                                "Runtime Object type \"$objectType\" is not a possible type for \"$itemType\"."
                            ));
                            $copied[$index] = null;
                        } else {
                            $listPerObjectType[$objectType->name][] = $item;
                            $resultTypeMap[$index] = $objectType->name;
                            $typeNameMap[$objectType->name] = $objectType;
                        }
                    }
                }

                $mapped = [];
                foreach ($listPerObjectType as $typeName => $list) {
                    $objectType = $typeNameMap[$typeName];
                    $subFieldASTs = self::collectSubFields($exeContext, $objectType, $fieldASTs);
                    $mapped[$typeName] = self::executeFields($exeContext, $objectType, $list, $subFieldASTs);
                    $cursors[$typeName] = 0;
                }

                // Restore order:
                $completed = [];
                foreach ($copied as $index => $item) {
                    if (null === $item) {
                        // Complete nulls separately
                        $completed[] = self::completeValueCatchingError($exeContext, $itemType, $fieldASTs, $info, $item);
                    } else {
                        $typeName = $resultTypeMap[$index];
                        $completed[] = $mapped[$typeName][$cursors[$typeName]++];
                    }
                }

                return $completed;
            } else {

                // For simple lists:
                $tmp = [];
                foreach ($result as $item) {
                    $tmp[] = self::completeValueCatchingError($exeContext, $itemType, $fieldASTs, $info, $item);
                }
                return $tmp;
            }

        }

        if ($returnType instanceof ObjectType) {
            $objectType = $returnType;

        } else if ($returnType instanceof AbstractType) {
            $objectType = $returnType->getObjectType($result, $info);

            if ($objectType && !$returnType->isPossibleType($objectType)) {
                throw new Error(
                    "Runtime Object type \"$objectType\" is not a possible type for \"$returnType\"."
                );
            }
        } else {
            $objectType = null;
        }

        if (!$objectType) {
            return null;
        }

        // If there is an isTypeOf predicate function, call it with the
        // current result. If isTypeOf returns false, then raise an error rather
        // than continuing execution.
        if (false === $objectType->isTypeOf($result, $info)) {
            throw new Error(
                "Expected value of type $objectType but got: $result.",
                $fieldASTs
            );
        }

        // Collect sub-fields to execute to complete this value.
        $subFieldASTs = self::collectSubFields($exeContext, $objectType, $fieldASTs);
        $executed = self::executeFields($exeContext, $objectType, [$result], $subFieldASTs);
        return isset($executed[0]) ? $executed[0] : null;
    }

    /**
     * @param ExecutionContext $exeContext
     * @param ObjectType $objectType
     * @param $fieldASTs
     * @return \ArrayObject
     */
    private static function collectSubFields(ExecutionContext $exeContext, ObjectType $objectType, $fieldASTs)
    {
        $subFieldASTs = new \ArrayObject();
        $visitedFragmentNames = new \ArrayObject();
        for ($i = 0; $i < count($fieldASTs); $i++) {
            $selectionSet = $fieldASTs[$i]->selectionSet;
            if ($selectionSet) {
                $subFieldASTs = self::collectFields(
                    $exeContext,
                    $objectType,
                    $selectionSet,
                    $subFieldASTs,
                    $visitedFragmentNames
                );
            }
        }
        return $subFieldASTs;
    }

    /**
     * If a resolve function is not given, then a default resolve behavior is used
     * which takes the property of the source object of the same name as the field
     * and returns it as the result, or if it's a function, returns the result
     * of calling that function.
     */
    public static function defaultResolveFn($source, $args, ResolveInfo $info)
    {
        $fieldName = $info->fieldName;
        $property = null;

        if (is_array($source) || $source instanceof \ArrayAccess) {
            if (isset($source[$fieldName])) {
                $property = $source[$fieldName];
            }
        } else if (is_object($source)) {
            if (isset($source->{$fieldName})) {
                $property = $source->{$fieldName};
            }
        }

        return $property instanceof \Closure ? $property($source) : $property;
    }

    /**
     * This method looks up the field on the given type defintion.
     * It has special casing for the two introspection fields, __schema
     * and __typename. __typename is special because it can always be
     * queried as a field, even in situations where no other fields
     * are allowed, like on a Union. __schema could get automatically
     * added to the query type, but that would require mutating type
     * definitions, which would cause issues.
     *
     * @return FieldDefinition
     */
    private static function getFieldDef(Schema $schema, ObjectType $parentType, $fieldName)
    {
        $schemaMetaFieldDef = Introspection::schemaMetaFieldDef();
        $typeMetaFieldDef = Introspection::typeMetaFieldDef();
        $typeNameMetaFieldDef = Introspection::typeNameMetaFieldDef();

        if ($fieldName === $schemaMetaFieldDef->name && $schema->getQueryType() === $parentType) {
            return $schemaMetaFieldDef;
        } else if ($fieldName === $typeMetaFieldDef->name && $schema->getQueryType() === $parentType) {
            return $typeMetaFieldDef;
        } else if ($fieldName === $typeNameMetaFieldDef->name) {
            return $typeNameMetaFieldDef;
        }

        $tmp = $parentType->getFields();
        return isset($tmp[$fieldName]) ? $tmp[$fieldName] : null;
    }
}
