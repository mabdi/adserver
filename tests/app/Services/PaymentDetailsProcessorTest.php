<?php

/**
 * Copyright (c) 2018-2022 Adshares sp. z o.o.
 *
 * This file is part of AdServer
 *
 * AdServer is free software: you can redistribute and/or modify it
 * under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AdServer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AdServer. If not, see <https://www.gnu.org/licenses/>
 */

declare(strict_types=1);

namespace Adshares\Adserver\Tests\Services;

use Adshares\Adserver\Exceptions\MissingInitialConfigurationException;
use Adshares\Adserver\Models\AdsPayment;
use Adshares\Adserver\Models\Config;
use Adshares\Adserver\Models\ConfigException;
use Adshares\Adserver\Models\NetworkCase;
use Adshares\Adserver\Models\NetworkCasePayment;
use Adshares\Adserver\Models\NetworkImpression;
use Adshares\Adserver\Models\NetworkPayment;
use Adshares\Adserver\Models\User;
use Adshares\Adserver\Models\UserLedgerEntry;
use Adshares\Adserver\Services\PaymentDetailsProcessor;
use Adshares\Adserver\Tests\TestCase;
use Adshares\Common\Application\Dto\ExchangeRate;
use Adshares\Common\Domain\ValueObject\AccountId;
use Adshares\Common\Infrastructure\Service\ExchangeRateReader;
use Adshares\Common\Infrastructure\Service\LicenseReader;
use DateTime;
use Illuminate\Support\Facades\DB;

final class PaymentDetailsProcessorTest extends TestCase
{
    private const LICENSE_FEE = 0.01;

    private const OPERATOR_FEE = 0.01;

    public function testProcessingEmptyDetails(): void
    {
        $paymentDetailsProcessor = $this->getPaymentDetailsProcessor();

        $adsPayment = $this->createAdsPayment(10000);

        $paymentDetailsProcessor->processPaidEvents($adsPayment, new DateTime(), [], 0);

        $this->assertCount(0, NetworkPayment::all());
    }

    public function testProcessingMissingConfigurationOfOperatorFee(): void
    {
        DB::delete('DELETE FROM configs WHERE `key` = ?', [Config::OPERATOR_RX_FEE]);

        $paymentDetailsProcessor = $this->getPaymentDetailsProcessor();

        $adsPayment = $this->createAdsPayment(10000);

        self::expectException(MissingInitialConfigurationException::class);
        $paymentDetailsProcessor->processPaidEvents($adsPayment, new DateTime(), [], 0);
    }

    public function testProcessingMissingConfigurationOfLicenseFee(): void
    {
        $licenseReader = self::createMock(LicenseReader::class);
        $licenseReader->expects(self::once())->method('getFee')->willThrowException(new ConfigException('test'));

        $paymentDetailsProcessor = new PaymentDetailsProcessor(
            $this->getExchangeRateReader(),
            $licenseReader
        );

        $adsPayment = $this->createAdsPayment(10000);

        self::expectException(MissingInitialConfigurationException::class);
        $paymentDetailsProcessor->processPaidEvents($adsPayment, new DateTime(), [], 0);
    }

    public function testProcessingDetails(): void
    {
        $totalPayment = 10000;
        $paidEventsCount = 2;

        $paymentDetailsProcessor = $this->getPaymentDetailsProcessor();

        /** @var User $user */
        $user = factory(User::class)->create();
        $userUuid = $user->uuid;

        $networkImpression = factory(NetworkImpression::class)->create();
        $networkCases = factory(NetworkCase::class)->times($paidEventsCount)->create(
            ['network_impression_id' => $networkImpression->id, 'publisher_id' => $userUuid]
        );

        $adsPayment = $this->createAdsPayment($totalPayment);

        $paymentDetails = [];
        foreach ($networkCases as $networkCase) {
            $paymentDetails[] = [
                'case_id' => $networkCase->case_id,
                'event_id' => $networkCase->event_id,
                'event_type' => $networkCase->event_type,
                'banner_id' => $networkCase->banner_id,
                'zone_id' => $networkCase->zone_id,
                'publisher_id' => $userUuid,
                'event_value' => $totalPayment / $paidEventsCount,
            ];
        }

        $result = $paymentDetailsProcessor->processPaidEvents($adsPayment, new DateTime(), $paymentDetails, 0);

        $expectedLicenseAmount = 0;
        $expectedOperatorAmount = 0;
        foreach ($paymentDetails as $paymentDetail) {
            $eventValue = $paymentDetail['event_value'];
            $eventLicenseAmount = (int)floor(self::LICENSE_FEE * $eventValue);
            $expectedLicenseAmount += $eventLicenseAmount;
            $expectedOperatorAmount += (int)floor(self::OPERATOR_FEE * ($eventValue - $eventLicenseAmount));
        }
        $expectedAdIncome = $totalPayment - $expectedLicenseAmount - $expectedOperatorAmount;

        $this->assertEquals($totalPayment, $result->eventValuePartialSum());
        $this->assertEquals($expectedLicenseAmount, $result->licenseFeePartialSum());
        $this->assertEquals($expectedAdIncome, NetworkCasePayment::sum('paid_amount'));
    }

    public function testAddAdIncomeToUserLedger(): void
    {
        $adsPayment = $this->createAdsPayment(100_000_000_000);
        /** @var User $user */
        $user = factory(User::class)->create();
        /** @var NetworkCase $networkCase */
        $networkCase = factory(NetworkCase::class)->create([
            'publisher_id' => $user->uuid,
        ]);
        /** @var NetworkCasePayment $networkCasePayment */
        $networkCasePayment = factory(NetworkCasePayment::class)->create([
            'network_case_id' => $networkCase->id,
            'ads_payment_id' => $adsPayment->id,
        ]);
        $paymentDetailsProcessor = $this->getPaymentDetailsProcessor();

        $paymentDetailsProcessor->addAdIncomeToUserLedger($adsPayment);

        $entries = UserLedgerEntry::all();
        self::assertCount(1, $entries);
        self::assertEquals($networkCasePayment->paid_amount, $entries->first()->amount);
    }

    public function testAddAdIncomeToUserLedgerWhenNoUser(): void
    {
        $adsPayment = $this->createAdsPayment(100_000_000_000);
        /** @var NetworkCase $networkCase */
        $networkCase = factory(NetworkCase::class)->create([
            'publisher_id' => '10000000000000000000000000000000',
        ]);
        /** @var NetworkCasePayment $networkCasePayment */
        factory(NetworkCasePayment::class)->create([
            'network_case_id' => $networkCase->id,
            'ads_payment_id' => $adsPayment->id,
        ]);
        $paymentDetailsProcessor = $this->getPaymentDetailsProcessor();

        $paymentDetailsProcessor->addAdIncomeToUserLedger($adsPayment);

        $entries = UserLedgerEntry::all();
        self::assertCount(0, $entries);
    }

    private function getExchangeRateReader(): ExchangeRateReader
    {
        $value = 1;

        $exchangeRateReader = $this->createMock(ExchangeRateReader::class);
        $exchangeRateReader->method('fetchExchangeRate')->willReturn(new ExchangeRate(new DateTime(), $value, 'USD'));

        /** @var ExchangeRateReader $exchangeRateReader */
        return $exchangeRateReader;
    }

    private function getLicenseReader(): LicenseReader
    {
        $licenseReader = $this->createMock(LicenseReader::class);
        $licenseReader->method('getAddress')->willReturn(new AccountId('0001-00000000-9B6F'));
        $licenseReader->method('getFee')->willReturn(self::LICENSE_FEE);

        /** @var LicenseReader $licenseReader */
        return $licenseReader;
    }

    private function getPaymentDetailsProcessor(): PaymentDetailsProcessor
    {
        return new PaymentDetailsProcessor(
            $this->getExchangeRateReader(),
            $this->getLicenseReader()
        );
    }

    private function createAdsPayment(int $amount): AdsPayment
    {
        $adsPayment = new AdsPayment();
        $adsPayment->txid = '0002:000017C3:0001';
        $adsPayment->amount = $amount;
        $adsPayment->address = '0002-00000007-055A';
        $adsPayment->save();

        return $adsPayment;
    }
}
