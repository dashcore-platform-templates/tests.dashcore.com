<?php

namespace Tests\Unit\Library;

use App\Library\TestParser;
use PHPUnit\Framework\TestCase;

class TestParserTest extends TestCase
{
    public function test_phpunit_test_methods_are_found_by_name_attribute_and_docblock(): void
    {
        $source = <<<'PHP'
        <?php

        namespace Tests\Feature;

        use PHPUnit\Framework\Attributes\Test;

        class InvoiceTest extends TestCase
        {
            public function test_totals_add_up(): void
            {
                $this->assertTrue(true);
            }

            #[Test]
            public function rounding_is_half_up(): void
            {
                $this->assertTrue(true);
            }

            /** @test */
            public function it_refuses_negative_lines(): void
            {
            }

            public function helper(): void {}

            private function test_private(): void {}
        }
        PHP;

        $found = (new TestParser)->parse($source);

        $this->assertSame(
            ['Tests\Feature\InvoiceTest::test_totals_add_up', 'Tests\Feature\InvoiceTest::rounding_is_half_up', 'Tests\Feature\InvoiceTest::it_refuses_negative_lines'],
            array_column($found, 'key'),
        );
        $this->assertSame(['phpunit'], array_values(array_unique(array_column($found, 'style'))));
        $this->assertSame([9, 12], [$found[0]['start'], $found[0]['end']]);
    }

    public function test_pest_tests_are_found_with_the_names_a_run_reports(): void
    {
        $source = <<<'PHP'
        <?php

        test('guests are redirected', function () {
            expect(true)->toBeTrue();
        });

        it('formats money', function (int $cents) {
            expect($cents)->toBeInt();
        })->with([1, 2]);

        describe('the forecast', function () {
            describe('in a short range', function () {
                it('stays flat', fn () => expect(1)->toBe(1));
            });

            test('warns early', fn () => expect(1)->toBe(1));
        });

        beforeEach(fn () => null);
        PHP;

        $found = (new TestParser)->parse($source);

        $this->assertSame([
            'guests are redirected',
            'it formats money',
            '`the forecast` → `in a short range` → it stays flat',
            '`the forecast` → warns early',
        ], array_column($found, 'name'));
        $this->assertNull($found[0]['key']);
        $this->assertSame([7, 9], [$found[1]['start'], $found[1]['end']], 'a chained ->with() is part of the test');
    }

    public function test_a_file_that_does_not_parse_declares_nothing(): void
    {
        $this->assertSame([], (new TestParser)->parse('<?php class {'));
    }
}
