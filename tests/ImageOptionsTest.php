<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Request\ImageOptions;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * On a rebuild, `user_data` has three states the specification distinguishes: absent keeps
 * the server's existing user-data, an empty string clears it, and a value replaces it. Only
 * the last needs an image that supports user-data.
 */
final class ImageOptionsTest extends BaseTestCase
{
    public function testAbsentUserDataIsNotSent(): void
    {
        $this->assertArrayNotHasKey('user_data', ImageOptions::defaults()->toArray());
    }

    public function testAnEmptyStringIsSentAsOne(): void
    {
        $this->assertSame(['user_data' => ''], ImageOptions::defaults()->withUserData('')->toArray());
    }

    public function testAnEmptyStringPassesValidationForAnImageWithoutUserData(): void
    {
        $options = ImageOptions::defaults()->withUserData('');

        $this->assertSame($options, $options->validateAgainst($this->image(['ssh'])));
    }

    public function testAValueIsRefusedForAnImageWithoutUserData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('null or empty');

        ImageOptions::defaults()->withUserData("#cloud-config\n")->validateAgainst($this->image(['ssh']));
    }

    public function testAValuePassesValidationForAnImageWithUserData(): void
    {
        $options = ImageOptions::defaults()->withUserData("#cloud-config\n");

        $this->assertSame($options, $options->validateAgainst($this->image(['ssh', 'user-data'])));
    }

    /**
     * @param  list<string>  $features
     */
    private function image(array $features): Image
    {
        return Image::fromArray([
            'id' => 1,
            'name' => 'Example',
            'slug' => 'example',
            'distribution_info' => ['features' => $features],
        ]);
    }
}
