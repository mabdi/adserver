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

namespace Adshares\Adserver\Models;

use Adshares\Adserver\Events\GenerateUUID;
use Adshares\Adserver\Events\UserCreated;
use Adshares\Adserver\Models\Traits\AddressWithNetwork;
use Adshares\Adserver\Models\Traits\AutomateMutators;
use Adshares\Adserver\Models\Traits\BinHex;
use Adshares\Adserver\Utilities\DomainReader;
use Adshares\Common\Domain\ValueObject\WalletAddress;
use DateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @property Collection|Campaign[] campaigns
 * @property int id
 * @property string email
 * @property string label
 * @property Carbon|null created_at
 * @property DateTime|null email_confirmed_at
 * @property DateTime|null admin_confirmed_at
 * @property string uuid
 * @property int|null ref_link_id
 * @property RefLink|null refLink
 * @property string|null name
 * @property string|null password
 * @property string|null api_token
 * @property int subscribe
 * @property bool is_email_confirmed
 * @property bool is_admin_confirmed
 * @property bool is_confirmed
 * @property bool is_admin
 * @property bool is_moderator
 * @property bool is_agency
 * @property bool is_advertiser
 * @property bool is_publisher
 * @property WalletAddress|null wallet_address
 * @property int|null auto_withdrawal
 * @property bool is_auto_withdrawal
 * @property int auto_withdrawal_limit
 * @property bool is_banned
 * @property string ban_reason
 * @mixin Builder
 */
class User extends Authenticatable
{
    use Notifiable;
    use SoftDeletes;

    use AutomateMutators;
    use BinHex;
    use AddressWithNetwork;

    public static $rules_add = [
        'email' => 'required|email|max:150|unique:users',
        'password' => 'required|min:8',
        'is_advertiser' => 'boolean',
        'is_publisher' => 'boolean',
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'deleted_at',
        'email_confirmed_at',
        'admin_confirmed_at',
    ];

    /**
     * The event map for the model.
     *
     * @var array
     */
    protected $dispatchesEvents = [
        'creating' => GenerateUUID::class,
        'created' => UserCreated::class,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'wallet_address',
        'auto_withdrawal',
        'is_advertiser',
        'is_publisher',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $visible = [
        'id',
        'uuid',
        'email',
        'name',
        'has_password',
        'is_advertiser',
        'is_publisher',
        'is_admin',
        'is_moderator',
        'is_agency',
        'api_token',
        'is_email_confirmed',
        'is_admin_confirmed',
        'is_confirmed',
        'is_subscribed',
        'adserver_wallet',
        'is_banned',
        'ban_reason',
    ];

    protected $traitAutomate = [
        'uuid' => 'BinHex',
        'wallet_address' => 'AddressWithNetwork'
    ];

    protected $appends = [
        'has_password',
        'adserver_wallet',
        'is_email_confirmed',
        'is_admin_confirmed',
        'is_confirmed',
        'is_subscribed',
    ];

    protected function toArrayExtras($array)
    {
        if (null === $array['email']) {
            $array['email'] = (string)$array['wallet_address'];
        }
        unset($array['wallet_address']);
        return $array;
    }

    public function getLabelAttribute(): string
    {
        return '#' . $this->id . (null !== $this->email ? ' (' . $this->email . ')' : '');
    }

    public function getHasPasswordAttribute(): bool
    {
        return null !== $this->password;
    }

    public function getIsEmailConfirmedAttribute(): bool
    {
        return null !== $this->email_confirmed_at;
    }

    public function getIsAdminConfirmedAttribute(): bool
    {
        return null !== $this->admin_confirmed_at;
    }

    public function getIsConfirmedAttribute(): bool
    {
        return (null === $this->email || $this->is_email_confirmed) && $this->is_admin_confirmed;
    }

    public function getIsSubscribedAttribute(): bool
    {
        return 0 !== $this->subscribe;
    }

    public function getAdserverWalletAttribute(): array
    {
        return [
            'total_funds' => $this->getBalance(),
            'wallet_balance' => $this->getWalletBalance(),
            'bonus_balance' => $this->getBonusBalance(),
            'total_funds_in_currency' => 0,
            'total_funds_change' => 0,
            'last_payment_at' => 0,
            'wallet_address' => optional($this->wallet_address)->getAddress(),
            'wallet_network' => optional($this->wallet_address)->getNetwork(),
            'is_auto_withdrawal' => $this->is_auto_withdrawal,
            'auto_withdrawal_limit' => $this->auto_withdrawal_limit,
        ];
    }

    public function getIsAutoWithdrawalAttribute(): bool
    {
        return null !== $this->auto_withdrawal;
    }

    public function getAutoWithdrawalLimitAttribute(): int
    {
        return (int)$this->auto_withdrawal;
    }

    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = null !== $value ? Hash::make($value) : null;
    }

    public function setHashedPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = $value;
    }

    public function validPassword($value): bool
    {
        return Hash::check($value, $this->attributes['password']);
    }

    public function generateApiKey(): void
    {
        if ($this->api_token) {
            return;
        }

        do {
            $this->api_token = Str::random(60);
        } while ($this->where('api_token', $this->api_token)->exists());

        $this->save();
    }

    public function clearApiKey(): void
    {
        $this->api_token = null;
        $this->save();
    }

    public function maskEmailAndWalletAddress(): void
    {
        $this->email = sprintf('%s@%s', $this->uuid, DomainReader::domain(config('app.url')));
        $this->email_confirmed_at = null;
        $this->wallet_address = null;
        $this->save();
    }

    public function ban(string $reason): void
    {
        $this->is_banned = true;
        $this->ban_reason = $reason;
        $this->api_token = null;
        $this->auto_withdrawal = null;
        $this->save();
    }

    public function unban(): void
    {
        $this->is_banned = false;
        $this->save();
    }

    public static function fetchById(int $id): ?self
    {
        return self::find($id);
    }

    public static function fetchByIds(array $ids): Collection
    {
        return self::whereIn('id', $ids)->get()->keyBy('id');
    }

    public static function fetchByUuid(string $uuid): ?self
    {
        return self::where('uuid', hex2bin($uuid))->first();
    }

    public static function fetchByEmail(string $email): ?self
    {
        return self::where('email', $email)->first();
    }

    public static function fetchByWalletAddress(WalletAddress $address): ?self
    {
        return self::where('wallet_address', $address)->first();
    }

    public static function fetchBySwashWalletAddress(string $address): ?self
    {
        return self::where('swash_wallet_address', $address)->first();
    }

    public static function findByAutoWithdrawal(): Collection
    {
        return self::whereNotNull('auto_withdrawal')->get();
    }

    public function isAdvertiser(): bool
    {
        return (bool)$this->is_advertiser;
    }

    public function isPublisher(): bool
    {
        return (bool)$this->is_publisher;
    }

    public function isAdmin(): bool
    {
        return (bool)$this->is_admin;
    }

    public function isModerator(): bool
    {
        return (bool)$this->is_moderator || (bool)$this->is_admin;
    }

    public function isAgency(): bool
    {
        return (bool)$this->is_agency;
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function getBalance(): int
    {
        return UserLedgerEntry::getBalanceByUserId($this->id);
    }

    public function getWalletBalance(): int
    {
        return UserLedgerEntry::getWalletBalanceByUserId($this->id);
    }

    public function getBonusBalance(): int
    {
        return UserLedgerEntry::getBonusBalanceByUserId($this->id);
    }

    public function getRefundBalance(): int
    {
        return $this->getBonusBalance();
    }

    public function refLink(): BelongsTo
    {
        return $this->belongsTo(RefLink::class);
    }

    public function getReferrals(): Collection
    {
        return self::has('refLink')->get();
    }

    public function getReferralIds(): array
    {
        return $this->getReferrals()->pluck('id')->toArray();
    }

    public function getReferralUuids(): array
    {
        return $this->getReferrals()->pluck('uuid')->toArray();
    }

    public static function registerWithEmail(string $email, string $password, ?RefLink $refLink = null): User
    {
        return self::register([
            'email' => $email,
            'password' => $password,
            'is_advertiser' => true,
            'is_publisher' => true,
        ], $refLink);
    }

    protected static function register(array $data, ?RefLink $refLink = null): User
    {
        $user = User::create($data);
        $user->password = $data['password'] ?? null;
        if (null !== $refLink) {
            $user->ref_link_id = $refLink->id;
            $refLink->used = true;
            $refLink->saveOrFail();
        }
        $user->saveOrFail();
        return $user;
    }

    public static function registerWithWallet(
        WalletAddress $address,
        bool $autoWithdrawal = false,
        ?RefLink $refLink = null
    ): User {
        return self::register([
            'wallet_address' => $address,
            'auto_withdrawal' => $autoWithdrawal
                ? config('app.auto_withdrawal_limit_' . strtolower($address->getNetwork()))
                : null,
            'is_advertiser' => true,
            'is_publisher' => true,
        ], $refLink);
    }

    public static function registerAdmin(string $email, string $name, string $password): User
    {
        $user = self::register([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $user->is_admin = true;
        $user->confirmEmail();
        $user->saveOrFail();
        return $user;
    }

    public function awardBonus(int $amount, ?RefLink $refLink = null): void
    {
        UserLedgerEntry::insertUserBonus($this->id, $amount, $refLink);
    }

    public function confirmEmail(): void
    {
        $this->email_confirmed_at = new DateTime();
        $this->subscription(true);
    }

    public function confirmAdmin(): void
    {
        $this->admin_confirmed_at = new DateTime();
    }

    public function subscription(bool $subscribe): void
    {
        $this->subscribe = $subscribe ? 1 : 0;
    }

    public static function fetchEmails(): Collection
    {
        return self::where('subscribe', 1)->whereNotNull('email')->get()->pluck('email');
    }

    public static function generateRandomETHWalletForSwash(): string {
        // An eth address contains 40 hexadecimals. I use 40 random hex chars.
        // At the moment I don't use the db, but in the future we may use users count, ...

        return '0x' . substr( hash('sha256', strval(rand(1,1000000) * microtime(true)) ), 0, 40); 
    }
}
