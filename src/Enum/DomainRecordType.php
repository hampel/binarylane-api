<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * The type of a DNS record.
 *
 * WHICH OTHER FIELDS MEAN ANYTHING DEPENDS ENTIRELY ON THIS. `priority` is for MX and SRV,
 * `port` and `weight` are for SRV alone, and `flags` and `tag` are for CAA alone - the
 * specification says so field by field, and a record read back from the API carries all five
 * as nulls whatever its type. The predicates below are how DomainRecord decides what to send.
 *
 * SOA IS LISTED AND IS NOT YOURS TO CREATE. Every zone has one, the API will return it in a
 * record list, and it is maintained by BinaryLane - the zone file's serial is documented as
 * always reading 0 rather than the real value.
 */
enum DomainRecordType: string
{
    /** An IPv4 address. `data` is the address. */
    case A = 'A';

    /** An IPv6 address. `data` is the address. */
    case AAAA = 'AAAA';

    /** Which certificate authorities may issue for the domain. Uses `flags` and `tag`. */
    case CAA = 'CAA';

    /** An alias. `data` is the canonical name. */
    case CNAME = 'CNAME';

    /** A mail exchange. Uses `priority`; lower wins. */
    case MX = 'MX';

    /** A name server. */
    case NS = 'NS';

    /** The zone's start of authority. Maintained by BinaryLane, not by you. */
    case SOA = 'SOA';

    /** A service location. Uses `priority`, `weight` and `port`. */
    case SRV = 'SRV';

    /** Free text - SPF, DKIM, DMARC, a verification token. */
    case TXT = 'TXT';

    /**
     * Whether `priority` is meaningful for this type. Lower wins, on both.
     */
    public function usesPriority(): bool
    {
        return $this === self::MX || $this === self::SRV;
    }

    /**
     * Whether `port` and `weight` are meaningful. SRV only.
     */
    public function usesServiceFields(): bool
    {
        return $this === self::SRV;
    }

    /**
     * Whether `flags` and `tag` are meaningful. CAA only.
     */
    public function usesCertificateAuthorityFields(): bool
    {
        return $this === self::CAA;
    }

    /**
     * Whether this is a record the zone owner creates, as opposed to one the zone has.
     */
    public function isManageable(): bool
    {
        return $this !== self::SOA;
    }
}
