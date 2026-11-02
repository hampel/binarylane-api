<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\Size;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Request\SizeOptions;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The granularity rules are stated field by field on the resize request: disk in multiples of
 * 5 GB, of 10 above 60 and of 100 above 200, with memory and transfer stepped likewise. A
 * size's own disk value is always valid, on a step or not.
 */
final class SizeOptionsTest extends BaseTestCase
{
    public function testADiskOffTheStepsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('multiples of 10 GB; 65 GB is not one');

        SizeOptions::none()->withDisk(65)->validateGranularity();
    }

    public function testADiskOnTheStepsPasses(): void
    {
        $options = SizeOptions::none()->withDisk(70);

        $this->assertSame($options, $options->validateGranularity());
    }

    public function testASizesOwnDiskPassesOffTheSteps(): void
    {
        $options = SizeOptions::none()->withDisk(65);

        $this->assertSame($options, $options->validateGranularity($this->sizeWithDisk(65)));
        $this->assertSame($options, $options->validateAgainst($this->sizeWithDisk(65)));
    }

    public function testAnotherDiskOffTheStepsIsStillRefusedWithTheSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('65 GB is not one');

        SizeOptions::none()->withDisk(65)->validateAgainst($this->sizeWithDisk(75));
    }

    public function testMemoryOffTheStepsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('multiples of 1024 MB; 2560 MB is not one');

        SizeOptions::none()->withMemory(2560)->validateGranularity();
    }

    public function testTransferIsSteppedInGigabytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('0.503 TB is 503 GB');

        SizeOptions::none()->withTransfer(0.503)->validateGranularity();
    }

    private function sizeWithDisk(int $disk): Size
    {
        return Size::fromArray([
            'slug' => 'example',
            'disk' => $disk,
            'options' => ['disk_min' => 20, 'disk_max' => 2000, 'memory_max' => 65536],
        ]);
    }
}
