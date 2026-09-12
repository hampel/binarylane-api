<?php

/**
 * Exercise: read the catalogue, and probe whether the size filters are actually honoured.
 * Read-only - nothing here can change anything.
 *
 * THE QUESTION THIS EXISTS FOR is the one a test suite structurally cannot answer. This
 * package tells callers to build a create form from `GET /v2/sizes?image=<slug>` rather than
 * from the raw catalogue. If BinaryLane stopped honouring the parameter tomorrow the response
 * would still be a 200 full of sizes, every mock in the suite would still pass, and the advice
 * in Endpoint\Sizes would quietly become wrong - which is the shape of failure only a real
 * call can see.
 *
 * IT COMPARES THE SET OF SIZES, NOT JUST THE REGIONS, and that is a correction. An earlier
 * version of this probe compared the union of every size's regions and reported "identical",
 * which it will be on any account where every image is offered in every region - and the real
 * effect was going on in a place it was not looking. Measured on 12 September 2026, filtering
 * on `windows-2025` dropped four of twenty-one sizes and changed no region list at all.
 *
 * So it asks twice and compares both. Each request fetches its own baseline rather than
 * reusing a count printed earlier: when the two agree on the unfiltered total, that agreement
 * is itself the check that both looked at the same catalogue.
 *
 * The interesting outcome is NOT an error. It is BOTH comparisons coming back identical for a
 * demanding image, which means the filter is being ignored and nothing says so.
 *
 * Needs BINARYLANE_API_TOKEN. Uses BINARYLANE_IMAGE when set; otherwise it looks for the image
 * with the largest minimum disk, because a demanding one is what makes the filter show itself.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Entity\Size;
use Hampel\BinaryLane\Api\Exception\ExceptionInterface;

require __DIR__ . '/lib/harness.php';

$io->title('binarylane · catalogue');

$binarylane = harness_client($io);

try {
    $regions = $binarylane->regions()->all();

    $io->info(sprintf('%d regions', count($regions)));

    foreach ($regions as $region) {
        $io->values([
            $region->slug => sprintf(
                '%s - %s, %d sizes, %d features',
                $region->name,
                $region->available ? 'accepting new resources' : 'NOT accepting new resources',
                count($region->sizes),
                count($region->features)
            ),
        ]);
    }

    $io->line();

    $sizes = $binarylane->sizes()->all();

    $io->info(sprintf('%d sizes in the catalogue', count($sizes)));

    $unavailable = array_values(array_filter($sizes, static fn (Size $size): bool => !$size->available));
    $outOfStock = array_values(array_filter(
        $sizes,
        static fn (Size $size): bool => ($size->regionsOutOfStock ?? []) !== []
    ));

    $io->values([
        'retired (available=false)' => count($unavailable),
        'out of stock somewhere' => count($outOfStock),
        'restricted disk values' => count(array_filter(
            $sizes,
            static fn (Size $size): bool => $size->options?->hasRestrictedDiskValues() ?? false
        )),
    ]);

    $cheapest = null;

    foreach ($sizes as $size) {
        if ($size->available && ($cheapest === null || $size->priceMonthly < $cheapest->priceMonthly)) {
            $cheapest = $size;
        }
    }

    if ($cheapest !== null) {
        $io->line();
        $io->values([
            'cheapest available' => $cheapest->slug,
            'price' => sprintf('AU$%.2f/month, AU$%.4f/hour', $cheapest->priceMonthly, $cheapest->priceHourly),
            'includes' => sprintf(
                '%d %s, %d MB, %d GB, %.1f TB transfer',
                $cheapest->vcpus,
                $cheapest->vcpuUnits,
                $cheapest->memory,
                $cheapest->disk,
                $cheapest->transfer
            ),
            'offered in' => implode(', ', $cheapest->regions) ?: '(nowhere)',
            'available in' => implode(', ', $cheapest->availableRegions()) ?: '(nowhere today)',
        ]);
    }

    // ------------------------------------------------------------------------------------
    // The probe.
    // ------------------------------------------------------------------------------------

    $io->line();
    $io->info('probe: does ?image= actually restrict the catalogue?');

    $slug = getenv('BINARYLANE_IMAGE') ?: null;

    if ($slug === null) {
        // The most demanding image available, because an undemanding one fits every size and
        // a filter that removes nothing cannot be told from a filter that is ignored.
        $demanding = null;

        foreach ($binarylane->images()->each(type: \Hampel\BinaryLane\Api\Enum\ImageQueryType::Distribution) as $image) {
            if ($image->slug === null || $image->slug === '') {
                continue;
            }

            if ($demanding === null || $image->minDiskSize > $demanding->minDiskSize) {
                $demanding = $image;
            }
        }

        $slug = $demanding?->slug;
    }

    if ($slug === null) {
        $io->warn('no distribution image with a slug was visible, so the probe cannot run');
    } else {
        // Both sides fetch their own list. The unfiltered count is not reused from above: if
        // the two disagree on how many sizes exist in the catalogue, the comparison below was
        // never comparing the same thing.
        $index = static function (array $list): array {
            $out = [];

            foreach ($list as $size) {
                $regions = $size->regions;
                sort($regions);
                $out[$size->slug] = $regions;
            }

            ksort($out);

            return $out;
        };

        $before = $index($binarylane->sizes()->all());
        $after = $index($binarylane->sizes()->forImage($slug));

        $dropped = array_diff(array_keys($before), array_keys($after));
        $narrowed = [];

        foreach ($after as $size => $regions) {
            if (isset($before[$size]) && $before[$size] !== $regions) {
                $narrowed[$size] = sprintf('%s -> %s', implode(',', $before[$size]), implode(',', $regions) ?: '(none)');
            }
        }

        $io->values([
            'image' => $slug,
            'sizes unfiltered' => count($before),
            'sizes filtered' => count($after),
            'sizes dropped' => $dropped === [] ? 'none' : implode(', ', $dropped),
            'region lists narrowed' => count($narrowed),
        ]);

        foreach (array_slice($narrowed, 0, 6, true) as $size => $change) {
            $io->line(sprintf('    %s: %s', $size, $change));
        }

        $io->line();

        if ($dropped === [] && $narrowed === []) {
            $io->warn('NOTHING CHANGED on either comparison. Either this image really fits every');
            $io->warn('size in every region - which is true of an undemanding distribution - or the');
            $io->warn('filter is being ignored. Re-run with BINARYLANE_IMAGE set to the most');
            $io->warn('demanding image on the account before believing the first reading.');
        } else {
            $io->success(sprintf(
                'the filter is honoured: %d size(s) dropped, %d region list(s) narrowed',
                count($dropped),
                count($narrowed)
            ));
        }
    }

    // ------------------------------------------------------------------------------------

    $io->line();
    $io->info('images');

    $distributions = $binarylane->images()->distributions();

    $withSurcharge = array_values(array_filter(
        $distributions,
        static fn (Image $image): bool => $image->hasSurcharge()
    ));

    $io->values([
        'distributions' => count($distributions),
        'licensed (surcharged)' => count($withSurcharge),
        'backups on the account' => iterator_count($binarylane->images()->backups()),
    ]);

    if ($withSurcharge !== []) {
        $io->line();

        foreach (array_slice($withSurcharge, 0, 5) as $image) {
            $io->values([
                $image->slug ?? (string) $image->id => sprintf(
                    '%s - AU$%.2f/month on 2 vCPU / 4096 MB',
                    $image->describe(),
                    $image->monthlySurcharge(4096, 2)
                ),
            ]);
        }
    }

    $io->line();
    $io->info('software catalogue');

    $catalogue = $binarylane->software()->all();

    $io->values([
        'products' => count($catalogue),
        'enabled' => count(array_filter($catalogue, static fn ($s): bool => $s->enabled)),
        'grouped (mutually exclusive)' => count(array_filter($catalogue, static fn ($s): bool => $s->group !== null)),
    ]);
} catch (ExceptionInterface $e) {
    $io->error($e::class);
    $io->error($e->getMessage());

    exit(1);
}
