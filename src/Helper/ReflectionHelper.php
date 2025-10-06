<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Helper;

use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

final readonly class ReflectionHelper
{

	/**
	 * @param ReflectionClass<object> $reflectionClass
	 * @return array<string, ReflectionParameter>
	 */
	public static function getConstructorParameters(ReflectionClass $reflectionClass): array
	{
		$constructor = $reflectionClass->getConstructor();
		if ($constructor === null) {
			return [];
		}

		$parameters = [];
		foreach ($constructor->getParameters() as $parameter) {
			$parameters[$parameter->getName()] = $parameter;
		}

		return $parameters;
	}

	/**
	 * @param ReflectionClass<object> $reflectionClass
	 * @param array<string, ReflectionParameter> $constructorParameters
	 * @return array<string, ReflectionProperty>
	 */
	public static function getWritableProperties(ReflectionClass $reflectionClass, array $constructorParameters = []): array
	{
		$properties = [];
		foreach ($reflectionClass->getProperties() as $property) {
			if (isset($constructorParameters[$property->getName()])) {
				continue;
			}

			if (self::isWritableFromOutside($property)) {
				$properties[$property->getName()] = $property;
			}
		}

		return $properties;
	}

	public static function isReadableFromOutside(ReflectionProperty $property): bool
	{
		if (PHP_VERSION_ID >= 80400) {
			if ($property->hasHooks()) {
				return $property->hasHook(\PropertyHookType::Get);
			}
		}

		return $property->isPublic();
	}

	public static function isWritableFromOutside(ReflectionProperty $property): bool
	{
		if ($property->isReadOnly()) {
			return false;
		}
		if (PHP_VERSION_ID >= 80400) {
			if ($property->hasHooks()) {
				return $property->hasHook(\PropertyHookType::Set);
			}
			if ($property->isProtectedSet() || $property->isPrivateSet()) {
				return false;
			}
		}

		return $property->isPublic();
	}

}
