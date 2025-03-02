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

namespace Adshares\Adserver\Http\Controllers\Manager;

use Adshares\Adserver\Http\Controller;
use Adshares\Adserver\Mail\AuthRecovery;
use Adshares\Adserver\Mail\Crm\UserRegistered;
use Adshares\Adserver\Mail\UserConfirmed;
use Adshares\Adserver\Mail\UserEmailActivate;
use Adshares\Adserver\Mail\UserEmailChangeConfirm1Old;
use Adshares\Adserver\Mail\UserEmailChangeConfirm2New;
use Adshares\Adserver\Mail\UserPasswordChange;
use Adshares\Adserver\Mail\UserPasswordChangeConfirm;
use Adshares\Adserver\Models\Config;
use Adshares\Adserver\Models\RefLink;
use Adshares\Adserver\Models\Token;
use Adshares\Adserver\Models\User;
use Adshares\Adserver\Models\Site;
use Adshares\Adserver\Models\Zone;
use Adshares\Common\Application\Service\AdsRpcClient;
use Adshares\Common\Application\Service\Exception\ExchangeRateNotAvailableException;
use Adshares\Common\Domain\ValueObject\SecureUrl;
use Adshares\Common\Domain\ValueObject\WalletAddress;
use Adshares\Common\Exception\InvalidArgumentException;
use Adshares\Common\Infrastructure\Service\ExchangeRateReader;
use Adshares\Config\RegistrationMode;
use DateTime;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AuthController extends Controller
{
    private const ERROR_MESSAGE_INVALID_TOKEN = 'Invalid or outdated token';

    private ExchangeRateReader $exchangeRateReader;

    public function __construct(ExchangeRateReader $exchangeRateReader)
    {
        $this->exchangeRateReader = $exchangeRateReader;
    }

    private function checkRegisterMode(?string $referralToken = null): ?RefLink
    {
        $registrationMode = Config::fetchStringOrFail(Config::REGISTRATION_MODE);
        if (RegistrationMode::PRIVATE === $registrationMode) {
            throw new AccessDeniedHttpException('Private registration enabled');
        }

        $refLink = null;
        if (null !== $referralToken) {
            $refLink = RefLink::fetchByToken($referralToken);
        }

        if (RegistrationMode::RESTRICTED === $registrationMode && null === $refLink) {
            throw new AccessDeniedHttpException('Restricted registration enabled');
        }

        return $refLink;
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->input('user');
        $refLink = $this->checkRegisterMode($data['referral_token'] ?? null);

        $this->validateRequestObject($request, 'user', User::$rules_add);
        Validator::make($request->all(), ['uri' => 'required'])->validate();

        DB::beginTransaction();
        $user = User::registerWithEmail($data['email'], $data['password'], $refLink);
        if (Config::isTrueOnly(Config::EMAIL_VERIFICATION_REQUIRED)) {
            $token = Token::generate(Token::EMAIL_ACTIVATE, $user);
            $mailable = new UserEmailActivate($token->uuid, $request->input('uri'));
            Mail::to($user)->queue($mailable);
        } else {
            $this->confirmEmail($user);
            if (Config::isTrueOnly(Config::AUTO_CONFIRMATION_ENABLED)) {
                $this->confirmAdmin($user);
            }
            $user->saveOrFail();
        }
        DB::commit();

        return self::json([], Response::HTTP_CREATED);
    }

    public function emailActivate(Request $request): JsonResponse
    {
        Validator::make($request->all(), ['user.email_confirm_token' => 'required'])->validate();

        DB::beginTransaction();
        $token = Token::check($request->input('user.email_confirm_token'), null, Token::EMAIL_ACTIVATE);
        if (false === $token) {
            DB::rollBack();
            return self::json([], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = User::find($token['user_id']);
        if (empty($user)) {
            DB::rollBack();
            return self::json([], Response::HTTP_FORBIDDEN);
        }

        $this->confirmEmail($user);
        if (Config::isTrueOnly(Config::AUTO_CONFIRMATION_ENABLED)) {
            $this->confirmAdmin($user);
        }
        $user->save();
        DB::commit();

        $this->sendCrmMailOnUserRegistered($user);

        return self::json($user->toArray());
    }

    public function confirm(int $userId): JsonResponse
    {
        /** @var User $user */
        $user = User::find($userId);
        if (empty($user)) {
            return self::json([], Response::HTTP_NOT_FOUND);
        }

        DB::beginTransaction();
        $this->confirmAdmin($user);
        $user->save();
        DB::commit();

        if ($user->is_confirmed && null !== $user->email) {
            Mail::to($user)->queue(new UserConfirmed());
        }

        return self::json($user->toArray());
    }

    public function emailActivateResend(Request $request): JsonResponse
    {
        Validator::make($request->all(), ['uri' => 'required'])->validate();

        /** @var User $user */
        $user = Auth::user();

        DB::beginTransaction();

        if (!Token::canGenerateToken($user, Token::EMAIL_ACTIVATE)) {
            return self::json(
                [],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'message' => 'You can request to resend email activation every 5 minutes.'
                        . ' Please wait 5 minutes or less.',
                ]
            );
        }

        $token = Token::generate(Token::EMAIL_ACTIVATE, $user);
        $mailable = new UserEmailActivate($token->uuid, $request->input('uri'));

        Mail::to($user)->queue($mailable);

        DB::commit();

        return self::json([], Response::HTTP_NO_CONTENT);
    }

    public function emailChangeStep1(Request $request): JsonResponse
    {
        Validator::make(
            $request->all(),
            ['email' => 'required|email', 'uri_step1' => 'required', 'uri_step2' => 'required']
        )->validate();
        if (User::withTrashed()->where('email', $request->input('email'))->count()) {
            return self::json(
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['email' => 'This email already exists in our database']
            );
        }

        /** @var User $user */
        $user = Auth::user();
        $userHasEmail = null !== $user->email && null !== $user->email_confirmed_at;

        DB::beginTransaction();
        if (!$userHasEmail) {
            $user->email = $request->input('email');
            $user->saveOrFail();
        }

        $tag = $userHasEmail ? Token::EMAIL_CHANGE_STEP_1 : Token::EMAIL_ACTIVATE;
        if (!Token::canGenerateToken($user, $tag)) {
            return self::json(
                [],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'message' => "You have already requested email change.\n"
                        . "You can request email change every 5 minutes.\n"
                        . 'Please wait 5 minutes or less to start configuring another email address.',
                ]
            );
        }
        $token = Token::generate($tag, $user, $request->only('email', 'uri_step1', 'uri_step2', 'uri'));
        $mailable = $userHasEmail ?
            new UserEmailChangeConfirm1Old($token->uuid, $request->input('uri_step1')) :
            new UserEmailActivate($token->uuid, $request->input('uri'));

        Mail::to($user)->queue($mailable);
        DB::commit();

        return self::json([], Response::HTTP_NO_CONTENT);
    }

    public function emailChangeStep2($token): JsonResponse
    {
        DB::beginTransaction();
        if (false === $token = Token::check($token, null, Token::EMAIL_CHANGE_STEP_1)) {
            DB::commit();

            return self::json([], Response::HTTP_FORBIDDEN, ['message' => self::ERROR_MESSAGE_INVALID_TOKEN]);
        }
        $user = User::findOrFail($token['user_id']);
        if (User::withTrashed()->where('email', $token['payload']['email'])->count()) {
            DB::commit();

            return self::json(
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['message' => 'This email already exists in our database']
            );
        }

        $token2 = Token::generate(Token::EMAIL_CHANGE_STEP_2, $user, $token['payload']);
        $mailable = new UserEmailChangeConfirm2New($token2->uuid, $token['payload']['uri_step2']);

        Mail::to($token['payload']['email'])->queue($mailable);

        DB::commit();

        return self::json([], Response::HTTP_NO_CONTENT);
    }

    public function emailChangeStep3($token): JsonResponse
    {
        DB::beginTransaction();
        if (false === $token = Token::check($token, null, Token::EMAIL_CHANGE_STEP_2)) {
            DB::commit();

            return self::json([], Response::HTTP_FORBIDDEN, ['message' => self::ERROR_MESSAGE_INVALID_TOKEN]);
        }
        $user = User::findOrFail($token['user_id']);
        if (User::withTrashed()->where('email', $token['payload']['email'])->count()) {
            DB::commit();

            return self::json(
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['message' => 'This email already exists in our database']
            );
        }
        $user->email = $token['payload']['email'];
        $user->email_confirmed_at = date('Y-m-d H:i:s');
        $user->save();
        DB::commit();

        return self::json($user->toArray());
    }

    public function check(int $code = Response::HTTP_OK): JsonResponse
    {
        try {
            $exchangeRate = $this->exchangeRateReader->fetchExchangeRate()->toArray();
        } catch (ExchangeRateNotAvailableException $exception) {
            Log::error(sprintf('[AuthController] Cannot fetch exchange rate: %s', $exception->getMessage()));
            $exchangeRate = null;
        }

        /** @var User $user */
        $user = Auth::user();

        return self::json(
            array_merge(
                $user->toArray(),
                [
                    'exchange_rate' => $exchangeRate,
                    'referral_refund_enabled' => Config::isTrueOnly(Config::REFERRAL_REFUND_ENABLED),
                    'referral_refund_commission' => Config::fetchFloatOrFail(Config::REFERRAL_REFUND_COMMISSION),
                ]
            ),
            $code
        );
    }

    public function impersonate(User $user): JsonResponse
    {
        /** @var User $logged */
        $logged = Auth::user();
        if ($logged->id === $user->id || $user->isModerator()) {
            return response()->json([], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($logged->isAgency() && !in_array($user->id, $logged->getReferralIds())) {
            return response()->json([], Response::HTTP_FORBIDDEN);
        }
        $token = Token::impersonate($logged, $user);
        return self::json($token->uuid);
    }

    public function login(Request $request): JsonResponse
    {
        if (
            Auth::guard()->attempt(
                $request->only('email', 'password'),
                $request->filled('remember')
            )
        ) {
            /** @var User $user */
            $user = Auth::user();
            if ($user->is_banned) {
                return new JsonResponse(['reason' => $user->ban_reason], Response::HTTP_FORBIDDEN);
            }
            $user->generateApiKey();
            return $this->check();
        }

        return response()->json([], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function walletLoginInit(Request $request, AdsRpcClient $rpcClient): JsonResponse
    {
        $message = <<<MSG
Log in to %s adserver.

I agree to the Terms of Service:
%s

and Privacy Policy:
%s

Date: %s
MSG;

        $message = sprintf(
            $message,
            config('app.name'),
            new SecureUrl((string)config('app.terms_url')),
            new SecureUrl((string)config('app.privacy_url')),
            date(DateTimeInterface::RFC2822)
        );

        $payload = [
            'request' => $request->all(),
            'message' => $message,
        ];

        return self::json([
            'token' => Token::generate(Token::WALLET_LOGIN, null, $payload)->uuid,
            'message' => $message,
            'gateways' => [
                'bsc' => $rpcClient->getGateway(WalletAddress::NETWORK_BSC)->toArray()
            ],
        ]);
    }

    public function walletLogin(Request $request): JsonResponse
    {
        try {
            $address = new WalletAddress($request->input('network'), $request->input('address'));
        } catch (InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException('Invalid wallet address');
        }

        if (null === User::fetchByWalletAddress($address)) {
            DB::beginTransaction();
            $refLink = $this->checkRegisterMode($request->input('referral_token') ?? null);
            $user = User::registerWithWallet($address, false, $refLink);
            if (Config::isTrueOnly(Config::AUTO_CONFIRMATION_ENABLED)) {
                $this->confirmAdmin($user);
            }
            $user->saveOrFail();
            DB::commit();
        }

        $credentials = $request->only('token', 'signature');
        $credentials['wallet_address'] = $address;
        if (Auth::guard()->attempt($credentials, $request->filled('remember'))) {
            Auth::user()->generateApiKey();
            return $this->check();
        }

        return response()->json([], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function swashRegister(Request $request): JsonResponse
    {
        try {
            new WalletAddress('eth', $request->input('address'));
        } catch (InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException('Invalid wallet address');
        }
        $user = User::fetchBySwashWalletAddress($request->input('address'));
        
        if (null === $user) {
            DB::beginTransaction();
            $address = new WalletAddress('eth', User::generateRandomETHWalletForSwash());

            $user = User::registerWithWallet($address, false);
            $user->swash_wallet_address = $request->input('address');
            if (Config::isTrueOnly(Config::AUTO_CONFIRMATION_ENABLED)) {
                $this->confirmAdmin($user);
            }
            $user->saveOrFail();
            DB::commit();
        }
        $site = Site::fetchOrCreate(
            $user->id,
            config('app.default_site_js'),
            'website',
            null
        );
        $ad_zones = array();
        foreach (config('app.swash_default_zones') as $zone_info) {
            $zoneObject = Zone::fetchOrCreate(
                $site->id,
                "{$zone_info['width']}x{$zone_info['height']}",
                $zone_info['name']
            );
            $ad_zones[] = array(
                'name' => $zone_info['name'],
                'width' => $zone_info['width'],
                'height' => $zone_info['height'],
                'uuid' => $zoneObject->uuid,
            );
        }

        return response()->json(['sAdId' => $user->wallet_address->toString(), 'zones' => $ad_zones]);
    }

    public function logout(): JsonResponse
    {
        Auth::user()->clearApiKey();
        return self::json([], Response::HTTP_NO_CONTENT);
    }

    public function recovery(Request $request): JsonResponse
    {
        Validator::make($request->all(), ['email' => 'required|email', 'uri' => 'required'])->validate();

        $user = User::where('email', $request->input('email'))->first();

        if (empty($user)) {
            return self::json([], Response::HTTP_NO_CONTENT);
        }

        DB::beginTransaction();

        if (!Token::canGenerateToken($user, Token::PASSWORD_RECOVERY)) {
            return self::json([], Response::HTTP_NO_CONTENT);
        }

        $mailable = new AuthRecovery(
            Token::generate(Token::PASSWORD_RECOVERY, $user)->uuid,
            $request->input('uri')
        );

        Mail::to($user)->queue($mailable);

        DB::commit();

        return self::json([], Response::HTTP_NO_CONTENT);
    }

    public function recoveryTokenExtend($token): JsonResponse
    {
        if (!Token::extend(Token::PASSWORD_RECOVERY, $token)) {
            return self::json(
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['message' => 'Password recovery token is invalid']
            );
        }

        return self::json([], Response::HTTP_NO_CONTENT);
    }

    public function changePassword(Request $request): JsonResponse
    {
        if (!Auth::check() && !$request->has('user.token')) {
            throw new UnauthorizedHttpException('', 'Required authenticated access or token authentication');
        }

        $this->validateRequestObject($request, 'user', ['password_new' => 'required|min:8']);

        DB::beginTransaction();
        if (Auth::check()) {
            $user = Auth::user();
            $tokenAuthorization = false;
        } else {
            if (false === $token = Token::check($request->input('user.token'), null, Token::PASSWORD_RECOVERY)) {
                DB::rollBack();
                throw new UnprocessableEntityHttpException('Authentication token is invalid');
            }
            $user = User::findOrFail($token['user_id']);
            $tokenAuthorization = true;
        }

        if (!$tokenAuthorization && null === $user->password) {
            DB::rollBack();
            if (null === $user->email || !$user->is_email_confirmed) {
                throw new UnprocessableEntityHttpException('Email is not set');
            }
            Validator::make($request->all(), ['uri' => 'required'])->validate();
            $confirmToken = Token::generate(
                Token::PASSWORD_CHANGE,
                $user,
                ['password' => Hash::make($request->input('user.password_new'))]
            );
            Mail::to($user)->queue(new UserPasswordChangeConfirm($confirmToken->uuid, $request->input('uri')));
            return self::json([]);
        }

        if (
            !$tokenAuthorization &&
            null !== $user->password &&
            (!$request->has('user.password_old') || !$user->validPassword($request->input('user.password_old')))
        ) {
            DB::rollBack();

            return self::json(
                $user->toArray(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['password_old' => 'Old password is not valid']
            );
        }

        $user->password = $request->input('user.password_new');
        $user->api_token = null;
        $user->save();

        if (null !== $user->email) {
            Mail::to($user)->queue(new UserPasswordChange());
        }

        DB::commit();

        return self::json($user->toArray());
    }

    public function confirmPasswordChange(string $token): JsonResponse
    {
        DB::beginTransaction();
        if (false === $tokenData = Token::check($token, null, Token::PASSWORD_CHANGE)) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException(self::ERROR_MESSAGE_INVALID_TOKEN);
        }

        $user = User::findOrFail($tokenData['user_id']);

        if (null === $user->email || !$user->is_email_confirmed) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException('Email is not set');
        }

        $user->setHashedPasswordAttribute($tokenData['payload']['password']);
        $user->api_token = null;
        $user->save();
        DB::commit();

        return self::json($user->toArray());
    }

    private function confirmEmail(User $user): void
    {
        $user->confirmEmail();
        if ($user->is_confirmed) {
            $this->awardBonus($user);
        }
    }

    private function confirmAdmin(User $user): void
    {
        $user->confirmAdmin();
        if ($user->is_confirmed) {
            $this->awardBonus($user);
        }
    }

    private function awardBonus(User $user): void
    {
        if (null !== $user->refLink && null !== $user->refLink->bonus && $user->refLink->bonus > 0) {
            try {
                $exchangeRate = $this->exchangeRateReader->fetchExchangeRate();
                $user->awardBonus($exchangeRate->toClick($user->refLink->bonus), $user->refLink);
            } catch (ExchangeRateNotAvailableException $exception) {
                Log::error(sprintf('[AuthController] Cannot fetch exchange rate: %s', $exception->getMessage()));
            }
        }
    }

    private function sendCrmMailOnUserRegistered(User $user): void
    {
        if (config('app.crm_mail_address_on_user_registered')) {
            Mail::to(config('app.crm_mail_address_on_user_registered'))->queue(
                new UserRegistered(
                    $user->uuid,
                    $user->email,
                    ($user->created_at ?: new DateTime())->format('d/m/Y'),
                    optional($user->refLink)->token
                )
            );
        }
    }
}
