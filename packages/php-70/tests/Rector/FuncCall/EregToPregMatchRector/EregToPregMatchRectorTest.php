<?php

declare(strict_types=1);

namespace Rector\Php70\Tests\Rector\FuncCall\EregToPregMatchRector;

use Iterator;
use Rector\Core\Testing\PHPUnit\AbstractRectorTestCase;
use Rector\Php70\Rector\FuncCall\EregToPregMatchRector;

final class EregToPregMatchRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideData()
     */
    public function test(string $file): void
    {
        $this->doTestFile($file);
    }

    public function provideData(): Iterator
    {
        return $this->yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    protected function getRectorClass(): string
    {
        return EregToPregMatchRector::class;
    }
}
