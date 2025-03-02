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

// phpcs:ignoreFile PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound

declare(strict_types=1);

namespace Adshares\Adserver\Tests\Http\Controllers\Manager;

use Adshares\Adserver\Mail\UserConfirmed;
use Adshares\Adserver\Mail\UserEmailActivate;
use Adshares\Adserver\Mail\UserEmailChangeConfirm1Old;
use Adshares\Adserver\Mail\UserPasswordChange;
use Adshares\Adserver\Mail\UserPasswordChangeConfirm;
use Adshares\Adserver\Models\Config;
use Adshares\Adserver\Models\RefLink;
use Adshares\Adserver\Models\Token;
use Adshares\Adserver\Models\User;
use Adshares\Adserver\Models\UserLedgerEntry;
use Adshares\Adserver\Tests\TestCase;
use Adshares\Common\Application\Service\Exception\ExchangeRateNotAvailableException;
use Adshares\Common\Application\Service\ExchangeRateRepository;
use Adshares\Common\Domain\ValueObject\WalletAddress;
use Adshares\Config\RegistrationMode;
use Adshares\Mock\Client\DummyExchangeRateRepository;
use DateTime;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class AuthControllerTest extends TestCase
{
    private const CHECK_URI = '/auth/check';
    private const SELF_URI = '/auth/self';
    private const PASSWORD_CONFIRM = '/auth/password/confirm';
    private const PASSWORD_URI = '/auth/password';
    private const EMAIL_ACTIVATE_URI = '/auth/email/activate';
    private const EMAIL_URI = '/auth/email';
    private const LOG_IN_URI = '/auth/login';
    private const LOG_OUT_URI = '/auth/logout';
    private const REGISTER_USER = '/auth/register';
    private const WALLET_LOGIN_INIT_URI = '/auth/login/wallet/init';
    private const WALLET_LOGIN_URI = '/auth/login/wallet';

    private const STRUCTURE_CHECK = [
        'uuid',
        'email',
        'isAdvertiser',
        'isPublisher',
        'isAdmin',
        'adserverWallet' => [
            'totalFunds',
            'totalFundsInCurrency',
            'totalFundsChange',
            'bonusBalance',
            'walletBalance',
            'walletAddress',
            'walletNetwork',
            'lastPaymentAt',
            'isAutoWithdrawal',
            'autoWithdrawalLimit',
        ],
        'isEmailConfirmed',
        'isConfirmed',
        'exchangeRate' => [
            'validAt',
            'value',
            'currency',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Config::updateAdminSettings([Config::EMAIL_VERIFICATION_REQUIRED => '1']);
        Config::updateAdminSettings([Config::AUTO_CONFIRMATION_ENABLED => '1']);
        Config::updateAdminSettings([Config::REGISTRATION_MODE => RegistrationMode::PUBLIC]);
    }

    public function testPublicRegister(): void
    {
        $user = $this->registerUser();
        Mail::assertQueued(UserEmailActivate::class);
        self::assertCount(1, Token::all());

        $this->assertFalse($user->is_email_confirmed);
        $this->assertFalse($user->is_admin_confirmed);
        $this->assertFalse($user->is_confirmed);
        $this->assertNull($user->refLink);

        $this->activateUser($user);
        self::assertEmpty(Token::all());
        $this->assertTrue($user->is_email_confirmed);
        $this->assertTrue($user->is_admin_confirmed);
        $this->assertTrue($user->is_confirmed);
        $this->assertNull($user->refLink);
        Mail::assertNotQueued(UserConfirmed::class);
    }

    public function testManualActivationManualConfirmationRegister(): void
    {
        Config::updateAdminSettings([Config::EMAIL_VERIFICATION_REQUIRED => '1']);
        Config::updateAdminSettings([Config::AUTO_CONFIRMATION_ENABLED => '0']);

        $user = $this->registerUser();
        $this->assertFalse($user->is_email_confirmed);
        $this->assertFalse($user->is_admin_confirmed);
        $this->assertFalse($user->is_confirmed);

        $this->activateUser($user);
        $this->assertTrue($user->is_email_confirmed);
        $this->assertFalse($user->is_admin_confirmed);
        $this->assertFalse($user->is_confirmed);

        $this->actingAs(factory(User::class)->create(['is_admin' => 1]), 'api');
        $this->confirmUser($user);
        $this->assertTrue($user->is_email_confirmed);
        $this->assertTrue($user->is_admin_confirmed);
        $this->assertTrue($user->is_confirmed);
        Mail::assertQueued(UserConfirmed::class);
    }

    public function testAutoActivationAutoConfirmationRegister(): void
    {
        Config::updateAdminSettings([Config::EMAIL_VERIFICATION_REQUIRED => '0']);
        Config::updateAdminSettings([Config::AUTO_CONFIRMATION_ENABLED => '1']);

        $user = $this->registerUser();
        $this->assertTrue($user->is_email_confirmed);
        $this->assertTrue($user->is_admin_confirmed);
        $this->assertTrue($user->is_confirmed);
    }

    public function testAutoActivationManualConfirmationRegister(): void
    {
        Config::updateAdminSettings([Config::EMAIL_VERIFICATION_REQUIRED => '0']);
        Config::updateAdminSettings([Config::AUTO_CONFIRMATION_ENABLED => '0']);

        $user = $this->registerUser();
        $this->assertTrue($user->is_email_confirmed);
        $this->assertFalse($user->is_admin_confirmed);
        $this->assertFalse($user->is_confirmed);

        $this->actingAs(factory(User::class)->create(['is_admin' => 1]), 'api');
        $this->confirmUser($user);
        $this->assertTrue($user->is_email_confirmed);
        $this->assertTrue($user->is_admin_confirmed);
        $this->assertTrue($user->is_confirmed);
        Mail::assertQueued(UserConfirmed::class);
    }

    public function testRestrictedRegister(): void
    {
        Config::updateAdminSettings([Config::REGISTRATION_MODE => RegistrationMode::RESTRICTED]);

        $user = $this->registerUser(null, Response::HTTP_FORBIDDEN);
        $this->assertNull($user);

        $user = $this->registerUser('dummy-token', Response::HTTP_FORBIDDEN);
        $this->assertNull($user);

        /** @var RefLink $refLink */
        $refLink = factory(RefLink::class)->create(['single_use' => true]);
        $user = $this->registerUser($refLink->token);
        $this->assertNotNull($user);

        $user = $this->registerUser($refLink->token, Response::HTTP_FORBIDDEN);
        $this->assertNull($user);
    }

    public function testPrivateRegister(): void
    {
        Config::updateAdminSettings([Config::REGISTRATION_MODE => RegistrationMode::PRIVATE]);

        $user = $this->registerUser(null, Response::HTTP_FORBIDDEN);
        $this->assertNull($user);

        /** @var RefLink $refLink */
        $refLink = factory(RefLink::class)->create();
        $user = $this->registerUser($refLink->token, Response::HTTP_FORBIDDEN);
        $this->assertNull($user);
    }

    public function testRegisterWithReferral(): void
    {
        /** @var RefLink $refLink */
        $refLink = factory(RefLink::class)->create();
        $this->assertFalse($refLink->used);

        $user = $this->registerUser($refLink->token);
        $this->assertNotNull($user->refLink);
        $this->assertEquals($refLink->token, $user->refLink->token);
        $this->assertTrue($user->refLink->used);

        $this->activateUser($user);
        $this->assertNotNull($user->refLink);
        $this->assertEquals($refLink->token, $user->refLink->token);
        $this->assertTrue($user->refLink->used);
    }

    public function testRegisterWithInvalidReferral(): void
    {
        $user = $this->registerUser('dummy_token');
        $this->assertNull($user->refLink);
    }

    public function testEmailActivateWithBonus(): void
    {
        /** @var RefLink $refLink */
        $refLink = factory(RefLink::class)->create(['bonus' => 100, 'refund' => 0.5]);
        $user = $this->registerUser($refLink->token);

        self::assertSame(
            [0, 0, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $this->activateUser($user);

        self::assertSame(
            [300, 300, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $entry = UserLedgerEntry::where('user_id', $user->id)
            ->where('type', UserLedgerEntry::TYPE_BONUS_INCOME)
            ->firstOrFail();

        $this->assertEquals(300, $entry->amount);
        $this->assertNotNull($entry->refLink);
        $this->assertEquals($refLink->id, $entry->refLink->id);
    }

    public function testEmailActivateNoBonus(): void
    {
        /** @var RefLink $refLink */
        $refLink = factory(RefLink::class)->create(['bonus' => 0, 'refund' => 0.5]);
        $user = $this->registerUser($refLink->token);
        self::assertSame(
            [0, 0, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $this->activateUser($user);
        self::assertSame(
            [0, 0, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );
    }

    public function testActiveManualConfirmationWithBonus(): void
    {
        Config::updateAdminSettings([Config::AUTO_CONFIRMATION_ENABLED => '0']);

        /** @var RefLink $refLink */
        $refLink = factory(RefLink::class)->create(['bonus' => 100, 'refund' => 0.5]);
        $user = $this->registerUser($refLink->token);

        self::assertSame(
            [0, 0, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $this->activateUser($user);

        self::assertSame(
            [0, 0, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $this->actingAs(factory(User::class)->create(['is_admin' => 1]), 'api');
        $this->confirmUser($user);

        self::assertSame(
            [300, 300, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $entry = UserLedgerEntry::where('user_id', $user->id)
            ->where('type', UserLedgerEntry::TYPE_BONUS_INCOME)
            ->firstOrFail();

        $this->assertEquals(300, $entry->amount);
        $this->assertNotNull($entry->refLink);
        $this->assertEquals($refLink->id, $entry->refLink->id);
    }

    public function testInactiveManualConfirmationWithBonus(): void
    {
        Config::updateAdminSettings([Config::AUTO_CONFIRMATION_ENABLED => '0']);

        /** @var RefLink $refLink */
        $refLink = factory(RefLink::class)->create(['bonus' => 100, 'refund' => 0.5]);
        $user = $this->registerUser($refLink->token);

        self::assertSame(
            [0, 0, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $this->actingAs(factory(User::class)->create(['is_admin' => 1]), 'api');
        $this->confirmUser($user);

        self::assertSame(
            [0, 0, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $this->activateUser($user);

        self::assertSame(
            [300, 300, 0],
            [
                $user->getBalance(),
                $user->getBonusBalance(),
                $user->getWalletBalance(),
            ]
        );

        $entry = UserLedgerEntry::where('user_id', $user->id)
            ->where('type', UserLedgerEntry::TYPE_BONUS_INCOME)
            ->firstOrFail();

        $this->assertEquals(300, $entry->amount);
        $this->assertNotNull($entry->refLink);
        $this->assertEquals($refLink->id, $entry->refLink->id);
    }

    public function testCheck(): void
    {
        $this->app->bind(
            ExchangeRateRepository::class,
            function () {
                return new DummyExchangeRateRepository();
            }
        );

        $this->actingAs(factory(User::class)->create(), 'api');

        $response = $this->getJson(self::CHECK_URI);

        $response->assertStatus(Response::HTTP_OK)->assertJsonStructure(self::STRUCTURE_CHECK);
    }

    public function testCheckWithoutExchangeRate(): void
    {
        $repository = $this->createMock(ExchangeRateRepository::class);
        $repository->expects($this->once())->method('fetchExchangeRate')->willThrowException(
            new ExchangeRateNotAvailableException()
        );

        $this->app->bind(
            ExchangeRateRepository::class,
            function () use ($repository) {
                return $repository;
            }
        );

        $this->actingAs(factory(User::class)->create(), 'api');

        $response = $this->getJson(self::CHECK_URI);

        $structure = self::STRUCTURE_CHECK;
        unset($structure['exchangeRate']);

        $response->assertStatus(Response::HTTP_OK)->assertJsonStructure($structure);
    }

    public function testWalletLoginInit(): void
    {
        $response = $this->get(self::WALLET_LOGIN_INIT_URI);
        $response->assertStatus(Response::HTTP_OK)->assertJsonStructure([
            'message',
            'token',
            'gateways' => ['bsc']
        ]);
    }

    public function testWalletLoginAds(): void
    {
        $user = $this->walletRegisterUser();
        $this->assertAuthenticatedAs($user);
    }

    public function testWalletLoginWithReferral(): void
    {
        /** @var RefLink $refLink */
        $refLink = factory(RefLink::class)->create();
        $this->assertFalse($refLink->used);

        $user = $this->walletRegisterUser($refLink->token);
        $this->assertNotNull($user->refLink);
        $this->assertEquals($refLink->token, $user->refLink->token);
        $this->assertTrue($user->refLink->used);
    }

    public function testWalletLoginBsc(): void
    {
        $user = factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('bsc:0x79e51bA0407bEc3f1246797462EaF46850294301')
        ]);
        $message = '123abc';
        $token = Token::generate(Token::WALLET_LOGIN, null, [
            'request' => [],
            'message' => $message,
        ])->uuid;

        //message:123abc
        $sign = '0xe649d27a045e5a9397a9a7572d93471e58f6ab8d024063b2ea5b6bcb4f65b5eb4aecf499197f71af91f57cd712799d2a559e3a3a40243db2c4e947aeb0a2c8181b';
        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => $token,
            'network' => 'bsc',
            'address' => '0x79e51bA0407bEc3f1246797462EaF46850294301',
            'signature' => $sign
        ]);
        $response->assertStatus(Response::HTTP_OK);
        $this->assertAuthenticatedAs($user);
    }

    public function testNonExistedWalletLoginUser(): void
    {
        factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('ads:0001-00000002-BB2D')
        ]);
        $message = '123abc';
        $token = Token::generate(Token::WALLET_LOGIN, null, [
            'request' => [],
            'message' => $message,
        ])->uuid;

        //SK: CA978112CA1BBDCAFAC231B39A23DC4DA786EFF8147C4E72B9807785AFEE48BB
        //PK: EAE1C8793B5597C4B3F490E76AC31172C439690F8EE14142BB851A61F9A49F0E
        //message:123abc
        $sign = '0x72d877601db72b6d843f11d634447bbdd836de7adbd5b2dfc4fa718ea68e7b18d65547b1265fec0c121ac76dfb086806da393d244dec76d72f49895f48aa5a01';
        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => $token,
            'network' => 'ads',
            'address' => '0001-00000001-8B4E',
            'signature' => $sign
        ]);
        $response->assertStatus(Response::HTTP_OK);

        $user = User::fetchByWalletAddress(new WalletAddress(WalletAddress::NETWORK_ADS, '0001-00000001-8B4E'));
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);

        $this->assertFalse($user->is_email_confirmed);
        $this->assertTrue($user->is_admin_confirmed);
        $this->assertTrue($user->is_confirmed);
    }

    public function testNonExistedWalletLoginUserWithRestrictedMode(): void
    {
        Config::updateAdminSettings([Config::REGISTRATION_MODE => RegistrationMode::RESTRICTED]);

        factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('ads:0001-00000002-BB2D')
        ]);
        $message = '123abc';
        $token = Token::generate(Token::WALLET_LOGIN, null, [
            'request' => [],
            'message' => $message,
        ])->uuid;

        //SK: CA978112CA1BBDCAFAC231B39A23DC4DA786EFF8147C4E72B9807785AFEE48BB
        //PK: EAE1C8793B5597C4B3F490E76AC31172C439690F8EE14142BB851A61F9A49F0E
        //message:123abc
        $sign = '0x72d877601db72b6d843f11d634447bbdd836de7adbd5b2dfc4fa718ea68e7b18d65547b1265fec0c121ac76dfb086806da393d244dec76d72f49895f48aa5a01';
        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => $token,
            'network' => 'ads',
            'address' => '0001-00000001-8B4E',
            'signature' => $sign
        ]);
        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function testNonExistedWalletLoginUserWithPrivateMode(): void
    {
        Config::updateAdminSettings([Config::REGISTRATION_MODE => RegistrationMode::PRIVATE]);

        factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('ads:0001-00000002-BB2D')
        ]);
        $message = '123abc';
        $token = Token::generate(Token::WALLET_LOGIN, null, [
            'request' => [],
            'message' => $message,
        ])->uuid;

        //SK: CA978112CA1BBDCAFAC231B39A23DC4DA786EFF8147C4E72B9807785AFEE48BB
        //PK: EAE1C8793B5597C4B3F490E76AC31172C439690F8EE14142BB851A61F9A49F0E
        //message:123abc
        $sign = '0x72d877601db72b6d843f11d634447bbdd836de7adbd5b2dfc4fa718ea68e7b18d65547b1265fec0c121ac76dfb086806da393d244dec76d72f49895f48aa5a01';
        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => $token,
            'network' => 'ads',
            'address' => '0001-00000001-8B4E',
            'signature' => $sign
        ]);
        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function testInvalidWalletLoginSignature(): void
    {
        factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('ads:0001-00000001-8B4E')
        ]);
        $message = '123abc';
        $token = Token::generate(Token::WALLET_LOGIN, null, [
            'request' => [],
            'message' => $message,
        ])->uuid;

        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => $token,
            'network' => 'ads',
            'address' => '0001-00000001-8B4E',
            'signature' => '0x1231231231'
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testUnsupportedWalletLoginNetwork(): void
    {
        factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('btc:3ALP7JRzHAyrhX5LLPSxU1A9duDiGbnaKg')
        ]);
        $message = '123abc';
        $token = Token::generate(Token::WALLET_LOGIN, null, [
            'request' => [],
            'message' => $message,
        ])->uuid;

        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => $token,
            'network' => 'btc',
            'address' => '3ALP7JRzHAyrhX5LLPSxU1A9duDiGbnaKg',
            'signature' => '0x1231231231'
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testInvalidWalletLoginToken(): void
    {
        factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('ads:0001-00000001-8B4E')
        ]);
        $sign = '0x72d877601db72b6d843f11d634447bbdd836de7adbd5b2dfc4fa718ea68e7b18d65547b1265fec0c121ac76dfb086806da393d244dec76d72f49895f48aa5a01';
        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => 'foo_token',
            'network' => 'ads',
            'address' => '0001-00000001-8B4E',
            'signature' => $sign
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testNonExistedWalletLoginToken(): void
    {
        factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('ads:0001-00000001-8B4E')
        ]);
        $sign = '0x72d877601db72b6d843f11d634447bbdd836de7adbd5b2dfc4fa718ea68e7b18d65547b1265fec0c121ac76dfb086806da393d244dec76d72f49895f48aa5a01';
        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => '1231231231',
            'network' => 'ads',
            'address' => '0001-00000001-8B4E',
            'signature' => $sign
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testExpiredWalletLoginToken(): void
    {
        factory(User::class)->create([
            'wallet_address' => WalletAddress::fromString('ads:0001-00000001-8B4E')
        ]);
        $message = '123abc';
        $token = Token::generate(Token::WALLET_LOGIN, null, [
            'request' => [],
            'message' => $message,
        ]);
        $token->valid_until = '2020-01-01 12:00:00';
        $token->saveOrFail();

        $sign = '0x72d877601db72b6d843f11d634447bbdd836de7adbd5b2dfc4fa718ea68e7b18d65547b1265fec0c121ac76dfb086806da393d244dec76d72f49895f48aa5a01';
        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => $token->uuid,
            'network' => 'ads',
            'address' => '0001-00000001-8B4E',
            'signature' => $sign
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testLogInMissingEmail(): void
    {
        $response = $this->post(self::LOG_IN_URI, [
            'password' => '87654321',
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testLogInAndLogOut(): void
    {
        /** @var User $user */
        $user = factory(User::class)->create(['password' => '87654321']);

        $this->post(self::LOG_IN_URI, ['email' => $user->email, 'password' => '87654321'])
            ->assertStatus(Response::HTTP_OK);
        $apiToken = User::fetchById($user->id)->api_token;
        self::assertNotNull($apiToken, 'Token is null');

        $this->get(self::LOG_OUT_URI, ['Authorization' => 'Bearer ' . $apiToken])
            ->assertStatus(Response::HTTP_NO_CONTENT);
        self::assertNull(User::fetchById($user->id)->api_token, 'Token is not null');
    }

    public function testLogInBannedUser(): void
    {
        /** @var User $user */
        $user = factory(User::class)
            ->create(['password' => '87654321', 'is_banned' => true, 'ban_reason' => 'suspicious activity']);

        $response = $this->post(self::LOG_IN_URI, ['email' => $user->email, 'password' => '87654321']);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
        $content = json_decode($response->getContent(), true);
        self::assertArrayHasKey('reason', $content);
    }

    public function testSetPassword(): void
    {
        $user = $this->walletRegisterUser();
        $user->email = $this->faker->email();
        $user->email_confirmed_at = new DateTime();
        $user->save();
        $this->actingAs($user, 'api');

        $response = $this->patch(self::SELF_URI, [
            'user' => [
                'password_new' => 'qwerty123',
            ],
            'uri' => '/confirm',
        ]);
        $response->assertStatus(Response::HTTP_OK);
        Mail::assertQueued(UserPasswordChangeConfirm::class);
    }

    public function testSetPasswordWhileUserHasNoEmailSet(): void
    {
        $user = $this->walletRegisterUser();
        $this->actingAs($user, 'api');

        $response = $this->patch(self::SELF_URI, [
            'user' => [
                'password_new' => 'qwerty123',
            ],
            'uri' => '/confirm',
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testSetPasswordConfirm(): void
    {
        $user = $this->walletRegisterUser();
        $user->email = $this->faker->email();
        $user->email_confirmed_at = new DateTime();
        $user->save();
        $this->actingAs($user, 'api');
        $token = Token::generate(Token::PASSWORD_CHANGE, $user, ['password' => 'qwerty123']);

        $response = $this->post(self::buildConfirmPasswordUri($token->uuid));
        $response->assertStatus(Response::HTTP_OK);

        $user->refresh();
        self::assertEquals('qwerty123', $user->password);
    }

    public function testSetPasswordConfirmInvalidToken(): void
    {
        $user = $this->walletRegisterUser();
        $user->email = $this->faker->email();
        $user->email_confirmed_at = new DateTime();
        $user->save();
        $this->actingAs($user, 'api');

        $response = $this->post(self::buildConfirmPasswordUri('foo'));
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testSetPasswordConfirmInvalidEmail(): void
    {
        $user = $this->walletRegisterUser();
        $this->actingAs($user, 'api');
        $token = Token::generate(Token::PASSWORD_CHANGE, $user, ['password' => 'qwerty123']);

        $response = $this->post(self::buildConfirmPasswordUri($token->uuid));
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function buildConfirmPasswordUri(string $token): string
    {
        return self::PASSWORD_CONFIRM . '/' . $token;
    }

    public function testSetInvalidPassword(): void
    {
        $user = $this->walletRegisterUser();
        $this->actingAs($user, 'api');

        $response = $this->patch(self::SELF_URI, [
            'user' => [
                'password_new' => '123',
            ]
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testChangePassword(): void
    {
        /** @var User $user */
        $user = factory(User::class)->create([
            'api_token' => '1234',
            'password' => '87654321',
        ]);
        $this->actingAs($user, 'api');
        self::assertNotNull($user->api_token, 'Token is null');

        $response = $this->patch(
            self::SELF_URI,
            [
                'user' => [
                    'password_old' => '87654321',
                    'password_new' => 'qwerty123',
                ]
            ]
        );
        $response->assertStatus(Response::HTTP_OK);
        self::assertNull($user->api_token, 'Token is not null');
        Mail::assertQueued(UserPasswordChange::class);
    }

    public function testChangeInvalidOldPassword(): void
    {
        $user = $this->registerUser();
        $this->actingAs($user, 'api');

        $response = $this->patch(self::SELF_URI, [
            'user' => [
                'password_old' => 'foopass123',
                'password_new' => 'qwerty123',
            ]
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testChangeInvalidNewPassword(): void
    {
        $user = $this->registerUser();
        $this->actingAs($user, 'api');

        $response = $this->patch(self::SELF_URI, [
            'user' => [
                'password_old' => '87654321',
                'password_new' => 'foo',
            ]
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testChangePasswordNoPassword(): void
    {
        $this->actingAs(factory(User::class)->create(), 'api');

        $response = $this->patch(self::SELF_URI, [
            'user' => [
                'email' => $this->faker->email(),
            ]
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testChangePasswordNoUser(): void
    {
        /** @var User $user */
        $user = factory(User::class)->create();
        $token = Token::generate(Token::PASSWORD_RECOVERY, $user);

        $response = $this->patch(self::PASSWORD_URI, [
            'user' => [
                'password_new' => '1234567890',
                'token' => $token->uuid,
            ]
        ]);
        $response->assertStatus(Response::HTTP_OK);
        self::assertNotEquals($user->password, User::fetchById($user->id)->password);
    }

    public function testChangePasswordNoToken(): void
    {
        $response = $this->patch(self::PASSWORD_URI, [
            'user' => [
                'password_new' => '1234567890',
                'token' => '0123456789ABCDEF0123456789ABCDEF',
            ]
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testChangePasswordUnauthorized(): void
    {
        $response = $this->patch(self::PASSWORD_URI, [
            'user' => [
                'password_new' => 'qwerty123',
            ]
        ]);
        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function testSetEmail(): void
    {
        Config::updateAdminSettings([Config::EMAIL_VERIFICATION_REQUIRED => '0']);

        $user = $this->walletRegisterUser();
        $this->actingAs($user, 'api');

        $response = $this->post(self::EMAIL_URI, [
            'email' => $this->faker->unique()->email,
            'uri_step1' => '/auth/email-activation/',
            'uri_step2' => '/auth/email-activation/'
        ]);
        $response->assertStatus(Response::HTTP_NO_CONTENT);
    }

    public function testSetEmailStep1(): void
    {
        Config::updateAdminSettings([Config::EMAIL_VERIFICATION_REQUIRED => '1']);

        $user = $this->walletRegisterUser();
        $this->actingAs($user, 'api');

        $response = $this->post(self::EMAIL_URI, [
            'email' => $this->faker->unique()->email,
            'uri_step1' => '/auth/email-activation/',
            'uri_step2' => '/auth/email-activation/'
        ]);
        $response->assertStatus(Response::HTTP_NO_CONTENT);
        Mail::assertQueued(UserEmailActivate::class);
    }

    public function testSetInvalidEmail(): void
    {
        $user = $this->walletRegisterUser();
        $this->actingAs($user, 'api');

        $response = $this->post(self::EMAIL_URI, [
            'email' => 'foo',
            'uri_step1' => '/auth/email-activation/',
            'uri_step2' => '/auth/email-activation/'
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testChangeEmail(): void
    {
        Config::updateAdminSettings([Config::EMAIL_VERIFICATION_REQUIRED => '0']);

        $user = $this->registerUser();
        $this->actingAs($user, 'api');

        $response = $this->post(self::EMAIL_URI, [
            'email' => $this->faker->unique()->email,
            'uri_step1' => '/auth/email-activation/',
            'uri_step2' => '/auth/email-activation/'
        ]);
        $response->assertStatus(Response::HTTP_NO_CONTENT);
        Mail::assertQueued(UserEmailChangeConfirm1Old::class);
    }

    public function testChangeInvalidEmail(): void
    {
        $user = $this->registerUser();
        $this->actingAs($user, 'api');

        $response = $this->post(self::EMAIL_URI, [
            'email' => 'foo',
            'uri_step1' => '/auth/email-activation/',
            'uri_step2' => '/auth/email-activation/'
        ]);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testEmailActivationNoToken(): void
    {
        $response = $this->postJson(
            self::EMAIL_ACTIVATE_URI,
            [
                'user' => [
                    'emailConfirmToken' => '00',
                ],
            ]
        );
        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function testRegisterDeletedUser(): void
    {
        /** @var User $user */
        $user = factory(User::class)->create(['deleted_at' => new DateTime()]);

        $response = $this->postJson(
            self::REGISTER_USER,
            [
                'user' => [
                    'email' => $user->email,
                    'password' => '87654321',
                ],
                'uri' => '/auth/email-activation/',
            ]
        );

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRegisterSwash(): void
    {

        // Non Exists
        /** @var User $user */
        $rnd = User::generateRandomETHWalletForSwash();

        $response = $this->postJson(
            '/auth/swash-register',
            [
                'address' => $rnd,
            ]
        );
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonStructure([
            'sAdId',
            'zones' => [ 0 => ["name", "width", "height", "uuid"]]
        ]);
        $sadId = $response->json()['sAdId'];

        $u = User::fetchBySwashWalletAddress($rnd);
        $this->assertEquals($sadId, $u->wallet_address);
        // Exists
        /** @var User $user */
        
        $response = $this->postJson(
            '/auth/swash-register',
            [
                'address' => $rnd,
            ]
        );

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonStructure([
            'sAdId'
        ]);
        
        $this->assertEquals($sadId, $response->json()['sAdId']);
    }

    private function registerUser(?string $referralToken = null, int $status = Response::HTTP_CREATED): ?User
    {
        $email = $this->faker->unique()->email;
        $response = $this->postJson(
            self::REGISTER_USER,
            [
                'user' => [
                    'email' => $email,
                    'password' => '87654321',
                    'referral_token' => $referralToken,
                ],
                'uri' => '/auth/email-activation/',
            ]
        );
        $response->assertStatus($status);

        return User::where('email', $email)->first();
    }

    private function walletRegisterUser(?string $referralToken = null, int $status = Response::HTTP_OK): ?User
    {
        $message = '123abc';
        $token = Token::generate(Token::WALLET_LOGIN, null, [
            'request' => [],
            'message' => $message,
        ])->uuid;

        //SK: CA978112CA1BBDCAFAC231B39A23DC4DA786EFF8147C4E72B9807785AFEE48BB
        //PK: EAE1C8793B5597C4B3F490E76AC31172C439690F8EE14142BB851A61F9A49F0E
        //message:123abc
        $sign = '0x72d877601db72b6d843f11d634447bbdd836de7adbd5b2dfc4fa718ea68e7b18d65547b1265fec0c121ac76dfb086806da393d244dec76d72f49895f48aa5a01';
        $response = $this->post(self::WALLET_LOGIN_URI, [
            'token' => $token,
            'network' => 'ads',
            'address' => '0001-00000001-8B4E',
            'signature' => $sign,
            'referral_token' => $referralToken
        ]);
        $response->assertStatus($status);

        return User::fetchByWalletAddress(new WalletAddress(WalletAddress::NETWORK_ADS, '0001-00000001-8B4E'));
    }

    private function activateUser(User $user): void
    {
        $activationToken = Token::where('user_id', $user->id)->where('tag', Token::EMAIL_ACTIVATE)->firstOrFail();

        $response = $this->postJson(
            self::EMAIL_ACTIVATE_URI,
            [
                'user' => [
                    'emailConfirmToken' => $activationToken->uuid,
                ],
            ]
        );
        $response->assertStatus(Response::HTTP_OK);
        $user->refresh();
    }

    private function confirmUser(User $user): void
    {
        $response = $this->postJson('/admin/users/' . $user->id . '/confirm');
        $response->assertStatus(Response::HTTP_OK);
        $user->refresh();
    }
}
