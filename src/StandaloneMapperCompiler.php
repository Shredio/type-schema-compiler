<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler;

use Nette\PhpGenerator\Helpers;
use Nette\Utils\FileSystem;
use Shredio\TypeSchema\Mapper\DefaultObjectMapperProvider;
use Shredio\TypeSchema\Mapper\Jit\Attribute\CompileObjectMapper;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompileHashedInfoProvider;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompileInfoProvider;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompilerContext;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompileStaticInfoProvider;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperToCompile;
use Shredio\TypeSchema\Mapper\ObjectMapperProvider;
use Shredio\TypeSchema\Types\Type;
use SplFileInfo;

final readonly class StandaloneMapperCompiler
{

	public function __construct(
		private MapperCompiler $compiler,
		private ObjectMapperCompileInfoProvider $objectMapperCompileInfoProvider,
		private ObjectMapperProvider $objectMapperProvider,
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
			$objectMapperCompileInfoProvider = new ObjectMapperCompileHashedInfoProvider(
				$directoryPath,
				$mapperClassNamePattern,
			);
		} else {
			$objectMapperCompileInfoProvider = new ObjectMapperCompileStaticInfoProvider(
				$directoryPath,
				$mapperClassNamePattern,
			);
		}

		return new self(
			MapperCompiler::create(true, false),
			$objectMapperCompileInfoProvider,
			new DefaultObjectMapperProvider(),
		);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $className
	 * @return class-string<Type<T>>
	 */
	public function compile(string $className): string
	{
		$objectMapperToCompile = $this->objectMapperCompileInfoProvider->provide($className);
		$this->compiler->compile(
			$objectMapperToCompile,
			new ObjectMapperCompilerContext(
				$this->objectMapperCompileInfoProvider,
				fn (ObjectMapperToCompile $objectMapperToCompile): bool => $this->objectMapperProvider->provide($objectMapperToCompile->className) !== null,
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
