<?php

/**
 * Copyright (c) 2018-2021 Adshares sp. z o.o.
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

namespace Adshares\Adserver\Tests\Models;

use Adshares\Adserver\Models\User;
use Adshares\Adserver\Tests\TestCase;
use Adshares\Common\Domain\ValueObject\WalletAddress;

class UserTest extends TestCase
{
    public function testFetchByWalletAddress(): void
    {
        /** @var User $user */
        $user = factory(User::class)->create();
        $user->wallet_address = new WalletAddress(WalletAddress::NETWORK_ADS, '0001-00000001-8B4E');
        $user->saveOrFail();

        $dbUser = User::fetchByWalletAddress(WalletAddress::fromString('ADS:0001-00000001-8B4E'));
        $this->assertNotNull($dbUser);
        $this->assertEquals($user->id, $dbUser->id);
    }

    public function testRegisterWithEmail(): void
    {
        $user = User::registerWithEmail('test@test.pl', '123123');
        $this->assertNotNull($user->uuid);
        $this->assertNull($user->wallet_address);
        $this->assertNull($user->auto_withdrawal);
        $this->assertEquals('test@test.pl', $user->email);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->isPublisher());
        $this->assertTrue($user->isAdvertiser());
        $this->assertNotNull($user->password);
    }

    public function testRegisterWithWallet(): void
    {
        $address = new WalletAddress(WalletAddress::NETWORK_ADS, '0001-00000001-8B4E');
        $user = User::registerWithWallet($address);

        $this->assertNotNull($user->uuid);
        $this->assertNull($user->email);
        $this->assertEquals($address, $user->wallet_address);
        $this->assertNull($user->auto_withdrawal);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->isPublisher());
        $this->assertTrue($user->isAdvertiser());
        $this->assertNull($user->password);

        $address = new WalletAddress(WalletAddress::NETWORK_ADS, '0001-00000002-BB2D');
        $user = User::registerWithWallet($address, true);

        $this->assertNotNull($user->uuid);
        $this->assertNull($user->email);
        $this->assertEquals($address, $user->wallet_address);
        $this->assertNotNull($user->auto_withdrawal);
        $this->assertEquals(100000000, $user->auto_withdrawal);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->isPublisher());
        $this->assertTrue($user->isAdvertiser());
        $this->assertNull($user->password);
    }

    public function testRegisteradmin(): void
    {
        $user = User::registerAdmin('test@test.pl', 'admin2', '123123');
        $this->assertNotNull($user->uuid);
        $this->assertNull($user->wallet_address);
        $this->assertNull($user->auto_withdrawal);
        $this->assertEquals('test@test.pl', $user->email);
        $this->assertEquals('admin2', $user->name);
        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isPublisher());
        $this->assertFalse($user->isAdvertiser());
        $this->assertNotNull($user->password);
    }

    public function testAutoWithdrawal(): void
    {
        $user = new User();

        $this->assertNull($user->auto_withdrawal);
        $this->assertEquals(0, $user->auto_withdrawal_limit);
        $this->assertFalse($user->is_auto_withdrawal);

        $user->auto_withdrawal = 100;

        $this->assertEquals(100, $user->auto_withdrawal);
        $this->assertEquals(100, $user->auto_withdrawal_limit);
        $this->assertTrue($user->is_auto_withdrawal);
    }

    public function testGenerateRandomETHWalletForSwash(): void
    {
        $rnd = User::generateRandomETHWalletForSwash();
        $rnd2 = User::generateRandomETHWalletForSwash();
        $this->assertEquals(42, strlen($rnd));
        $this->assertNotEquals($rnd, $rnd2);
    }
}
