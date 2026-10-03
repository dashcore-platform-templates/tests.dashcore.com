<?php

namespace App\Library;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Finds the tests declared in a test file, from its source: PHPUnit test
 * methods and Pest's it()/test() calls (inside describe() blocks too), with
 * the lines each spans and the name a run reports it under — so the code can
 * be put next to what the scans saw it do.
 */
class TestParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @return list<array{style: string, key: ?string, name: string, start: int, end: int}>
     *                                                                                      key: PHPUnit's Class::method, as runs report it; null for Pest,
     *                                                                                      whose runtime ids are generated, so it is matched by name
     */
    public function parse(string $source): array
    {
        try {
            $ast = $this->parser->parse($source) ?? [];
        } catch (Throwable) {
            return [];
        }

        $ast = (new NodeTraverser(new NameResolver))->traverse($ast);
        $found = [];

        $visitor = new class($found) extends NodeVisitorAbstract
        {
            /** @var list<string> describe() blocks we are inside */
            private array $describes = [];

            /** @param list<array<string, mixed>> $found */
            public function __construct(private array &$found) {}

            public function enterNode(Node $node)
            {
                if ($node instanceof Class_ && $node->namespacedName !== null) {
                    foreach ($node->getMethods() as $method) {
                        if (! $method->isPublic() || ! $this->isTestMethod($method)) {
                            continue;
                        }

                        $this->found[] = [
                            'style' => 'phpunit',
                            'key' => $node->namespacedName->toString().'::'.$method->name->toString(),
                            'name' => $method->name->toString(),
                            'start' => $method->getStartLine(),
                            'end' => $method->getEndLine(),
                        ];
                    }

                    return null;
                }

                $call = $this->pestCall($node);

                if ($call === null) {
                    return null;
                }

                [$function, $description] = $call;

                if ($function === 'describe') {
                    $this->describes[] = $description;

                    return null;
                }

                $prefix = implode('', array_map(fn (string $d) => "`{$d}` → ", $this->describes));

                $this->found[] = [
                    'style' => 'pest',
                    'key' => null,
                    'name' => $prefix.($function === 'it' ? "it {$description}" : $description),
                    'start' => $node->getStartLine(),
                    'end' => $node->getEndLine(),
                ];

                return null;
            }

            public function leaveNode(Node $node)
            {
                if (($this->pestCall($node)[0] ?? null) === 'describe') {
                    array_pop($this->describes);
                }

                return null;
            }

            /**
             * A Pest test or describe call, wherever the chain hangs off it:
             * it('…', fn …)->with(…) is a test as much as it('…', fn …).
             *
             * @return array{0: string, 1: string}|null
             */
            private function pestCall(Node $node): ?array
            {
                if (! $node instanceof FuncCall || ! $node->name instanceof Node\Name) {
                    return null;
                }

                $function = $node->name->toLowerString();

                if (! in_array($function, ['it', 'test', 'describe'], true)) {
                    return null;
                }

                $first = $node->getArgs()[0]->value ?? null;

                return $first instanceof String_ ? [$function, $first->value] : null;
            }

            private function isTestMethod(Node\Stmt\ClassMethod $method): bool
            {
                if (str_starts_with($method->name->toString(), 'test')) {
                    return true;
                }

                foreach ($method->attrGroups as $group) {
                    foreach ($group->attrs as $attr) {
                        if (in_array(class_basename($attr->name->toString()), ['Test'], true)) {
                            return true;
                        }
                    }
                }

                return str_contains((string) $method->getDocComment()?->getText(), '@test');
            }
        };

        $traverser = new NodeTraverser($visitor);
        $traverser->traverse($ast);

        return $found;
    }
}
