<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler;

use Nette\PhpGenerator\PhpNamespace;

final class NamespaceResolver
{

	/** @var array<string, string|null> */
	private array $classNames = [];

	public function __construct(
		private readonly PhpNamespace $namespace,
	)
	{
	}

	public function addUse(string $className): string
	{
		if (!array_key_exists($className, $this->classNames)) {
			$this->namespace->addUse($className);
			$this->classNames[$className] = null;

			return $className;
		}

		return $className;
	}

	public function shortName(string $className): string
	{
		if (!array_key_exists($className, $this->classNames)) {
			$this->addUse($className);
			return $this->classNames[$className] = $this->namespace->simplifyName($className);
		}

		return $this->classNames[$className] ??= $this->namespace->simplifyName($className);
	}

}
