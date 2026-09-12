<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Enum\AccountStatus;
use Hampel\BinaryLane\Api\Enum\PaymentMethod;
use Hampel\BinaryLane\Api\Enum\TaxCodeType;
use Hampel\BinaryLane\Api\Exception\NotAuthenticatedException;

final class AccountTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountBody(array $overrides = []): array
    {
        return ['account' => $overrides + [
            'email' => 'someone@example.test',
            'email_verified' => true,
            'two_factor_authentication_enabled' => true,
            'status' => 'active',
            'tax_code' => ['name' => 'GST', 'type' => 'scalar', 'fixed_percent' => 10.0],
            'configured_payment_methods' => ['credit-card'],
            'additional_ipv4_limit' => 8,
        ]];
    }

    public function testItReadsTheAccount(): void
    {
        $this->client->pushJson(200, $this->accountBody());

        $account = $this->binarylane()->account()->get();

        $this->assertSame('/v2/account', $this->sentPath());
        $this->assertSame('someone@example.test', $account->email);
        $this->assertTrue($account->emailVerified);
        $this->assertTrue($account->twoFactorAuthenticationEnabled);
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertSame(8, $account->additionalIpv4Limit);
        $this->assertTrue($account->isActive());
    }

    public function testVerifyIsTheAccountReadUnderAnotherName(): void
    {
        $this->client->pushJson(200, $this->accountBody());

        $this->assertTrue($this->binarylane()->verify()->isActive());
        $this->assertSame('/v2/account', $this->sentPath());
    }

    public function testVerifyRaisesForATokenThatDoesNotWork(): void
    {
        $this->client->pushRaw(401, '');

        $this->expectException(NotAuthenticatedException::class);

        $this->binarylane()->verify();
    }

    public function testItReadsTheTaxCode(): void
    {
        $this->client->pushJson(200, $this->accountBody());

        $tax = $this->binarylane()->account()->get()->taxCode;

        $this->assertNotNull($tax);
        $this->assertSame(TaxCodeType::Scalar, $tax->type);
        $this->assertSame('GST', $tax->name);
        $this->assertTrue($tax->applies());
        $this->assertSame(1.1, $tax->multiplier());
        $this->assertEqualsWithDelta(10.0, $tax->on(100.0), 0.0001);
    }

    public function testATaxCodeOfNoneAddsNothing(): void
    {
        $this->client->pushJson(200, $this->accountBody(['tax_code' => ['name' => 'None', 'type' => 'none']]));

        $tax = $this->binarylane()->account()->get()->taxCode;

        $this->assertNotNull($tax);
        $this->assertFalse($tax->applies());
        $this->assertSame(1.0, $tax->multiplier());
        $this->assertSame(0.0, $tax->on(100.0));
    }

    public function testItReadsTheConfiguredPaymentMethods(): void
    {
        $this->client->pushJson(200, $this->accountBody(['configured_payment_methods' => ['credit-card', 'paypal']]));

        $account = $this->binarylane()->account()->get();

        $this->assertTrue($account->usesPaymentMethod(PaymentMethod::CreditCard));
        $this->assertTrue($account->usesPaymentMethod(PaymentMethod::PayPal));
        $this->assertTrue($account->canBeBilled());
    }

    /**
     * "No configured payment method" is a condition an integration acts on, so a method this
     * package has no case for must not look like no method at all.
     */
    public function testAPaymentMethodItDoesNotRecogniseIsKeptRatherThanDropped(): void
    {
        $this->client->pushJson(200, $this->accountBody(['configured_payment_methods' => ['bank-transfer']]));

        $account = $this->binarylane()->account()->get();

        $this->assertSame([], $account->configuredPaymentMethods);
        $this->assertSame(['bank-transfer'], $account->unknownPaymentMethods);
        $this->assertTrue($account->canBeBilled());
    }

    public function testAnAccountWithNoPaymentMethodSaysSo(): void
    {
        $this->client->pushJson(200, $this->accountBody([
            'status' => 'incomplete',
            'configured_payment_methods' => [],
        ]));

        $account = $this->binarylane()->account()->get();

        $this->assertFalse($account->canBeBilled());
        $this->assertFalse($account->isActive());
        $this->assertTrue($account->status?->needsAttention());
    }

    /**
     * A status added to the API after this release must not read as active.
     */
    public function testAnUnrecognisedStatusIsNotTreatedAsActive(): void
    {
        $this->client->pushJson(200, $this->accountBody(['status' => 'suspended-pending-review']));

        $account = $this->binarylane()->account()->get();

        $this->assertNull($account->status);
        $this->assertFalse($account->isActive());
    }

    public function testItKeepsTheRawBodyForFieldsItDoesNotName(): void
    {
        $this->client->pushJson(200, $this->accountBody(['something_new' => 'value']));

        $account = $this->binarylane()->account()->get();

        $this->assertSame('value', $account->raw['something_new']);

        $encoded = json_decode(json_encode($account, JSON_THROW_ON_ERROR), true);

        $this->assertIsArray($encoded);
        $this->assertSame('value', $encoded['something_new']);
    }
}
