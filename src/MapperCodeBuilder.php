<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler;

use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\Printer;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use Shredio\TypeSchema\Context\TypeContext;
use Shredio\TypeSchema\Error\ErrorElement;
use Shredio\TypeSchema\TypeSchema;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\ArrayNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\DumpNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\MethodNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\TypeSchemaCodeFormatter;
use Shredio\TypeSchemaCompiler\Attribute\CompileObjectMapper;

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

		$this->section($parseMethod, '0. Initialize TypeSchema');
		$this->initializeTypeSchema($parseMethod, $definition, 'ts');

		$this->section($parseMethod, '1. Define schema', 1);
		$this->defineSchema($parseMethod, $definition, $properties, $reflectionClass, 'ts', 'schema');

		$this->section($parseMethod, '2. Map values', 1);
		$this->mapValues($parseMethod, 'schema', 'values');
		$this->returnOnError($parseMethod, 'values');

		$this->section($parseMethod, '3. Create a new instance', 1);
		$continue = $this->createNewInstance($parseMethod, $definition, $properties, 'values', 'obj');

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
	 * @param ReflectionClass<object> $reflectionClass
	 */
	private function defineSchema(
		Method $method,
		ClassDefinition $definition,
		array $properties,
		ReflectionClass $reflectionClass,
		string $typeSchemaVar,
		string $schemaVar,
	): void
	{
		$nodes = array_map(
			fn (CompiledProperty $property) => $property->typeSchemaNode,
			$properties,
		);
		/** @var CompileObjectMapper|null $attribute */
		$attribute = ($reflectionClass->getAttributes(CompileObjectMapper::class)[0] ?? null)?->newInstance();
		$identifier = $attribute?->identifier;

		$nodes = [new ArrayNode($nodes, true)];
		if ($identifier !== null) {
			if (!isset($properties[$identifier])) {
				throw new \LogicException(sprintf('Identifier property "%s" not found in class %s.', $identifier, $reflectionClass->getName()));
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
		string $valuesVar,
		string $objVar,
	): bool
	{
		$constructorParameters = [];
		$return = false;
		foreach ($properties as $property) {
			if ($property->isInConstructor) {
				$constructorParameters[$property->name] = true;
			} else {
				$return = true;
			}
		}

		$args = [];
		if ($constructorParameters !== []) {
			$expression = sprintf('new %s(...array_intersect_key($%s, ?))', $definition->mappedShortName, $valuesVar);
			$args[] = $constructorParameters;
		} else {
			$expression = sprintf('new %s()', $definition->mappedShortName);
		}

		if ($return) {
			$method->addBody(sprintf('$? = %s;', $expression), [$objVar, ...$args]);
		} else {
			$method->addBody(sprintf('return %s;', $expression), $args);
		}

		return $return;
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
			->setPublic()
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
