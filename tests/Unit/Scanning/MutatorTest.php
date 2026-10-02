<?php

namespace Tests\Unit\Scanning;

use App\Scanning\Mutation\Mutant;
use App\Scanning\Mutation\Mutator;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MutatorTest extends TestCase
{
    private const SOURCE = <<<'PHP'
        <?php

        namespace App\Services;

        class Pricing
        {
            public function apply(): void
            {
                $this->total = 10;
            }

            public function isFree(): bool
            {
                return $this->total === 0;
            }

            public function total(): int
            {
                return $this->total * 2;
            }

            public function rate(): float
            {
                return 1.5;
            }

            public function name(): string
            {
                return 'pro';
            }

            public function lines(): array
            {
                return [1, 2];
            }

            public function maybe(): ?int
            {
                return 3;
            }

            public function either(): int|string|null
            {
                return 3;
            }

            public function untyped()
            {
                return 3;
            }

            public function make(): self
            {
                return new self;
            }

            public function each(): iterable
            {
                yield 1;
            }

            public function abort(): never
            {
                exit(1);
            }
        }
        PHP;

    /** @return array<string, array{int, list<string>, list<string>}> */
    public static function methods(): array
    {
        return [
            'void does nothing' => [9, ['does nothing'], ['']],
            'bool flips both ways' => [14, ['returns true', 'returns false'], ['return true;', 'return false;']],
            'int' => [19, ['returns 0', 'returns 1'], ['return 0;', 'return 1;']],
            'float' => [24, ['returns 0.0', 'returns 1.0'], ['return 0.0;', 'return 1.0;']],
            'string' => [29, ['returns an empty string', 'returns another string'], ["return '';", "return 'mutant';"]],
            'array' => [34, ['returns an empty array'], ['return [];']],
            'nullable' => [39, ['returns null'], ['return null;']],
            'union with null' => [44, ['returns null'], ['return null;']],
            'untyped' => [49, ['returns null'], ['return null;']],
            'an object' => [54, ['throws'], ["throw new \\RuntimeException('mutant');"]],
            'a generator' => [59, ['yields nothing'], ['yield from [];']],
            'never' => [64, ['throws'], ["throw new \\RuntimeException('mutant');"]],
        ];
    }

    /**
     * @param  list<string>  $descriptions
     * @param  list<string>  $bodies
     */
    #[DataProvider('methods')]
    public function test_each_return_type_gets_its_trivial_bodies(int $line, array $descriptions, array $bodies): void
    {
        $mutants = (new Mutator)->mutants(self::SOURCE, $line);

        $this->assertSame($descriptions, array_map(fn (Mutant $m) => $m->description, $mutants));

        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $originalLines = explode("\n", self::SOURCE);

        foreach ($mutants as $i => $mutant) {
            $this->assertNotNull($parser->parse($mutant->source), 'every mutant is valid PHP');

            $mutatedLines = explode("\n", $mutant->source);
            $this->assertCount(count($originalLines), $mutatedLines, 'single-line bodies swap in place');
            $this->assertSame(trim($bodies[$i]), trim($mutatedLines[$line - 1]));

            foreach ($originalLines as $n => $original) {
                if ($n !== $line - 1) {
                    $this->assertSame($original, $mutatedLines[$n], 'nothing else in the file changes');
                }
            }
        }
    }

    public function test_a_multi_statement_body_is_replaced_whole(): void
    {
        $source = "<?php\nclass A\n{\n    public function go(): int\n    {\n        \$a = 1;\n        // note\n        \$b = 2;\n        return \$a + \$b;\n    }\n}\n";

        $mutants = (new Mutator)->mutants($source, 6);

        $this->assertSame("<?php\nclass A\n{\n    public function go(): int\n    {\n        return 0;\n    }\n}\n", $mutants[0]->source);
    }

    public function test_no_method_at_that_line_or_unparseable_source_has_no_mutants(): void
    {
        $this->assertSame([], (new Mutator)->mutants(self::SOURCE, 3));
        $this->assertSame([], (new Mutator)->mutants('<?php class {', 1));
    }
}
