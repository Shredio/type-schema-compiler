<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Symfony;

use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompileHashedTargetProvider;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompiler;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompileTargetProvider;
use Shredio\TypeSchema\Mapper\Jit\JustInTimeClassMapperProvider;
use Shredio\TypeSchema\Symfony\TypeSchemaBundle;
use Shredio\TypeSchemaCompiler\MapperCompiler;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TypeSchemaJitBundle extends AbstractBundle
{

	/**
	 * @param mixed[] $config
	 */
	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
		$services = $container->services();

		$services->set($this->prefix('class_mapper_target_provider'), ClassMapperCompileHashedTargetProvider::class)
			->args([
				'$directoryPath' => $config['target_dir'],
				'$mapperClassNamePattern' => $config['class_name_pattern'],
			])
			->alias(ClassMapperCompileTargetProvider::class, $this->prefix('class_mapper_target_provider'));

		$services->set($this->prefix('compiler'), MapperCompiler::class)
			->factory([MapperCompiler::class, 'create'])
			->args([
				'$autoRefresh' => $config['auto_refresh'],
			])
			->alias(ClassMapperCompiler::class, $this->prefix('compiler'));

		$services->set(JustInTimeClassMapperProvider::class)
			->decorate(TypeSchemaBundle::ClassMapperProviderServiceName)
			->args([
				service($this->prefix('class_mapper_target_provider')),
				'$innerProvider' => service('.inner'),
				'$compiler' => service($this->prefix('compiler')),
				'$raiseWarningsOnMissingClasses' => $config['raise_warnings_on_missing_classes'],
			]);
	}

	public function configure(DefinitionConfigurator $definition): void
	{
		$definition->rootNode() // @phpstan-ignore-line
			->addDefaultsIfNotSet()
			->children()
				->booleanNode('auto_refresh')
					->info('Automatically refresh compiled mappers when source changes')
					->defaultValue('%kernel.debug%')
				->end()
				->scalarNode('class_name_pattern')
					->info('Class name pattern for generated mapper classes')
					->defaultValue('TypeSchemaMapper\%sMapper')
				->end()
				->scalarNode('target_dir')
					->info('Directory where compiled mappers will be stored')
					->defaultValue('%kernel.cache_dir%/type-schema-mappers')
				->end()
				->scalarNode('raise_warnings_on_missing_classes')
					->defaultFalse()
				->end()
			->end();
	}

	private function prefix(string $name): string
	{
		return sprintf('type_schema_jit.%s', $name);
	}

}
