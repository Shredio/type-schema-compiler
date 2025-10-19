<?php declare(strict_types = 1);

namespace Tests;

use LogicException;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Shredio\TypeSchema\Context\TypeContext;
use Shredio\TypeSchema\Mapper\ClassMapper;
use Shredio\TypeSchema\Mapper\ClassMapperProvider;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompileHashedTargetProvider;
use Shredio\TypeSchema\Mapper\Jit\JustInTimeClassMapperProvider;
use Shredio\TypeSchema\Mapper\RegistryClassMapperProvider;
use Shredio\TypeSchemaCompiler\MapperCompiler;
use Shredio\TypeSchemaCompiler\StandaloneMapperCompiler;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{

	protected function setUp(): void
	{
		parent::setUp();

		foreach (Finder::findFiles('*.php')->in(__DIR__ . '/Generated') as $file) {
			FileSystem::delete($file->getPathname());
		}
	}

	/**
	 * @param class-string $className
	 */
	protected function compile(string $className): string
	{
		$compiler = StandaloneMapperCompiler::create(
			__DIR__ . '/Generated',
			'Tests\\Generated\\%sMapper',
			true,
		);

		$className = $compiler->compile($className);
		$reflection = new \ReflectionClass($className);
		$file = $reflection->getFileName();
		if ($file === false) {
			throw new \RuntimeException('Cannot get file name of compiled class.');
		}

		return FileSystem::read($file);
	}

	/**
	 * @param list<class-string> $mappers
	 */
	public function createObjectMapperProvider(array $mappers = []): ClassMapperProvider
	{
		$classMappers = [];
		foreach ($mappers as $mapper) {
			$classMappers[] = new readonly class($mapper) extends ClassMapper {

				/**
				 * @param class-string<object> $className
				 */
				public function __construct(
					private string $className,
				)
				{
				}

				public function isSupported(string $className): bool
				{
					return is_a($className, $this->className, true);
				}

				public function create(string $className, mixed $valueToParse, TypeContext $context): object
				{
					throw new LogicException('This should not be called in tests.');
				}

			};
		}

		return new JustInTimeClassMapperProvider(
			new ClassMapperCompileHashedTargetProvider(__DIR__ . '/Generated', 'Tests\\Generated\\%sMapper'),
			MapperCompiler::create(true, false),
			new RegistryClassMapperProvider([...$classMappers, ...RegistryClassMapperProvider::createDefaultClassMappers()]),
		);
	}

	/**
	 * @param class-string $className
	 */
	protected function assertCompiledSameAsFile(string $expectedFile, string $className): void
	{
		$this->assertStringEqualsFile($expectedFile, $this->compile($className));
	}

	/**
	 * @param class-string $className
	 * @param list<class-string> $mappers
	 */
	protected function assertCompiledFromObjectMapperProviderSameAsFile(string $expectedFile, string $className, array $mappers = []): void
	{
		$type = $this->createObjectMapperProvider($mappers)->provide($className);

		$reflection = new \ReflectionClass($type::class);
		$file = $reflection->getFileName();
		if ($file === false) {
			throw new \RuntimeException('Cannot get file name of compiled class.');
		}

		$this->assertStringEqualsFile($expectedFile, FileSystem::read($file));
	}

	protected function assertCreatedMapperCount(int $expected): void
	{
		$files = iterator_to_array(Finder::findFiles('*.php')->in(__DIR__ . '/Generated'));
		$this->assertCount($expected, $files);
	}

}
