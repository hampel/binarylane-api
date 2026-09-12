<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\SshKey;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

final class SshKeysTest extends TestCase
{
    private const PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyMaterial someone@example.test';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function keyRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 5,
            'fingerprint' => 'SHA256:abcdef0123456789',
            'public_key' => self::PUBLIC_KEY,
            'name' => 'laptop',
            'default' => false,
        ];
    }

    public function testItReadsAKeyById(): void
    {
        $this->client->pushJson(200, ['ssh_key' => $this->keyRow()]);

        $key = $this->binarylane()->sshKeys()->get(5);

        $this->assertSame('/v2/account/keys/5', $this->sentPath());
        $this->assertSame(5, $key->id);
        $this->assertSame('laptop', $key->name);
        $this->assertFalse($key->isDeployedByDefault());
    }

    /**
     * The API takes either, which is what makes it possible to check whether a key from an
     * authorized_keys file is already here.
     */
    public function testAKeyCanBeAddressedByFingerprint(): void
    {
        $this->client->pushJson(200, ['ssh_key' => $this->keyRow()]);

        $this->binarylane()->sshKeys()->get('SHA256:abcdef0123456789');

        $this->assertSame('/v2/account/keys/SHA256:abcdef0123456789', $this->sentPath());
    }

    public function testExistsAnswersFalseForAKeyTheAccountDoesNotHold(): void
    {
        $this->client->pushJson(404, $this->problem('Not Found'));

        $this->assertFalse($this->binarylane()->sshKeys()->exists('SHA256:nothere'));
    }

    public function testItReadsTheAlgorithmAndCommentOffTheKeyItself(): void
    {
        $key = SshKey::fromArray($this->keyRow());

        $this->assertSame('ssh-ed25519', $key->algorithm());
        $this->assertSame('someone@example.test', $key->comment());
        $this->assertSame('5', $key->reference());
    }

    public function testCreateSendsTheWholeAuthorizedKeysLine(): void
    {
        $this->client->pushJson(200, ['ssh_key' => $this->keyRow()]);

        $this->binarylane()->sshKeys()->create(self::PUBLIC_KEY, 'laptop');

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame('/v2/account/keys', $this->sentPath());
        $this->assertSame([
            'public_key' => self::PUBLIC_KEY,
            'name' => 'laptop',
            'default' => false,
        ], $this->sentBody());
    }

    public function testCreateRefusesAnEmptyKeyOrName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->sshKeys()->create(self::PUBLIC_KEY, '   ');
    }

    /**
     * The specification marks the name required on an update, even when only the default flag
     * is changing.
     */
    public function testUpdateRequiresTheNameEvenToChangeOnlyTheDefaultFlag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires its name');

        $this->binarylane()->sshKeys()->update(5, '', true);
    }

    public function testUpdateOmitsTheDefaultFlagWhenItIsNotBeingChanged(): void
    {
        $this->client->pushJson(200, ['ssh_key' => $this->keyRow(['name' => 'workstation'])]);

        $this->binarylane()->sshKeys()->update(5, 'workstation');

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertSame(['name' => 'workstation'], $this->sentBody());
    }

    public function testUpdateSendsTheDefaultFlagWhenItIsBeingChanged(): void
    {
        $this->client->pushJson(200, ['ssh_key' => $this->keyRow(['default' => true])]);

        $this->binarylane()->sshKeys()->update(5, 'laptop', true);

        $this->assertSame(['name' => 'laptop', 'default' => true], $this->sentBody());
    }

    public function testDeleteAnswersWithNothing(): void
    {
        $this->client->pushRaw(204, '');

        $this->binarylane()->sshKeys()->delete(5);

        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame('/v2/account/keys/5', $this->sentPath());
    }

    /**
     * A default key reaches every future server created without an explicit key list, which is
     * the widest-reaching thing in this endpoint.
     */
    public function testDefaultsAreTheKeysThatReachEveryNewServer(): void
    {
        $this->client->pushJson(200, $this->collection([
            $this->keyRow(['id' => 5, 'default' => false]),
            $this->keyRow(['id' => 6, 'name' => 'ci', 'default' => true]),
        ], 'ssh_keys'));

        $defaults = $this->binarylane()->sshKeys()->defaults();

        $this->assertCount(1, $defaults);
        $this->assertSame(6, $defaults[0]->id);
    }

    public function testAKeyIdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->sshKeys()->get(0);
    }

    public function testAnEmptyFingerprintIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->sshKeys()->get('  ');
    }
}
