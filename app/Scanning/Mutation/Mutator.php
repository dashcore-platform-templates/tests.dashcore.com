<?php

namespace App\Scanning\Mutation;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Builds "extreme" mutants of a method: its whole body replaced by the most
 * trivial thing its signature allows — nothing for void, 0 and 1 for an int,
 * true and false for a bool, null where null is allowed.
 *
 * One body swap per mutant keeps the count to one or two per method, which is
 * what makes checking every tested method of a fleet affordable, and it asks
 * exactly the question that matters: if this method stopped doing its job,
 * would any test notice? A test that still passes is reaching the code
 * without checking it.
 */
class Mutator
{
    /**
     * @return list<Mutant> empty when the method cannot be found or has no body
     */
    public function mutants(string $source, int $firstStatementLine): array
    {
        $method = $this->find($source, $firstStatementLine);

        if ($method === null || ($method->stmts ?? []) === []) {
            return [];
        }

        $first = $method->stmts[0];
        $last = $method->stmts[count($method->stmts) - 1];
        $start = $first->getStartFilePos();
        $end = $last->getEndFilePos();

        // A leading comment belongs to the first statement's position range in
        // some layouts; never cut into the middle of the source.
        if ($start < 0 || $end < $start) {
            return [];
        }

        $mutants = [];

        foreach ($this->bodies($method) as $description => $body) {
            $mutants[] = new Mutant(
                description: $description,
                source: substr($source, 0, $start).$body.substr($source, $end + 1),
            );
        }

        return $mutants;
    }

    private function find(string $source, int $firstStatementLine): ?ClassMethod
    {
        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        } catch (\Throwable) {
            return null;
        }

        return (new NodeFinder)->findFirst($ast, fn (Node $n) => $n instanceof ClassMethod
            && ($n->stmts ?? []) !== []
            && $n->stmts[0]->getStartLine() === $firstStatementLine);
    }

    /**
     * @return array<string, string> description => replacement body
     */
    private function bodies(ClassMethod $method): array
    {
        if ((new NodeFinder)->findFirst($method->stmts, fn (Node $n) => $n instanceof Node\Expr\Yield_ || $n instanceof Node\Expr\YieldFrom) !== null) {
            return ['yields nothing' => 'yield from [];'];
        }

        $type = $method->returnType;

        if ($type === null) {
            return ['returns null' => 'return null;'];
        }

        if ($type instanceof Node\NullableType) {
            return ['returns null' => 'return null;'];
        }

        $names = $type instanceof Node\UnionType
            ? array_map(fn ($t) => $this->typeName($t), $type->types)
            : [$this->typeName($type)];

        if (in_array('null', $names, true) || in_array('mixed', $names, true)) {
            return ['returns null' => 'return null;'];
        }

        return match ($names[0]) {
            'void' => ['does nothing' => ''],
            'never' => ['throws' => 'throw new \RuntimeException(\'mutant\');'],
            'bool' => ['returns true' => 'return true;', 'returns false' => 'return false;'],
            'true' => ['throws' => 'throw new \RuntimeException(\'mutant\');'],
            'false' => ['throws' => 'throw new \RuntimeException(\'mutant\');'],
            'int' => ['returns 0' => 'return 0;', 'returns 1' => 'return 1;'],
            'float' => ['returns 0.0' => 'return 0.0;', 'returns 1.0' => 'return 1.0;'],
            'string' => ['returns an empty string' => "return '';", 'returns another string' => "return 'mutant';"],
            'array', 'iterable' => ['returns an empty array' => 'return [];'],
            default => ['throws' => 'throw new \RuntimeException(\'mutant\');'],
        };
    }

    private function typeName(Node $type): string
    {
        return match (true) {
            $type instanceof Node\Identifier => strtolower($type->toString()),
            $type instanceof Node\Name => 'class',
            default => 'class',
        };
    }
}
