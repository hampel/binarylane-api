<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * Replace the server's image as part of a resize - the specification's `ChangeImage`.
 *
 * THIS IS WHAT MAKES A RESIZE DESTRUCTIVE. A resize on its own changes the plan; a resize
 * carrying one of these REBUILDS the server on the new image, and the specification says
 * plainly that no further confirmation is requested. Everything on the disks is gone.
 *
 * Paired with Resize::withPreActionBackup(), which takes a backup first - the only safety
 * net the API offers.
 */
final class ChangeImage implements \JsonSerializable
{
    /**
     * @param  int|string  $image  a slug or an id. Which kinds of image are permitted varies
     *                             by action, says the specification
     */
    private function __construct(
        public readonly int|string $image,
        private readonly ?ImageOptions $options = null,
    ) {
    }

    public static function to(int|string $image): self
    {
        if (is_string($image) && trim($image) === '') {
            throw new InvalidArgumentException('Changing the image needs an image slug or id.');
        }

        return new self(is_string($image) ? trim($image) : $image);
    }

    /**
     * How the server is set up once the new image is on it - hostname, password, SSH keys,
     * user-data. All four have defaults that decide things for you; see ImageOptions.
     */
    public function withOptions(ImageOptions $options): self
    {
        return new self($this->image, $options->isEmpty() ? null : $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['image' => $this->image];

        if ($this->options !== null) {
            $payload['options'] = $this->options->toArray();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
