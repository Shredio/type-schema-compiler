<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler;

use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\Printer;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use Shredio\TypeSchema\Context\TypeContext;
use Shredio\TypeSchema\Error\ErrorElement;
use Shredio\TypeSchema\TypeSchema;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\ArrayNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\DumpNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\MethodNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\TypeSchemaCodeFormatter;
use Shredio\TypeSchemaCompiler\Attribute\CompileObjectMapper;
use Shredio\TypeSchemaCompiler\Context\CompileContext;

final readonly class MapperCodeBuilder
{

	/**
	 * @param ReflectionClass<object> $reflectionClass
	 * @param array<string, CompiledProperty> $properties
	 */
	public function build(ReflectionClass $reflectionClass, string $className, string $targetNamespace, string $targetShortName, array $properties): string
	{
		$definition = new ClassDefinition($className, $targetShortName, $targetNamespace);
		$this->initializeDefinition($definition);

		// parse()
		$this->buildParse($definition, $properties, $reflectionClass);

		// getTypeNode(TypeContext $context)
		$this->buildGetTypeNode($definition);

		return (new Printer())->printFile($definition->file);
	}

	/**
	 * @param array<string, CompiledProperty> $properties
	 * @param ReflectionClass<object> $reflectionClass
	 */
	private function buildParse(ClassDefinition $definition, array $properties, ReflectionClass $reflectionClass): void
	{
		$parseMethod = $this->createParseMethod($definition);
		$context = new CompileContext(
			sourceClassName: $reflectionClass->getName(),
			attribute: ($reflectionClass->getAttributes(CompileObjectMapper::class)[0] ?? null)?->newInstance() ?? new CompileObjectMapper(),
		);

		$this->initializeMethod($parseMethod, $definition, $context);

		$this->section($parseMethod, '0. Initialize TypeSchema');
		$this->initializeTypeSchema($parseMethod, $definition, 'ts');

		$this->section($parseMethod, '1. Define schema', 1);
		$this->defineSchema($parseMethod, $definition, $properties, $context, 'ts', 'schema');

		$this->section($parseMethod, '2. Map values', 1);
		$this->mapValues($parseMethod, 'schema', 'values');
		$this->returnOnError($parseMethod, 'values');

		$this->section($parseMethod, '3. Create a new instance', 1);
		$continue = $this->createNewInstance($parseMethod, $definition, $properties, $context, 'values', 'obj');

		if (!$continue) {
			return;
		}

		$this->section($parseMethod, '4. Set properties', 1);
		$this->setProperties($parseMethod, $properties, 'obj', 'values');
	}

	private function section(Method $method, string $comment, int $newLinesBefore = 0): void
	{
		for ($i = 0; $i < $newLinesBefore; $i++) {
			$method->addBody('');
		}
		$method->addBody('// ' . $comment);
	}

	/**
	 * @param array<string, CompiledProperty> $properties
	 */
	private function defineSchema(
		Method $method,
		ClassDefinition $definition,
		array $properties,
		CompileContext $context,
		string $typeSchemaVar,
		string $schemaVar,
	): void
	{
		$nodes = array_map(
			fn (CompiledProperty $property) => $property->typeSchemaNode,
			$properties,
		);
		$identifier = $context->attribute->identifier;

		$nodes = [new ArrayNode($nodes, true)];
		if ($identifier !== null) {
			if (!isset($properties[$identifier])) {
				throw new \LogicException(sprintf('Identifier property "%s" not found in class %s.', $identifier, $context->sourceClassName));
			}

			$nodes['identifier'] = new DumpNode($identifier);
		}

		$node = new MethodNode('arrayShape', $nodes);

		$formatter = new TypeSchemaCodeFormatter($definition->namespaceResolver, $typeSchemaVar);
		$method->addBody('$? = ?;', [$schemaVar, new Literal($formatter->format($node))]);
	}

	private function createParseMethod(ClassDefinition $definition): Method
	{
		$definition->namespaceResolver->addUse(TypeContext::class);
		$definition->namespaceResolver->addUse(ErrorElement::class);

		$method = $definition->class->addMethod('parse')
			->setPublic()
			->setReturnType(implode('|', [
				ErrorElement::class,
				$definition->mappedClassName,
			]));
		$method->addParameter('valueToParse')
			->setType('mixed');
		$method->addParameter('context')
			->setType(TypeContext::class);

		return $method;
	}

	private function initializeMethod(Method $method, ClassDefinition $definition, CompileContext $context): void
	{
		if ($context->attribute->contextFactory !== null) {
			$this->addContextFactory($method, $definition, $context->sourceClassName, $context->attribute->contextFactory);
		}
	}

	private function addContextFactory(Method $method, ClassDefinition $definition, string $className, string $methodName): void
	{
		$reflectionMethod = new ReflectionMethod($className, $methodName);
		if (!$reflectionMethod->isStatic() || !$reflectionMethod->isPublic()) {
			throw new \LogicException(sprintf('Context factory method %s::%s must be public static.', $className, $methodName));
		}

		// Check parameters
		$parameters = $reflectionMethod->getParameters();
		if (count($parameters) > 1) {
			throw new \LogicException(sprintf('Context factory method %s::%s must have zero or one parameter ($context).', $className, $methodName));
		}

		if (isset($parameters[0])) {
			$parameter = $parameters[0];
			$type = $parameter->getType();
			if (!$type instanceof ReflectionNamedType || $type->getName() !== TypeContext::class) {
				throw new \LogicException(sprintf('Parameter $context of context factory method %s::%s must be of type %s.', $className, $methodName, TypeContext::class));
			}
		}

		// Check return type
		$returnType = $reflectionMethod->getReturnType();
		if (!$returnType instanceof ReflectionNamedType || $returnType->getName() !== TypeContext::class) {
			throw new \LogicException(sprintf('Return type of context factory method %s::%s must be of type %s.', $className, $methodName, TypeContext::class));
		}

		$method->addBody(sprintf(
			'$context = %s::%s(%s);',
			$definition->namespaceResolver->shortName($className),
			$methodName,
			count($parameters) === 1 ? '$context' : '',
		));
		$method->addBody('');
	}

	private function initializeTypeSchema(Method $method, ClassDefinition $definition, string $typeSchemaVar): void
	{
		$method->addBody(sprintf('$%s = %s::get();', $typeSchemaVar, $definition->namespaceResolver->shortName(TypeSchema::class)));
	}

	private function mapValues(Method $method, string $schemaVar, string $valuesVar): void
	{
		$method->addBody('$? = $?->parse($valueToParse, $context);', [$valuesVar, $schemaVar]);
	}

	/**
	 * @param array<string, CompiledProperty> $properties
	 */
	private function createNewInstance(
		Method $method,
		ClassDefinition $definition,
		array $properties,
		CompileContext $context,
		string $valuesVar,
		string $objVar,
	): bool
	{
		$constructorParameters = [];
		$hasPropertiesToSet = false;
		foreach ($properties as $property) {
			if ($property->isInConstructor) {
				$constructorParameters[$property->name] = true;
			} else {
				$hasPropertiesToSet = true;
			}
		}

		$args = [];
		if ($constructorParameters !== []) {
			if (!$hasPropertiesToSet && !$context->attribute->discardExtraItems) {
				$expression = sprintf(
					'new %s(...$%s)',
					$definition->mappedShortName,
					$valuesVar
				);
			} else {
				$expression = sprintf(
					'new %s(...array_intersect_key($%s, ?))',
					$definition->mappedShortName,
					$valuesVar
				);
				$args[] = $constructorParameters;
			}
		} else {
			$expression = sprintf('new %s()', $definition->mappedShortName);
		}

		if ($hasPropertiesToSet) {
			$method->addBody(sprintf('$? = %s;', $expression), [$objVar, ...$args]);
		} else {
			$method->addBody(sprintf('return %s;', $expression), $args);
		}

		return $hasPropertiesToSet;
	}

	/**
	 * @param array<string, CompiledProperty> $properties
	 */
	private function setProperties(Method $parseMethod, array $properties, string $objVar, string $valuesVar): void
	{
		$requiredProperties = [];
		$optionalProperties = [];
		foreach ($properties as $property) {
			if ($property->isInConstructor) {
				continue;
			}

			if ($property->isRequired) {
				$requiredProperties[] = $property;
			} else {
				$optionalProperties[] = $property;
			}
		}

		foreach ($requiredProperties as $property) {
			$parseMethod->addBody('$?->? = $?[?];', [
				$objVar,
				$property->name,
				$valuesVar,
				$property->name,
			]);
		}

		if ($optionalProperties === []) {
			$parseMethod->addBody('');
			$parseMethod->addBody('return $?;', [$objVar]);
			return;
		}

		$parseMethod->addBody('');
		foreach ($optionalProperties as $property) {
			$parseMethod->addBody('if (array_key_exists(?, $?)) {', [
				$property->name,
				$valuesVar,
			]);
			$parseMethod->addBody("\t\$?->? = \$?[?];", [
				$objVar,
				$property->name,
				$valuesVar,
				$property->name,
			]);
			$parseMethod->addBody('}');
		}

		$parseMethod->addBody('');
		$parseMethod->addBody('return $?;', [$objVar]);
	}

	private function buildGetTypeNode(ClassDefinition $definition): void
	{
		$definition->namespaceResolver->addUse(IdentifierTypeNode::class);
		$definition->namespaceResolver->addUse(TypeNode::class);

		$method = $definition->class->addMethod('getTypeNode')
			->setProtected()
			->setReturnType(TypeNode::class);

		$method->addParameter('context')
			->setType(TypeContext::class);

		$method->setBody(
			sprintf(
				'return new %s(%s::class);',
				$definition->namespaceResolver->shortName(IdentifierTypeNode::class),
				$definition->mappedShortName,
			),
		);
	}

	private function returnOnError(Method $parseMethod, string $valuesVar): void
	{
		$parseMethod->addBody('if ($this->isError($?)) {', [$valuesVar]);
		$parseMethod->addBody("\treturn \$?;", [$valuesVar]);
		$parseMethod->addBody('}');
	}

	private function initializeDefinition(ClassDefinition $definition): void
	{
		$definition->class->addComment('');
		$definition->class->addComment(sprintf('@extends Type<%s>', $definition->mappedShortName));
	}

}
