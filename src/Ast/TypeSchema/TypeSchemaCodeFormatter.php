<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

use Nette\PhpGenerator\Literal;
use Shredio\TypeSchemaCompiler\NamespaceResolver;

final readonly class TypeSchemaCodeFormatter
{

	private TypeSchemaDumper $dumper;

	public function __construct(
		private NamespaceResolver $namespaceResolver,
		private string $typeSchemaVariableName = 'ts',
	)
	{
		$this->dumper = new TypeSchemaDumper();
	}

	public function format(TypeSchemaNode $node): string
	{
		if ($node instanceof MethodNode) {
			return $this->dumper->format(
				'$?->?(...?:)',
				$this->typeSchemaVariableName, $node->method, $this->formatNodes($node->nodes),
			);
		}

		if ($node instanceof DumpNode) {
			return $this->dumper->dump($node->value);
		}

		if ($node instanceof LiteralNode) {
			return $node->value;
		}

		if ($node instanceof ArrayNode) {
			$items = $node->items;
			foreach ($items as $key => $item) {
				$items[$key] = new Literal($this->format($item));
			}

			if ($node->multiLine) {
				return $this->dumper->multiLineDump($items);
			} else {
				return $this->dumper->dump($items);
			}
		}

		if ($node instanceof ClassNameNode) {
			$shortClassName = $this->namespaceResolver->shortName($node->value);
			return $this->dumper->format('?::class', new Literal($shortClassName));
		}

		if ($node instanceof NewClassNode) {
			$shortClassName = $this->namespaceResolver->shortName($node->className);
			return $this->dumper->format('new ?()', new Literal($shortClassName));
		}

		throw new \LogicException('Unsupported node type: ' . $node::class);
	}

	/**
	 * @param array<TypeSchemaNode> $nodes
	 * @return array<Literal>
	 */
	private function formatNodes(array $nodes): array
	{
		return array_map(
			fn (TypeSchemaNode $node): Literal => new Literal($this->format($node)),
			$nodes,
		);
	}

}
