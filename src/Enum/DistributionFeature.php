<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * What a distribution image supports at first install.
 *
 * Read this before building a create request: passing `ssh_keys` to an image that does not
 * advertise `ssh`, or `user_data` to one that does not advertise `user-data`, is a request
 * that will be accepted and then quietly do nothing useful.
 */
enum DistributionFeature: string
{
    /** The initial install accepts SSH connections and SSH keys. */
    case Ssh = 'ssh';

    /** The initial install accepts Remote Desktop connections. */
    case RemoteDesktop = 'remote-desktop';

    /** The initial install accepts cloud-init user-data. */
    case UserData = 'user-data';
}
