<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler;

use InvalidArgumentException;
use Nette\PhpGenerator\Helpers;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\ClassNameNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\DumpNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\MethodNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\NewClassNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\TypeSchemaNode;
use Shredio\TypeSchemaCompiler\Helper\ReflectionHelper;
use Shredio\TypeSchemaCompiler\Lock\FileLock;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompiler;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompilerContext;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperToCompile;
use Shredio\TypeSchema\TypeSystem\TypeNodeHelper;

final class MapperCompiler implements ObjectMapperCompiler
{

	/** @var array<class-string, bool> */
	private array $compiled = [];

	public function __construct(
		private readonly Lexer $phpDocLexer,
		private readonly PhpDocParser $phpDocParser,
		private readonly bool $autoRefresh = false,
		private readonly bool $multiProcessSafe = true,
	)
	{
	}

	public static function create(bool $autoRefresh = false, bool $multiProcessSafe = true): self
	{
		$config = new ParserConfig([]);
		$phpDocLexer = new Lexer($config);
		$constExprParser = new ConstExprParser($config);
		$phpDocParser = new PhpDocParser(
			$config,
			new TypeParser($config, $constExprParser),
			$constExprParser,
		);

		return new self($phpDocLexer, $phpDocParser, $autoRefresh, $multiProcessSafe);
	}

	public function compile(ObjectMapperToCompile $objectMapperData, ObjectMapperCompilerContext $context): void
	{
		if (isset($this->compiled[$objectMapperData->className])) {
			return;
		}

		$this->compiled[$objectMapperData->className] = true;
		$reflectionClass = new ReflectionClass($objectMapperData->className);

		if ($this->multiProcessSafe) {
			$transaction = (new FileLock($objectMapperData->targetFilePath))->transaction(...);
		} else {
			$transaction = fn (callable $callback): mixed => $callback();
		}

		$transaction(function () use ($context, $objectMapperData, $reflectionClass): void {
			$code = $this->getCode($reflectionClass, $objectMapperData->mapperClassName, $context);
			$path = $objectMapperData->targetFilePath;

			if (file_put_contents("$path.tmp", $code) !== strlen($code) || !rename("$path.tmp", $path)) {
				@unlink("$path.tmp"); // @ file may not exist
				throw new RuntimeException("Unable to create '$path'.");
			}
		});
	}

	public function needsRecompile(ObjectMapperToCompile $objectMapperData): bool
	{
		return $this->autoRefresh;
	}

	/**
	 * @param ReflectionClass<object> $reflectionClass
	 */
	private function getCode(
		ReflectionClass $reflectionClass,
		string $mapperClassName,
		ObjectMapperCompilerContext $context,
	): string
	{
		$builder = new MapperCodeBuilder();

		return $builder->build(
			$reflectionClass->getName(),
			Helpers::extractNamespace($mapperClassName),
			Helpers::extractShortName($mapperClassName),
			$this->compileOnlyProperties($reflectionClass, $context),
		);
	}

	/**
	 * @param ReflectionClass<object> $reflectionClass
	 * @return array<string, CompiledProperty>
	 */
	public function compileOnlyProperties(ReflectionClass $reflectionClass, ObjectMapperCompilerContext $context): array
	{
		$properties = [];
		$docTypes = $this->getPhpDocTypes($reflectionClass);
		foreach (ReflectionHelper::getConstructorParameters($reflectionClass) as $parameter) {
			$typeNode = $this->compileType($reflectionClass, $parameter, $docTypes, $context);
			if ($parameter->isOptional()) {
				$typeNode = $this->createOptionalProperty($typeNode);
			}

			$properties[$parameter->getName()] = new CompiledProperty(
				$parameter->getName(),
				true,
				$parameter->isOptional(),
				$typeNode,
			);
		}

		foreach (ReflectionHelper::getWritableProperties($reflectionClass) as $property) {
			if (isset($properties[$property->getName()])) {
				continue;
			}

			$typeNode = $this->compileType($reflectionClass, $property, $docTypes, $context);
			if ($property->hasDefaultValue()) {
				$typeNode = $this->createOptionalProperty($typeNode);
			}

			$properties[$property->getName()] = new CompiledProperty(
				$property->getName(),
				false,
				!$property->hasDefaultValue(),
				$typeNode,
			);
		}

		return $properties;
	}

	/**
	 * @param ReflectionClass<object> $reflectionClass
	 * @return array<string, TypeNode>
	 */
	private function getPhpDocTypes(ReflectionClass $reflectionClass): array
	{
		$constructor = $reflectionClass->getConstructor();
		$parameterTypes = [];

		if ($constructor !== null) {
			$declaringClass = $constructor->getDeclaringClass();

			$constructorDocComment = $constructor->getDocComment();
			if ($constructorDocComment !== false && $constructorDocComment !== '') {
				foreach ($this->parsePhpDoc($constructorDocComment)->children as $node) {
					if ($node instanceof PhpDocTagNode && $node->value instanceof ParamTagValueNode) {
						TypeNodeHelper::resolveClassKeywords($node->value->type, $declaringClass);
						$parameterName = substr($node->value->parameterName, 1);
						$parameterTypes[$parameterName] = $node->value->type;
					}
				}
			}
		}

		foreach (ReflectionHelper::getWritableProperties($reflectionClass) as $property) {
			if (isset($parameterTypes[$property->getName()])) {
				continue;
			}

			$propertyDocComment = $property->getDocComment();
			if ($propertyDocComment === false || $propertyDocComment === '') {
				continue;
			}

			$parameterName = $property->getName();
			foreach ($this->parsePhpDoc($propertyDocComment)->children as $node) {
				if (
					$node instanceof PhpDocTagNode
					&& $node->value instanceof VarTagValueNode
					&& ($node->value->variableName === '' || substr($node->value->variableName, 1) === $parameterName)
				) {
					TypeNodeHelper::resolveClassKeywords($node->value->type, $property->getDeclaringClass());
					$parameterTypes[$parameterName] = $node->value->type;
				}
			}
		}

		return $parameterTypes;
	}

	private function createOptionalProperty(TypeSchemaNode $child): TypeSchemaNode
	{
		return new MethodNode('optional', [$child]);
	}

	/**
	 * @param class-string $className
	 */
	private function createForInnerClass(string $className, ObjectMapperCompilerContext $context): TypeSchemaNode
	{
		$objectMapperToCompile = $context->createObjectMapperToCompile($className);
		if ($context->hasProviderFor($objectMapperToCompile)) {
			return new MethodNode('mapper', [new ClassNameNode($className)]);
		}

		$this->compile($objectMapperToCompile, $context);

		return new NewClassNode($objectMapperToCompile->mapperClassName);
	}

	private function parsePhpDoc(string $docComment): PhpDocNode
	{
		$tokens = $this->phpDocLexer->tokenize($docComment);
		return $this->phpDocParser->parse(new TokenIterator($tokens));
	}

	/**
	 * @param ReflectionClass<object> $reflectionClass
	 * @param array<string, TypeNode> $docTypes
	 */
	private function compileType(
		ReflectionClass $reflectionClass,
		ReflectionParameter|ReflectionProperty $reflection,
		array $docTypes,
		ObjectMapperCompilerContext $context,
	): TypeSchemaNode
	{
		if (isset($docTypes[$reflection->getName()])) {
			$typeNode = $docTypes[$reflection->getName()];
		} else {
			$typeNode = TypeNodeHelper::fromReflection($reflection);
			if ($typeNode !== null) {
				TypeNodeHelper::resolveClassKeywords($typeNode, $reflectionClass);
			} else {
				$typeNode = new IdentifierTypeNode('mixed');
			}
		}

		return $this->createFromTypeNode($typeNode, $context);
	}

	private function createFromTypeNode(TypeNode $typeNode, ObjectMapperCompilerContext $context): TypeSchemaNode
	{
		$typeNode = $this->normalizeType($typeNode);

		if ($typeNode instanceof IdentifierTypeNode) {
			if (!TypeNodeHelper::isKeyword($typeNode)) {
				if (!class_exists($typeNode->name) && !interface_exists($typeNode->name)) {
					throw new InvalidArgumentException("Class or interface '{$typeNode->name}' does not exist.");
				}

				return $this->createForInnerClass($typeNode->name, $context);
			}

			return match ($typeNode->name) {
				'mixed' => new MethodNode('mixed'),
				'null' => new MethodNode('null'),
				'bool' => new MethodNode('bool'),
				'int' => new MethodNode('int'),
				'float' => new MethodNode('float'),
				'string' => new MethodNode('string'),
				'array' => new MethodNode('array'),
				'object' => new MethodNode('object'),
				'non-empty-string' => new MethodNode('nonEmptyString'),
				default => $this->invalidTypeNode($typeNode),
			};
		}

		if ($typeNode instanceof ArrayTypeNode) {
			return new MethodNode('array', [
				new MethodNode('arrayKey'),
				$this->createFromTypeNode($typeNode->type, $context),
			]);
		}

		if ($typeNode instanceof NullableTypeNode) {
			return new MethodNode('nullable', [
				$this->createFromTypeNode($typeNode->type, $context),
			]);
		}

		if ($typeNode instanceof GenericTypeNode) {
			return match (strtolower($typeNode->type->name)) {
				'array' => match (count($typeNode->genericTypes)) {
					1 => new MethodNode('array', [
						new MethodNode('arrayKey'),
						$this->createFromTypeNode($typeNode->genericTypes[0], $context),
					]),
					2 => new MethodNode('array', [
						$this->createFromTypeNode($typeNode->genericTypes[0], $context),
						$this->createFromTypeNode($typeNode->genericTypes[1], $context),
					]),
					default => $this->invalidTypeNode($typeNode),
				},
				'int' => match (count($typeNode->genericTypes)) {
					2 => new MethodNode('intRange', [
						new DumpNode($this->resolveIntegerBoundary($typeNode, $typeNode->genericTypes[0], 'min')),
						new DumpNode($this->resolveIntegerBoundary($typeNode, $typeNode->genericTypes[1], 'max')),
					]),
					default => $this->invalidTypeNode($typeNode),
				},
				'list' => match (count($typeNode->genericTypes)) {
					1 => new MethodNode('list', [
						$this->createFromTypeNode($typeNode->genericTypes[0], $context),
					]),
					default => $this->invalidTypeNode($typeNode),
				},
				'non-empty-list' => match (count($typeNode->genericTypes)) {
					1 => new MethodNode('nonEmptyList', [
						$this->createFromTypeNode($typeNode->genericTypes[0], $context),
					]),
					default => $this->invalidTypeNode($typeNode),
				},
				default => $this->invalidTypeNode($typeNode),
			};
		}

		if ($typeNode instanceof UnionTypeNode) {
			return new MethodNode(
				'union',
				array_values(array_map(fn (TypeNode $type) => $this->createFromTypeNode($type, $context), $typeNode->types)),
			);
		}

		$this->invalidTypeNode($typeNode);
	}

	private function invalidTypeNode(TypeNode $node): never
	{
		throw new InvalidArgumentException(sprintf('Unsupported type node "%s" of class %s.', $node, $node::class));
	}

	private function resolveIntegerBoundary(
		TypeNode $type,
		TypeNode $boundaryType,
		string $extremeName,
	): ?int
	{
		if ($boundaryType instanceof ConstTypeNode && $boundaryType->constExpr instanceof ConstExprIntegerNode) {
			return (int) $boundaryType->constExpr->value;
		}

		if ($boundaryType instanceof IdentifierTypeNode && $boundaryType->name === $extremeName) {
			return null;
		}

		throw new InvalidArgumentException(
			sprintf('Cannot resolve type "%s", integer boundary %s is not supported.', $type, $boundaryType),
		);
	}

	private function normalizeType(TypeNode $type): TypeNode
	{
		if ($type instanceof IdentifierTypeNode) {
			return match (strtolower($type->name)) {
				'double' => new IdentifierTypeNode('float'),
				'integer' => new IdentifierTypeNode('int'),
				'negative-int' => new GenericTypeNode(new IdentifierTypeNode('int'), [
					new IdentifierTypeNode('min'),
					new ConstTypeNode(new ConstExprIntegerNode('-1')),
				]),
				'non-negative-int' => new GenericTypeNode(new IdentifierTypeNode('int'), [
					new ConstTypeNode(new ConstExprIntegerNode('0')),
					new IdentifierTypeNode('max'),
				]),
				'non-positive-int' => new GenericTypeNode(new IdentifierTypeNode('int'), [
					new IdentifierTypeNode('min'),
					new ConstTypeNode(new ConstExprIntegerNode('0')),
				]),
				'noreturn' => new IdentifierTypeNode('never'),
				'number' => new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('float')]),
				'positive-int' => new GenericTypeNode(new IdentifierTypeNode('int'), [
					new ConstTypeNode(new ConstExprIntegerNode('1')),
					new IdentifierTypeNode('max'),
				]),
				'scalar' => new UnionTypeNode([
					new IdentifierTypeNode('int'),
					new IdentifierTypeNode('float'),
					new IdentifierTypeNode('string'),
					new IdentifierTypeNode('bool'),
				]),
				default => TypeNodeHelper::isKeyword($type) ? new IdentifierTypeNode(strtolower($type->name)) : $type,
			};
		}

		if ($type instanceof UnionTypeNode) {
			$newType = TypeNodeHelper::removeNullFromUnion($type);
			if ($newType !== null) {
				return new NullableTypeNode(self::normalizeType($newType));
			}

			return $type;
		}

		return $type;
	}

}
