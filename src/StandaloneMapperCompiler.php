<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler;

use Nette\PhpGenerator\Helpers;
use Nette\Utils\FileSystem;
use Shredio\TypeSchema\Mapper\ClassMapperProvider;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompileHashedTargetProvider;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompileStaticTargetProvider;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompileTargetProvider;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperToCompile;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompilerContext;
use Shredio\TypeSchema\Mapper\RegistryClassMapperProvider;
use Shredio\TypeSchema\Types\Type;
use Shredio\TypeSchemaCompiler\Attribute\CompileObjectMapper;
use SplFileInfo;

final readonly class StandaloneMapperCompiler
{

	public function __construct(
		private MapperCompiler $compiler,
		private ClassMapperCompileTargetProvider $classMapperCompileTargetProvider,
		private ClassMapperProvider $classMapperProvider,
	)
	{
	}

	/**
	 * @param non-empty-string $directoryPath
	 * @param non-empty-string $mapperClassNamePattern
	 */
	public static function create(
		string $directoryPath,
		string $mapperClassNamePattern,
		bool $hashed = false,
	): self
	{
		if ($hashed) {
			$objectMapperCompileInfoProvider = new ClassMapperCompileHashedTargetProvider(
				$directoryPath,
				$mapperClassNamePattern,
			);
		} else {
			$objectMapperCompileInfoProvider = new ClassMapperCompileStaticTargetProvider(
				$directoryPath,
				$mapperClassNamePattern,
			);
		}

		return new self(
			MapperCompiler::create(true, false),
			$objectMapperCompileInfoProvider,
			new RegistryClassMapperProvider(RegistryClassMapperProvider::createDefaultClassMappers())
		);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $className
	 * @return class-string<Type<T>>
	 */
	public function compile(string $className): string
	{
		$objectMapperToCompile = $this->classMapperCompileTargetProvider->provide($className);
		$this->compiler->compile(
			$objectMapperToCompile,
			new ObjectMapperCompilerContext(
				$this->classMapperCompileTargetProvider,
				fn (ClassMapperToCompile $classMapperToCompile): bool => $this->classMapperProvider->provide($classMapperToCompile->className) === null,
			),
		);

		return $objectMapperToCompile->mapperClassName;
	}

	/**
	 * @param iterable<SplFileInfo> $files
	 */
	public function compileByAttributes(iterable $files): void
	{
		$attributeShortName = Helpers::extractShortName(CompileObjectMapper::class);
		$findNeedle = sprintf('#[%s', $attributeShortName);
		foreach ($files as $file) {
			$contents = FileSystem::read($file->getPathname());
			if (!str_contains($contents, $findNeedle)) {
				continue;
			}

			$namespace = $this->extractNamespaceFromContent($contents);
			$className = $this->extractClassNameFromContent($contents);

			if ($namespace === null) {
				throw new \RuntimeException(sprintf('Cannot extract namespace from file %s', $file->getRealPath()));
			}
			if ($className === null) {
				throw new \RuntimeException(sprintf('Cannot extract class name from file %s', $file->getRealPath()));
			}

			$fullClassName = $namespace . '\\' . $className;
			if (!class_exists($fullClassName)) {
				throw new \RuntimeException(sprintf('Class %s does not exist from file %s', $fullClassName, $file->getRealPath()));
			}

			$reflectionClass = new \ReflectionClass($fullClassName);
			if ($reflectionClass->getAttributes(CompileObjectMapper::class) === []) {
				continue;
			}

			$this->compile($fullClassName);
		}
	}

	private function extractNamespaceFromContent(string $contents): ?string
	{
		if (preg_match('#^namespace\s+([^;]+);#m', $contents, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}

	private function extractClassNameFromContent(string $contents): ?string
	{
		if (preg_match('#class\s+([^\s{]+)#m', $contents, $matches)) {
			return $matches[1];
		}

		return null;
	}

}
