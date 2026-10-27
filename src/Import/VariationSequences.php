<?php

declare(strict_types=1);

namespace Com\Tecnick\Pdf\Font\Import;

use Com\Tecnick\Pdf\Font\Exception as FontException;

/** Reads the supplementary cmap format 14 without changing the nominal character map. */
final class VariationSequences
{
    public const DEFAULT_GLYPH = -1;

    private int $entries = 0;

    public function __construct(
        private readonly string $data,
        private readonly int $glyphCount,
    ) {}

    /** @return array<int, array<int, int>> Selector => base => GID, or DEFAULT_GLYPH. */
    public function read(): array
    {
        if ($this->number(0, 2) !== 14 || $this->number(2, 4) !== \strlen($this->data)) {
            throw new FontException('Malformed cmap format 14 header');
        }
        $count = $this->number(6, 4);
        $this->checkRange(10, $count, 11);
        $map = [];
        $previous = -1;
        for ($index = 0; $index < $count; ++$index) {
            $offset = 10 + (11 * $index);
            $selector = $this->number($offset, 3);
            if (!self::isSelector($selector) || $selector <= $previous) {
                throw new FontException('Invalid or unordered cmap variation selector');
            }
            $map[$selector] = $this->readSelector($offset, 10 + (11 * $count));
            $previous = $selector;
        }
        return $map;
    }

    public static function isSelector(int $ord): bool
    {
        return $ord >= 0xFE00 && $ord <= 0xFE0F || $ord >= 0xE_0100 && $ord <= 0xE_01EF;
    }

    /** @return array<int, int> */
    private function readSelector(int $offset, int $recordsEnd): array
    {
        $map = [];
        $default = $this->number($offset + 3, 4);
        $nondefault = $this->number($offset + 7, 4);
        foreach ([$default, $nondefault] as $table) {
            if ($table !== 0 && $table < $recordsEnd) {
                throw new FontException('cmap variation data overlaps the selector records');
            }
        }
        if ($default !== 0) {
            $map = $this->readDefaults($default);
        }
        if ($nondefault !== 0) {
            $this->readMappings($nondefault, $map);
        }
        return $map;
    }

    /** @return array<int, int> */
    private function readDefaults(int $offset): array
    {
        $count = $this->number($offset, 4);
        $this->checkRange($offset + 4, $count, 4);
        $map = [];
        $previous = -1;
        for ($index = 0; $index < $count; ++$index) {
            $record = $offset + 4 + (4 * $index);
            $start = $this->number($record, 3);
            $end = $start + $this->number($record + 3, 1);
            $this->checkBaseRange($start, $end, $previous);
            $this->reserve($end - $start + 1);
            for ($base = $start; $base <= $end; ++$base) {
                $map[$base] = self::DEFAULT_GLYPH;
            }
            $previous = $end;
        }
        return $map;
    }

    /** @param array<int, int> $map */
    private function readMappings(int $offset, array &$map): void
    {
        $count = $this->number($offset, 4);
        $this->checkRange($offset + 4, $count, 5);
        $this->reserve($count);
        $previous = -1;
        for ($index = 0; $index < $count; ++$index) {
            $record = $offset + 4 + (5 * $index);
            $base = $this->number($record, 3);
            $gid = $this->number($record + 3, 2);
            $this->checkBaseRange($base, $base, $previous);
            if (isset($map[$base]) || $gid >= $this->glyphCount) {
                throw new FontException('Conflicting variation mapping or glyph index outside the font');
            }
            $map[$base] = $gid;
            $previous = $base;
        }
    }

    private function checkBaseRange(int $start, int $end, int $previous): void
    {
        if ($start <= $previous || $end > 0x10_FFFF || $start <= 0xDFFF && $end >= 0xD800) {
            throw new FontException('Invalid or unordered cmap variation base character');
        }
    }

    private function reserve(int $count): void
    {
        $this->entries += $count;
        if ($this->entries > 0x11_0000) {
            throw new FontException('cmap variation table has too many mappings');
        }
    }

    private function checkRange(int $offset, int $count, int $size): void
    {
        if ($offset > \strlen($this->data) || $count > \intdiv(\strlen($this->data) - $offset, $size)) {
            throw new FontException('Truncated cmap variation table');
        }
    }

    private function number(int $offset, int $size): int
    {
        $this->checkRange($offset, 1, $size);
        $number = 0;
        for ($index = 0; $index < $size; ++$index) {
            $number = ($number << 8) | \ord($this->data[$offset + $index]);
        }
        return $number;
    }
}
