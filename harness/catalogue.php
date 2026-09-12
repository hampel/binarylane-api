<?php

/**
 * Exercise: read the catalogue, and probe whether the size filters are actually honoured.
 * Read-only - nothing here can change anything.
 *
 * THE QUESTION THIS EXISTS FOR is the one a test suite structurally cannot answer. This
 * package tells callers that `GET /v2/sizes?image=<slug>` narrows each size's `regions` to
 * where that image is available on that size, and that without it the region lists are wider
 * than the truth. Both halves are read off the specification's prose. If BinaryLane stopped
 * honouring the parameter tomorrow the response would still be a 200 full of sizes, every
 * mock in the suite would still pass, and the advice in Endpoint\Sizes would quietly become
 * wrong - which is the shape of failure that only a real call can see.
 *
 * So it asks twice and compares. Each request fetches its own baseline rather than reusing a
 * count printed earlier: when the two agree on the total, that agreement is itself the check
 * that both looked at the same catalogue.
 *
 * The interesting outcome is NOT an error. It is the two region lists coming back identical,
 * which means the filter is being ignored and nothing says so.
 *
 * Needs BINARYLANE_API_TOKEN. Uses BINARYLANE_IMAGE when set, and otherwise picks the first
 * distribution the account can see.
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
    $io->info('probe: does ?image= actually narrow the region lists?');

    $slug = getenv('BINARYLANE_IMAGE') ?: null;

    if ($slug === null) {
        foreach ($binarylane->images()->each(type: \Hampel\BinaryLane\Api\Enum\ImageQueryType::Distribution) as $image) {
            if ($image->slug !== null && $image->slug !== '') {
                $slug = $image->slug;

                break;
            }
        }
    }

    if ($slug === null) {
        $io->warn('no distribution image with a slug was visible, so the probe cannot run');
    } else {
        // Both sides fetch their own list. The unfiltered count is not reused from above:
        // if the two disagree on how many sizes exist, the comparison below was never
        // comparing the same thing and the numbers say so.
        $unfiltered = $binarylane->sizes()->all();
        $filtered = $binarylane->sizes()->forImage($slug);

        $regionsOf = static function (array $list): array {
            $all = [];

            foreach ($list as $size) {
                foreach ($size->regions as $region) {
                    $all[$region] = true;
                }
            }

            ksort($all);

            return array_keys($all);
        };

        $before = $regionsOf($unfiltered);
        $after = $regionsOf($filtered);

        $io->values([
            'image' => $slug,
            'sizes unfiltered' => count($unfiltered),
            'sizes filtered' => count($filtered),
            'regions unfiltered' => implode(', ', $before) ?: '(none)',
            'regions filtered' => implode(', ', $after) ?: '(none)',
        ]);

        $io->line();

        if ($before === $after && count($unfiltered) === count($filtered)) {
            $io->warn('IDENTICAL on both counts. Either this image really is available everywhere');
            $io->warn('this catalogue reaches - which is plausible for a common distribution - or');
            $io->warn('the filter is being ignored. Re-run with BINARYLANE_IMAGE set to something');
            $io->warn('scarcer before believing the first reading.');
        } else {
            $io->success('the filter changed the answer, so it is being honoured');
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
