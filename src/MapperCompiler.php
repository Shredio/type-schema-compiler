<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler;

use InvalidArgumentException;
use Nette\PhpGenerator\Helpers;
use Nette\Utils\FileSystem;
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
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompiler;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperToCompile;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompilerContext;
use Shredio\TypeSchema\TypeSystem\TypeNodeHelper;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\ArrayNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\ClassNameNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\DumpNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\MethodNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\NewClassNode;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\TypeSchemaNode;
use Shredio\TypeSchemaCompiler\Attribute\PropertyCompileOptions;
use Shredio\TypeSchemaCompiler\Exception\CompileException;
use Shredio\TypeSchemaCompiler\Helper\ReflectionHelper;
use Shredio\TypeSchemaCompiler\Lock\FileLock;

final class MapperCompiler implements ClassMapperCompiler
{

	/** @var array<class-string, bool> */
	private array $compiled = [];

	private bool $tempDirCreated = false;

	private bool $validationMode = false;

	public function __construct(
		private readonly Lexer $phpDocLexer,
		private readonly PhpDocParser $phpDocParser,
		private readonly bool $autoRefresh = false,
		private readonly bool $multiProcessSafety = true,
	)
	{
	}

	public static function create(bool $autoRefresh = false, bool $multiProcessSafety = true): self
	{
		$config = new ParserConfig([]);
		$phpDocLexer = new Lexer($config);
		$constExprParser = new ConstExprParser($config);
		$phpDocParser = new PhpDocParser(
			$config,
			new TypeParser($config, $constExprParser),
			$constExprParser,
		);

		return new self($phpDocLexer, $phpDocParser, $autoRefresh, $multiProcessSafety);
	}

	public function withMultiProcessSafety(bool $enabled): static
	{
		return new self($this->phpDocLexer, $this->phpDocParser, $this->autoRefresh, $enabled);
	}


	public function withValidationMode(): static
	{
		$self = new self($this->phpDocLexer, $this->phpDocParser, true, false);
		$self->validationMode = true;

		return $self;
	}

	public function compile(ClassMapperToCompile $objectMapperData, ObjectMapperCompilerContext $context): void
	{
		if (isset($this->compiled[$objectMapperData->className])) {
			return;
		}

		if (!$this->tempDirCreated && !$this->validationMode) {
			$this->tempDirCreated = true;
			$dir = dirname($objectMapperData->targetFilePath);
			if (!is_dir($dir)) {
				FileSystem::createDir($dir);
			}
		}

		$this->compiled[$objectMapperData->className] = true;
		$reflectionClass = new ReflectionClass($objectMapperData->className);
		if (!$reflectionClass->isInstantiable()) {
			if ($reflectionClass->isInterface()) {
				throw new CompileException(sprintf('Cannot compile class mapper for interface %s.', $objectMapperData->className));
			}
			if ($reflectionClass->isTrait()) {
				throw new CompileException(sprintf('Cannot compile class mapper for trait %s.', $objectMapperData->className));
			}
			if ($reflectionClass->isEnum()) {
				throw new CompileException(sprintf('Cannot compile class mapper for enum %s.', $objectMapperData->className));
			}
			if ($reflectionClass->isAnonymous()) {
				throw new CompileException(sprintf('Cannot compile class mapper for anonymous class %s.', $objectMapperData->className));
			}

			throw new CompileException(sprintf('Cannot compile class mapper for non-instantiable class %s.', $objectMapperData->className));
		}

		if ($this->multiProcessSafety) {
			$transaction = (new FileLock($objectMapperData->targetFilePath))->transaction(...);
		} else {
			$transaction = fn (callable $callback): mixed => $callback();
		}

		$transaction(function () use ($context, $objectMapperData, $reflectionClass): void {
			$code = $this->getCode($reflectionClass, $objectMapperData->mapperClassName, $context);

			if ($this->validationMode) {
				return;
			}

			$path = $objectMapperData->targetFilePath;
			if (file_put_contents("$path.tmp", $code) !== strlen($code) || !rename("$path.tmp", $path)) {
				@unlink("$path.tmp"); // @ file may not exist
				throw new RuntimeException("Unable to create '$path'.");
			}
		});
	}

	public function needsRecompile(ClassMapperToCompile $objectMapperData): bool
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
		if (str_starts_with($reflectionClass->getName(), 'class@anonymous')) {
			throw new \LogicException('Cannot build mapper for anonymous classes.');
		}

		return $builder->build(
			$reflectionClass,
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
			$options = $this->getPropertyOptionsAttribute($parameter);
			$typeNode = $this->compileType($reflectionClass, $parameter, $docTypes, $options, $context);
			if ($isOptional = $this->isParameterOptional($parameter, $options)) {
				$typeNode = $this->createOptionalProperty($typeNode);
			}

			$properties[$parameter->getName()] = new CompiledProperty(
				$parameter->getName(),
				true,
				!$isOptional,
				$typeNode,
			);
		}

		foreach (ReflectionHelper::getWritableProperties($reflectionClass) as $property) {
			if (isset($properties[$property->getName()])) {
				continue;
			}

			$options = $this->getPropertyOptionsAttribute($property);
			$typeNode = $this->compileType($reflectionClass, $property, $docTypes, $options, $context);
			if ($isOptional = $this->isPropertyOptional($property, $options)) {
				$typeNode = $this->createOptionalProperty($typeNode);
			}

			$properties[$property->getName()] = new CompiledProperty(
				$property->getName(),
				false,
				!$isOptional,
				$typeNode,
			);
		}

		return $properties;
	}

	private function isParameterOptional(ReflectionParameter $parameter, PropertyCompileOptions $options): bool
	{
		if (is_bool($options->optional)) {
			return $options->optional;
		}

		return $parameter->isOptional();
	}

	private function isPropertyOptional(ReflectionProperty $property, PropertyCompileOptions $options): bool
	{
		if (is_bool($options->optional)) {
			return $options->optional;
		}

		return $property->hasDefaultValue();
	}

	private function getPropertyOptionsAttribute(ReflectionParameter|ReflectionProperty $reflection): PropertyCompileOptions
	{
		$attribute = $reflection->getAttributes(PropertyCompileOptions::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
		if ($attribute === null) {
			return new PropertyCompileOptions();
		}

		/** @var PropertyCompileOptions */
		return $attribute->newInstance();
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
		$ClassMapperToCompile = $context->createClassMapperToCompile($className);
		if ($context->hasProviderFor($ClassMapperToCompile)) {
			return new MethodNode('mapper', [new ClassNameNode($className)]);
		}

		$this->compile($ClassMapperToCompile, $context);

		return new NewClassNode($ClassMapperToCompile->mapperClassName);
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
		PropertyCompileOptions $options,
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

		return $this->createFromTypeNode($typeNode, $options, $context);
	}

	private function createFromTypeNode(TypeNode $typeNode, PropertyCompileOptions $options, ObjectMapperCompilerContext $context): TypeSchemaNode
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
				$this->createFromTypeNode($typeNode->type, $options, $context),
			]);
		}

		if ($typeNode instanceof NullableTypeNode) {
			$args = [$this->createFromTypeNode($typeNode->type, $options, $context)];
			if ($options->nullValues !== []) {
				$args[] = new DumpNode($options->nullValues);
			}

			return new MethodNode('nullable', $args);
		}

		if ($typeNode instanceof GenericTypeNode) {
			return match (strtolower($typeNode->type->name)) {
				'array' => match (count($typeNode->genericTypes)) {
					1 => new MethodNode('array', [
						new MethodNode('arrayKey'),
						$this->createFromTypeNode($typeNode->genericTypes[0], $options, $context),
					]),
					2 => new MethodNode('array', [
						$this->createFromTypeNode($typeNode->genericTypes[0], $options, $context),
						$this->createFromTypeNode($typeNode->genericTypes[1], $options, $context),
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
						$this->createFromTypeNode($typeNode->genericTypes[0], $options, $context),
					]),
					default => $this->invalidTypeNode($typeNode),
				},
				'non-empty-list' => match (count($typeNode->genericTypes)) {
					1 => new MethodNode('nonEmptyList', [
						$this->createFromTypeNode($typeNode->genericTypes[0], $options, $context),
					]),
					default => $this->invalidTypeNode($typeNode),
				},
				default => $this->invalidTypeNode($typeNode),
			};
		}

		if ($typeNode instanceof UnionTypeNode) {
			return new MethodNode(
				'union',
				[new ArrayNode(array_values(array_map(fn (TypeNode $type): TypeSchemaNode => $this->createFromTypeNode($type, $options, $context), $typeNode->types)))],
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
