<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Result;

use Hampel\BinaryLane\Api\Entity\ActionLink;
use Hampel\BinaryLane\Api\Entity\Server;

/**
 * What `POST /v2/servers` answers with: a server, and links to the actions still building it.
 *
 * THE SERVER EXISTS AND IS NOT READY. Its status is `new`, it has no addresses yet, and
 * several of its fields are null - so the object in hand is a receipt rather than a usable
 * server. The actions are how to wait:
 *
 *     $created = $binarylane->servers()->create($request);
 *     $binarylane->actions()->awaitAll($created->actionIds(), timeout: 1800);
 *     $server = $binarylane->servers()->get($created->id());
 *
 * A build takes minutes rather than seconds and a Windows image can take considerably longer,
 * so the default ten-minute timeout is not generous here - pass one.
 *
 * This shape exists for exactly one operation. Every other mutation on this API answers with
 * a single Action.
 */
final class CreatedServer implements \JsonSerializable
{
    /**
     * @param  list<ActionLink>  $actions
     */
    public function __construct(
        public readonly Server $server,
        public readonly array $actions = [],
    ) {
    }

    /**
     * The new server's id - what everything afterwards is addressed by.
     */
    public function id(): int
    {
        return $this->server->id;
    }

    /**
     * The ids of the actions building the server, ready for Actions::awaitAll().
     *
     * @return list<int>
     */
    public function actionIds(): array
    {
        return array_map(static fn (ActionLink $link): int => $link->id, $this->actions);
    }

    /**
     * Whether the API named any actions at all.
     *
     * An empty list is not a promise that the server is ready; it means nothing was linked.
     * Re-reading the server is what says whether it is.
     */
    public function hasActions(): bool
    {
        return $this->actions !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['server' => $this->server, 'links' => ['actions' => $this->actions]];
    }
}
