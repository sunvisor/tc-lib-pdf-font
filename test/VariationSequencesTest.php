<?php

declare(strict_types=1);

namespace Test;

use Com\Tecnick\Pdf\Font\Definition;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import\VariationSequences;
use Com\Tecnick\Pdf\Font\Load;
use Com\Tecnick\Pdf\Font\Stack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariationSequencesTest extends TestCase
{
    private static function uint24(int $value): string
    {
        return \substr(\pack('N', $value), 1);
    }

    private static function table(): string
    {
        $default = \pack('N', 1) . self::uint24(0x908A) . "\x01";
        $mapping = \pack('N', 1) . self::uint24(0x658E) . \pack('n', 7);
        return (
            \pack('nNN', 14, 21 + \strlen($default . $mapping), 1)
            . self::uint24(0xE_0102)
            . \pack('NN', 21, 21 + \strlen($default))
            . $default
            . $mapping
        );
    }

    public function testReadsDefaultRangesAndNonDefaultMappings(): void
    {
        $map = (new VariationSequences(self::table(), 8))->read();
        self::assertSame([0xE_0102 => [0x908A => -1, 0x908B => -1, 0x658E => 7]], $map);
    }

    public function testDefinitionKeepsVariationMapAcrossJsonRoundTrip(): void
    {
        $map = (new VariationSequences(self::table(), 8))->read();
        /** @var mixed $raw */
        $raw = \json_decode(\json_encode(['variations' => $map], JSON_THROW_ON_ERROR), true, 5, JSON_THROW_ON_ERROR);
        self::assertIsArray($raw);
        self::assertSame($map, Definition::normalize($raw)['variations'] ?? null);
    }

    /** @throws FontException */
    public function testResolvesDefaultNonDefaultAndUnsupportedSequences(): void
    {
        $stack = $this->stack();
        self::assertSame(12, $stack->getGidForVariation(0x2_0000, 0xE_0100));
        self::assertSame(23, $stack->getGidForVariation(0x2_0000, 0xE_0101));
        self::assertNull($stack->getGidForVariation(0x2_0000, 0xE_0102));
        self::assertNull($stack->getGidForVariation(0x2_0001, 0xE_0100));
    }

    /** @throws FontException */
    public function testSequencesUseOneGlyphAndKeepDistinctCidsForTheSameGlyph(): void
    {
        $stack = $this->stack();
        $encoded = $stack->ordArrToGidStr([0x2_0000, 0xE_0100, 0x2_0000, 0xE_0101, 0x2_0000, 0x2_0000, 0xE_0100]);
        self::assertSame(\pack('n*', 1, 2, 3, 1), $encoded);
        self::assertSame([0x2_0000 => true], $stack->getFont('sample')['subsetchars']);
        self::assertSame(
            [
                1 => ['gid' => 12, 'unicode' => [0x2_0000, 0xE_0100]],
                2 => ['gid' => 23, 'unicode' => [0x2_0000, 0xE_0101]],
                3 => ['gid' => 12, 'unicode' => [0x2_0000]],
            ],
            $stack->getFont('sample')['encodedglyphs'],
        );
    }

    /** @throws FontException */
    public function testUsesVariantAdvanceAndDoesNotAddSelectorSpacing(): void
    {
        $stack = $this->stack();
        self::assertSame(0.0, $stack->getCharWidth(0xE_0101));
        self::assertEqualsWithDelta(24.8, $stack->getOrdArrWidth([0x2_0000, 0xE_0101, 0x2_0000]), 0.0001);
    }

    /** @throws FontException */
    public function testUnsupportedAndIsolatedSelectorsDoNotProduceNotdefGlyphs(): void
    {
        $stack = $this->stack();
        self::assertSame(\pack('n', 1), $stack->ordArrToGidStr([0xE_0100, 0x2_0000, 0xE_0102]));
        self::assertSame(
            ['gid' => 12, 'unicode' => [0x2_0000, 0xE_0102]],
            $stack->getFont('sample')['encodedglyphs'][1] ?? null,
        );
    }

    /** @throws FontException */
    private function stack(): Stack
    {
        return new class extends Stack {
            /** @throws FontException */
            public function __construct()
            {
                parent::__construct(1.0);
                $data = Load::DEFAULT_DATA;
                $data['ctgu'] = [0x2_0000 => 12];
                $data['subset'] = true;
                $data['cwu'] = [0x2_0000 => 1000];
                $data['variationwidths'] = [23 => 900];
                $data['variations'] = [0xE_0100 => [0x2_0000 => -1], 0xE_0101 => [0x2_0000 => 23]];
                $this->font['sample'] = $data;
                $this->stack[] = [
                    'key' => 'sample',
                    'style' => '',
                    'fakestyle' => '',
                    'size' => 12.0,
                    'spacing' => 2.0,
                    'stretching' => 1.0,
                ];
                $this->index = 0;
            }
        };
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidTables(): iterable
    {
        $table = self::table();
        yield 'truncated header' => ["\x00\x0e", 8];
        yield 'invalid length' => [\substr_replace($table, \pack('N', 999), 2, 4), 8];
        yield 'too many records' => [\substr_replace($table, \pack('N', 0xFFFF_FFFF), 6, 4), 8];
        yield 'invalid selector' => [\substr_replace($table, self::uint24(0x1234), 10, 3), 8];
        yield 'offset into header' => [\substr_replace($table, \pack('N', 2), 13, 4), 8];
        yield 'offset past end' => [\substr_replace($table, \pack('N', 1000), 17, 4), 8];
        yield 'truncated ranges' => [\substr_replace($table, \pack('N', 1000), 21, 4), 8];
        yield 'surrogate base' => [\substr_replace($table, self::uint24(0xD800), 25, 3), 8];
        yield 'invalid base' => [\substr_replace($table, self::uint24(0x11_0000), 25, 3), 8];
        yield 'overlapping maps' => [\substr_replace($table, self::uint24(0x908A), 33, 3), 8];
        yield 'glyph outside font' => [$table, 7];
    }

    #[DataProvider('invalidTables')]
    public function testRejectsMalformedTables(string $table, int $glyphCount): void
    {
        $this->expectException(FontException::class);
        (new VariationSequences($table, $glyphCount))->read();
    }
}
