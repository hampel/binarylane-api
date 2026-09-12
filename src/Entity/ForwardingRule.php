<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\LoadBalancerRuleProtocol;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Which traffic a load balancer forwards.
 *
 * ONE FIELD, AND IT IS A PROTOCOL RATHER THAN A PORT. There is no port mapping on a
 * BinaryLane load balancer: `http` means the HTTP port and `https` the HTTPS one, and there
 * is no TCP option at all. Anything that is not a web protocol needs a different approach -
 * see LoadBalancerRuleProtocol.
 */
final class ForwardingRule implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?LoadBalancerRuleProtocol $entryProtocol = null,
        public readonly array $raw = [],
    ) {
    }

    public static function http(): self
    {
        return new self(LoadBalancerRuleProtocol::Http);
    }

    public static function https(): self
    {
        return new self(LoadBalancerRuleProtocol::Https);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            LoadBalancerRuleProtocol::tryFrom(Cast::string($row['entry_protocol'] ?? null) ?? ''),
            $row,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['entry_protocol' => $this->entryProtocol?->value];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : $this->toArray();
    }
}
