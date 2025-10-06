<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Lock;

use RuntimeException;

final readonly class FileLock
{

	private string $file;

	public function __construct(string $file)
	{
		$this->file = sprintf('%s.lock', $file);
	}

	public function lock(): callable
	{
		$handle = fopen($this->file, 'c+');
		if ($handle === false) {
			throw new RuntimeException(sprintf('Unable to write lock "%s".', $this->file));
		}
		if (!flock($handle, LOCK_EX)) {
			throw new RuntimeException(sprintf('Unable to acquire exclusive lock "%s".', $this->file));
		}

		return fn () => flock($handle, LOCK_UN);
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function transaction(callable $callback): mixed
	{
		$unlock = $this->lock();
		try {
			return $callback();
		} finally {
			$unlock();
		}
	}

}
