<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Entity\Size;
use Hampel\BinaryLane\Api\Enum\ImageQueryType;
use Hampel\BinaryLane\Api\Enum\ImageStatus;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Request\License;

/**
 * Sizes, regions, images and the software catalogue - the read-only surface a create form is
 * built from.
 */
final class CatalogueTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sizeRow(array $overrides = []): array
    {
        return $overrides + [
            'slug' => 'std-2vcpu',
            'size_type' => ['slug' => 'standard', 'name' => 'Standard'],
            'available' => true,
            'regions' => ['syd', 'mel', 'per'],
            'regions_out_of_stock' => ['per'],
            'price_monthly' => 20.0,
            'price_hourly' => 0.03,
            'disk' => 80,
            'memory' => 4096,
            'transfer' => 1.0,
            'excess_transfer_cost_per_gigabyte' => 0.02,
            'vcpus' => 2,
            'vcpu_units' => 'core',
            'options' => [
                'disk_min' => 80,
                'disk_max' => 400,
                'disk_cost_per_additional_gigabyte' => 0.1,
                'restricted_disk_values' => null,
                'memory_max' => 16384,
                'memory_cost_per_additional_megabyte' => 0.005,
                'transfer_max' => 10.0,
                'transfer_cost_per_additional_gigabyte' => 0.01,
                'ipv4_addresses_max' => 8,
                'ipv4_addresses_cost_per_address' => 3.0,
                'discount_for_no_public_ipv4' => 1.5,
                'daily_backups' => 0,
                'weekly_backups' => 0,
                'monthly_backups' => 0,
                'backups_cost_per_backup_per_gigabyte' => 0.02,
                'offsite_backups_cost_per_gigabyte' => 0.03,
                'offsite_backup_frequency_cost' => [
                    'daily_per_gigabyte' => 0.05,
                    'weekly_per_gigabyte' => 0.03,
                    'monthly_per_gigabyte' => 0.01,
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------------------------
    // Sizes
    // ------------------------------------------------------------------------------------

    /**
     * Three conditions decide whether a size can be used in a region, they fail independently,
     * and all three produce the same 400.
     */
    public function testAvailabilityIsThreeSeparateQuestions(): void
    {
        $size = Size::fromArray($this->sizeRow());

        $this->assertTrue($size->isAvailableIn('syd'));
        $this->assertTrue($size->isOfferedIn('per'));
        $this->assertTrue($size->isOutOfStockIn('per'));
        $this->assertFalse($size->isAvailableIn('per'), 'offered there, but out of stock');
        $this->assertFalse($size->isAvailableIn('bne'), 'not offered there at all');
        $this->assertSame(['syd', 'mel'], $size->availableRegions());
    }

    public function testARetiredSizeIsAvailableNowhere(): void
    {
        $size = Size::fromArray($this->sizeRow(['available' => false]));

        $this->assertFalse($size->isAvailableIn('syd'));
        $this->assertSame([], $size->availableRegions());
        $this->assertTrue($size->isOfferedIn('syd'), 'still listed against the region');
    }

    public function testTheUnitsAreBinaryLanesOwn(): void
    {
        $size = Size::fromArray($this->sizeRow());

        $this->assertSame(4096 * 1024 ** 2, $size->memoryBytes());
        $this->assertSame(80 * 1024 ** 3, $size->diskBytes());
        $this->assertSame(1000.0, $size->transferGigabytes(), 'a TB here is 1000 GB');
        $this->assertEqualsWithDelta(2.0, $size->excessTransferCost(100), 0.0001);
    }

    public function testTheSizeFiltersAreSentAsQueryParameters(): void
    {
        $this->client->pushJson(200, $this->collection([$this->sizeRow()], 'sizes'));

        $this->binarylane()->sizes()->list(serverId: 1234, image: 'ubuntu-24-04-lts');

        $query = urldecode($this->sentQuery());

        $this->assertStringContainsString('server_id=1234', $query);
        $this->assertStringContainsString('image=ubuntu-24-04-lts', $query);
    }

    public function testAvailableInAppliesEveryCondition(): void
    {
        $this->client->pushJson(200, $this->collection([
            $this->sizeRow(),
            $this->sizeRow(['slug' => 'std-4vcpu', 'available' => false]),
            $this->sizeRow(['slug' => 'std-min', 'regions' => ['mel']]),
        ], 'sizes'));

        $sizes = $this->binarylane()->sizes()->availableIn('syd');

        $this->assertSame(['std-2vcpu'], array_map(static fn (Size $s): string => $s->slug, $sizes));
    }

    public function testFindWalksTheCatalogue(): void
    {
        $this->client->pushJson(200, $this->collection([
            $this->sizeRow(),
            $this->sizeRow(['slug' => 'std-4vcpu']),
        ], 'sizes'));

        $this->assertSame('std-4vcpu', $this->binarylane()->sizes()->find('std-4vcpu')?->slug);
    }

    public function testForResizeRefusesANonPositiveServerId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->sizes()->forResize(0);
    }

    /**
     * A size may restrict disk to particular values rather than a range - the range alone is
     * then not sufficient.
     */
    public function testRestrictedDiskValuesNarrowTheRange(): void
    {
        $row = $this->sizeRow();
        $sizeOptions = $row['options'];

        $this->assertIsArray($sizeOptions);

        $sizeOptions['restricted_disk_values'] = [80, 160, 320];
        $row['options'] = $sizeOptions;

        $options = Size::fromArray($row)->options;

        $this->assertNotNull($options);
        $this->assertTrue($options->hasRestrictedDiskValues());
        $this->assertTrue($options->allowsDisk(160));
        $this->assertFalse($options->allowsDisk(200), 'inside the range and not on the list');
        $this->assertFalse($options->allowsDisk(500), 'outside the range');
    }

    public function testAnUnrestrictedSizeAllowsTheWholeRange(): void
    {
        $options = Size::fromArray($this->sizeRow())->options;

        $this->assertNotNull($options);
        $this->assertFalse($options->hasRestrictedDiskValues());
        $this->assertTrue($options->allowsDisk(200));
        $this->assertFalse($options->allowsDisk(500));
    }

    /**
     * Only the most frequent enabled schedule is charged, so the three rates are not summed.
     */
    public function testOffsiteBackupCostFollowsTheMostFrequentSchedule(): void
    {
        $cost = Size::fromArray($this->sizeRow())->options?->offsiteBackupFrequencyCost;

        $this->assertNotNull($cost);
        $this->assertSame(0.05, $cost->forMostFrequent(daily: true, weekly: true, monthly: true));
        $this->assertSame(0.03, $cost->forMostFrequent(daily: false, weekly: true, monthly: true));
        $this->assertSame(0.0, $cost->forMostFrequent(daily: false, weekly: false, monthly: false));
    }

    // ------------------------------------------------------------------------------------
    // Regions
    // ------------------------------------------------------------------------------------

    public function testRegionsReportWhatTheyOffer(): void
    {
        $this->client->pushJson(200, $this->collection([
            ['slug' => 'syd', 'name' => 'Sydney', 'available' => true, 'sizes' => ['std-2vcpu'], 'features' => ['vpc']],
            ['slug' => 'per', 'name' => 'Perth', 'available' => false, 'sizes' => ['std-2vcpu']],
        ], 'regions'));

        $regions = $this->binarylane()->regions()->offering('std-2vcpu');

        $this->assertCount(1, $regions, 'Perth offers it and takes no new resources');
        $this->assertSame('syd', $regions[0]->slug);
        $this->assertTrue($regions[0]->supports('vpc'));
    }

    // ------------------------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------------------------

    public function testImagesCanBeFetchedBySlugOrId(): void
    {
        $this->client->pushJson(200, ['image' => ['id' => 100, 'slug' => 'ubuntu-24-04-lts', 'name' => 'Ubuntu', 'status' => 'available']]);

        $image = $this->binarylane()->images()->get('ubuntu-24-04-lts');

        $this->assertSame('/v2/images/ubuntu-24-04-lts', $this->sentPath());
        $this->assertSame(ImageStatus::Available, $image->status);
        $this->assertSame('ubuntu-24-04-lts', $image->reference());
    }

    /**
     * A backup has no slug, so its id is the only reference that finds it.
     */
    public function testABackupsReferenceIsItsId(): void
    {
        $image = Image::fromArray(['id' => 9001, 'type' => 'backup', 'name' => 'nightly']);

        $this->assertSame('9001', $image->reference());
        $this->assertTrue($image->isBackup());
        $this->assertFalse($image->isDistribution());
    }

    /**
     * `NEW` is upper case on the wire, alone among the four statuses.
     */
    public function testTheNewImageStatusIsUpperCase(): void
    {
        $this->assertSame(ImageStatus::New, ImageStatus::tryFrom('NEW'));
        $this->assertNull(ImageStatus::tryFrom('new'));
    }

    /**
     * The API ignores `private=false`, so sending one would suggest a filter that is not being
     * applied.
     */
    public function testAFalsePrivateFilterIsNotSent(): void
    {
        $this->client->pushJson(200, $this->collection([], 'images'));
        $this->binarylane()->images()->list(privateOnly: false);
        $this->assertStringNotContainsString('private', $this->sentQuery());

        $this->client->pushJson(200, $this->collection([], 'images'));
        $this->binarylane()->images()->list(privateOnly: true);
        $this->assertStringContainsString('private=true', $this->sentQuery());
    }

    public function testTheImageTypeFilterIsSent(): void
    {
        $this->client->pushJson(200, $this->collection([], 'images'));

        $this->binarylane()->images()->list(type: ImageQueryType::Distribution);

        $this->assertStringContainsString('type=distribution', urldecode($this->sentQuery()));
    }

    public function testAnImageUpdateWithNothingToChangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->images()->update(9001);
    }

    public function testAnImageCanBeLockedAgainstReplacement(): void
    {
        $this->client->pushJson(200, ['image' => ['id' => 9001, 'name' => 'keep me']]);

        $this->binarylane()->images()->update(9001, 'keep me', true);

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertSame(['name' => 'keep me', 'locked' => true], $this->sentBody());
    }

    /**
     * An empty string clears the display name; null leaves it unchanged.
     */
    public function testAnEmptyImageNameIsSentBecauseItClearsTheName(): void
    {
        $this->client->pushJson(200, ['image' => ['id' => 9001]]);

        $this->binarylane()->images()->update(9001, '');

        $this->assertSame(['name' => ''], $this->sentBody());
    }

    public function testDownloadUrlsAreWithheldFromDebugOutput(): void
    {
        $this->client->pushJson(200, ['link' => [
            'id' => 9001,
            'expiry' => '2026-09-12T04:00:00Z',
            'disks' => [
                ['id' => 1, 'compressed_url' => 'https://dl.example.test/a.gz?sig=secret', 'raw_url' => 'https://dl.example.test/a.raw?sig=secret'],
            ],
        ]]);

        $download = $this->binarylane()->images()->download(9001);

        $this->assertSame('/v2/images/9001/download', $this->sentPath());
        $this->assertSame(['https://dl.example.test/a.gz?sig=secret'], $download->urls(), 'the compressed one is preferred');
        $this->assertStringNotContainsString('sig=secret', print_r($download, true));
        $this->assertSame(3600, $download->expiresIn(new \DateTimeImmutable('2026-09-12T03:00:00Z')));
    }

    // ------------------------------------------------------------------------------------
    // Software
    // ------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function softwareRow(int $id, string $group = 'control-panel'): array
    {
        return [
            'id' => $id,
            'name' => 'Panel ' . $id,
            'description' => 'A control panel',
            'enabled' => true,
            'cost_per_licence_per_month' => 15.0,
            'minimum_licence_count' => 1,
            'maximum_licence_count' => 10,
            'licence_step_count' => 5,
            'group' => $group,
            'supported_operating_systems' => ['ubuntu-24-04-lts'],
        ];
    }

    public function testALicenceCountIsCheckedAgainstAllThreeRules(): void
    {
        $this->client->pushJson(200, ['software' => $this->softwareRow(1)]);

        $software = $this->binarylane()->software()->get(1);

        $this->assertTrue($software->allowsCount(5));
        $this->assertFalse($software->allowsCount(3), 'not a multiple of the step');
        $this->assertFalse($software->allowsCount(15), 'above the maximum');
        $this->assertSame(5, $software->roundUpCount(3));
        $this->assertNull($software->roundUpCount(11));
    }

    public function testALicenceRequestNamesTheRuleItBroke(): void
    {
        $software = \Hampel\BinaryLane\Api\Entity\Software::fromArray($this->softwareRow(1));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The nearest acceptable count is 5');

        License::forSoftware($software, 3);
    }

    public function testWithdrawnSoftwareCannotBeLicensedAnew(): void
    {
        $software = \Hampel\BinaryLane\Api\Entity\Software::fromArray(
            ['enabled' => false] + $this->softwareRow(1)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not enabled');

        License::forSoftware($software, 5);
    }

    /**
     * Software sharing a group cannot be licensed together, and that can only be checked
     * across the whole set.
     */
    public function testTwoProductsInOneGroupConflict(): void
    {
        $catalogue = [
            1 => \Hampel\BinaryLane\Api\Entity\Software::fromArray($this->softwareRow(1)),
            2 => \Hampel\BinaryLane\Api\Entity\Software::fromArray($this->softwareRow(2)),
            3 => \Hampel\BinaryLane\Api\Entity\Software::fromArray($this->softwareRow(3, 'monitoring')),
        ];

        $conflict = License::conflictIn([new License(1, 5), new License(2, 5)], $catalogue);

        $this->assertNotNull($conflict);
        $this->assertSame([1, 2], [$conflict[0]->id, $conflict[1]->id]);

        $this->assertNull(License::conflictIn([new License(1, 5), new License(3, 5)], $catalogue));
    }

    public function testSoftwareForAnOperatingSystemUsesItsOwnPath(): void
    {
        $this->client->pushJson(200, $this->collection([$this->softwareRow(1)], 'software'));

        $available = $this->binarylane()->software()->availableFor('ubuntu-24-04-lts');

        $this->assertCount(1, $available);
        $this->assertSame('/v2/software/operating_system/ubuntu-24-04-lts', $this->sentPath());
    }

    public function testTheCatalogueCanBeKeyedById(): void
    {
        $this->client->pushJson(200, $this->collection([
            $this->softwareRow(1),
            $this->softwareRow(2),
        ], 'software'));

        $catalogue = $this->binarylane()->software()->byId();

        $this->assertSame([1, 2], array_keys($catalogue));
        $this->assertSame('Panel 2', $catalogue[2]->name);
    }
}
