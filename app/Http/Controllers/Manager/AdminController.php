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

namespace Adshares\Adserver\Http\Controllers\Manager;

use Adshares\Adserver\Facades\DB;
use Adshares\Adserver\Http\Controller;
use Adshares\Adserver\Http\Requests\UpdateAdminSettings;
use Adshares\Adserver\Http\Requests\UpdateRegulation;
use Adshares\Adserver\Http\Response\LicenseResponse;
use Adshares\Adserver\Http\Response\SettingsResponse;
use Adshares\Adserver\Mail\PanelPlaceholdersChange;
use Adshares\Adserver\Mail\UserBanned;
use Adshares\Adserver\Models\BidStrategy;
use Adshares\Adserver\Models\Campaign;
use Adshares\Adserver\Models\Classification;
use Adshares\Adserver\Models\Config;
use Adshares\Adserver\Models\PanelPlaceholder;
use Adshares\Adserver\Models\RefLink;
use Adshares\Adserver\Models\Site;
use Adshares\Adserver\Models\SitesRejectedDomain;
use Adshares\Adserver\Models\Token;
use Adshares\Adserver\Models\User;
use Adshares\Adserver\Models\UserLedgerEntry;
use Adshares\Adserver\Models\UserSettings;
use Adshares\Adserver\Repository\CampaignRepository;
use Adshares\Adserver\Utilities\SiteValidator;
use Adshares\Common\Application\Service\LicenseVault;
use Adshares\Common\Exception\RuntimeException;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AdminController extends Controller
{
    private const EMAIL_NOTIFICATION_DELAY_IN_MINUTES = 5;

    private LicenseVault $licenseVault;

    public function __construct(LicenseVault $licenseVault)
    {
        $this->licenseVault = $licenseVault;
    }

    public function listSettings(): SettingsResponse
    {
        $settings = Config::fetchAdminSettings();

        return SettingsResponse::fromConfigModel($settings);
    }

    public function updateSettings(UpdateAdminSettings $request): JsonResponse
    {
        $input = $request->toConfigFormat();
        Config::updateAdminSettings($input);

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function updateSiteSettings(Request $request): JsonResponse
    {
        $configData = [];
        $acceptBannersManually = $request->get('accept_banners_manually');
        if ($acceptBannersManually !== null) {
            $configData[Config::SITE_ACCEPT_BANNERS_MANUALLY] =
                filter_var($acceptBannersManually, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        $classifierLocalBanners = $request->get('classifier_local_banners');
        if ($classifierLocalBanners !== null) {
            if (!in_array($classifierLocalBanners, Config::ALLOWED_CLASSIFIER_LOCAL_BANNERS_OPTIONS, true)) {
                throw new UnprocessableEntityHttpException('Field classifierLocalBanners is invalid.');
            }
            $configData[Config::SITE_CLASSIFIER_LOCAL_BANNERS] = $classifierLocalBanners;
        }

        DB::beginTransaction();
        try {
            foreach ($configData as $key => $value) {
                Config::upsertByKey($key, $value);
            }
            DB::commit();
            return new JsonResponse([], Response::HTTP_NO_CONTENT);
        } catch (Exception $exception) {
            DB::rollBack();
            return new JsonResponse([], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function wallet(): JsonResponse
    {
        return self::json([
            'wallet' => [
                'balance' => UserLedgerEntry::getBalanceForAllUsers(),
                'unused_bonuses' => UserLedgerEntry::getBonusBalanceForAllUsers(),
            ]
        ]);
    }

    public function getLicense(): LicenseResponse
    {
        try {
            $license = $this->licenseVault->read();
        } catch (RuntimeException $exception) {
            throw new NotFoundHttpException($exception->getMessage());
        }

        return new LicenseResponse($license);
    }

    public function getPrivacyPolicy(): JsonResponse
    {
        return $this->getRegulation(PanelPlaceholder::TYPE_PRIVACY_POLICY);
    }

    public function getTerms(): JsonResponse
    {
        return $this->getRegulation(PanelPlaceholder::TYPE_TERMS);
    }

    private function getRegulation(string $type): JsonResponse
    {
        $regulation = PanelPlaceholder::fetchByType($type);

        if (null === $regulation) {
            return new JsonResponse([], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($regulation);
    }

    public function putPrivacyPolicy(UpdateRegulation $request): JsonResponse
    {
        return $this->putRegulation(PanelPlaceholder::TYPE_PRIVACY_POLICY, $request);
    }

    public function putTerms(UpdateRegulation $request): JsonResponse
    {
        return $this->putRegulation(PanelPlaceholder::TYPE_TERMS, $request);
    }

    private function putRegulation(string $type, UpdateRegulation $request): JsonResponse
    {
        PanelPlaceholder::register(PanelPlaceholder::construct($type, $request->toString()));

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function patchPanelPlaceholders(Request $request): JsonResponse
    {
        $input = $request->all();
        if (!$input) {
            throw new UnprocessableEntityHttpException('Missing data');
        }
        $regulations = [];
        foreach ($input as $type => $content) {
            if (!in_array($type, PanelPlaceholder::TYPES_ALLOWED, true)) {
                throw new UnprocessableEntityHttpException(sprintf('Invalid type (%s)', $type));
            }
            if (!is_string($content) || strlen($content) > PanelPlaceholder::MAXIMUM_CONTENT_LENGTH) {
                throw new UnprocessableEntityHttpException(sprintf('Invalid content for type (%s)', $type));
            }

            $regulations[] = PanelPlaceholder::construct($type, $content);
        }

        DB::beginTransaction();

        try {
            $registerDateTime = new DateTimeImmutable();
            $previousEmailSendDateTime = Config::fetchDateTime(Config::PANEL_PLACEHOLDER_NOTIFICATION_TIME);

            PanelPlaceholder::register($regulations);
            Config::upsertDateTime(Config::PANEL_PLACEHOLDER_UPDATE_TIME, $registerDateTime);

            if ($previousEmailSendDateTime <= $registerDateTime) {
                $emailSendDateTime =
                    $registerDateTime->modify(sprintf('+%d minutes', self::EMAIL_NOTIFICATION_DELAY_IN_MINUTES));
                Config::upsertDateTime(Config::PANEL_PLACEHOLDER_NOTIFICATION_TIME, $emailSendDateTime);
                Mail::to(config('app.adshares_operator_email'))
                    ->bcc(config('app.adshares_support_email'))
                    ->later($emailSendDateTime, new PanelPlaceholdersChange());
            }
        } catch (Exception $exception) {
            DB::rollBack();

            throw $exception;
        }

        DB::commit();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function getIndexUpdateTime(): JsonResponse
    {
        return self::json([
            'index_update_time' => Config::fetchDateTime(Config::PANEL_PLACEHOLDER_UPDATE_TIME)
                ->format(DateTimeInterface::ATOM),
        ]);
    }

    public function getRejectedDomains(): JsonResponse
    {
        return self::json(['domains' => SitesRejectedDomain::fetchAll()->pluck('domain')]);
    }

    public function putRejectedDomains(Request $request): JsonResponse
    {
        $domains = $request->get('domains');

        if (!is_array($domains)) {
            throw new BadRequestHttpException('Field `domains` must be an array');
        }

        foreach ($domains as $domain) {
            if (!SiteValidator::isDomainValid($domain)) {
                throw new UnprocessableEntityHttpException("Invalid domain ($domain)");
            }
        }

        $databaseDomains = SitesRejectedDomain::fetchAll();
        $databaseDomainsToDeleteIds = [];
        /** @var SitesRejectedDomain $databaseDomain */
        foreach ($databaseDomains as $databaseDomain) {
            if (!in_array($databaseDomain->domain, $domains)) {
                $databaseDomainsToDeleteIds[] = $databaseDomain->id;
            }
        }

        DB::beginTransaction();

        try {
            SitesRejectedDomain::deleteByIds($databaseDomainsToDeleteIds);
            foreach ($domains as $domain) {
                SitesRejectedDomain::upsert((string)$domain);
            }
            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info(sprintf('Domains cannot be rejected (%s).', $exception->getMessage()));

            throw new HttpException(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, 'Cannot add domains');
        }

        return self::json([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function switchUserToModerator(int $userId): JsonResponse
    {
        /** @var User $logged */
        $logged = Auth::user();

        if (!$logged->isAdmin()) {
            return self::json([], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = User::find($userId);
        if (empty($user)) {
            return self::json([], Response::HTTP_NOT_FOUND);
        }

        if ($user->isModerator()) {
            return self::json([], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->is_moderator = true;
        $user->is_agency = false;
        $user->save();

        return self::json($user->toArray());
    }

    public function switchUserToAgency(int $userId): JsonResponse
    {
        /** @var User $logged */
        $logged = Auth::user();

        if (!$logged->isModerator()) {
            return self::json([], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = User::find($userId);
        if (empty($user)) {
            return self::json([], Response::HTTP_NOT_FOUND);
        }

        if ($user->isAgency()) {
            return self::json([], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->is_moderator = false;
        $user->is_agency = true;
        $user->save();

        return self::json($user->toArray());
    }

    public function switchUserToRegular(int $userId): JsonResponse
    {
        /** @var User $logged */
        $logged = Auth::user();

        if (!$logged->isModerator()) {
            return self::json([], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = User::find($userId);
        if (empty($user)) {
            return self::json([], Response::HTTP_NOT_FOUND);
        }

        if ($user->isModerator() && !$logged->isAdmin()) {
            return self::json([], Response::HTTP_FORBIDDEN);
        }

        $user->is_moderator = false;
        $user->is_agency = false;
        $user->save();

        return self::json($user->toArray());
    }

    public function banUser(int $userId, Request $request): JsonResponse
    {
        $reason = $request->input('reason');
        if (!is_string($reason) || strlen(trim($reason)) < 1 || strlen(trim($reason)) > 255) {
            throw new UnprocessableEntityHttpException('Invalid reason');
        }

        /** @var User $user */
        $user = User::find($userId);
        if (empty($user)) {
            throw new NotFoundHttpException();
        }
        if ($user->isAdmin()) {
            throw new UnprocessableEntityHttpException('Administrator cannot be banned');
        }

        DB::beginTransaction();
        try {
            Campaign::deactivateAllForUserId($userId);
            $user->sites()->get()->each(
                function (Site $site) {
                    $site->changestatus(Site::STATUS_INACTIVE);
                    $site->save();
                }
            );
            $user->ban($reason);
            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        Mail::to($user)->queue(new UserBanned($reason));

        return self::json($user->toArray());
    }

    public function unbanUser(int $userId): JsonResponse
    {
        /** @var User $user */
        $user = User::find($userId);
        if (empty($user)) {
            throw new NotFoundHttpException();
        }

        $user->unban();

        return self::json($user->toArray());
    }

    public function deleteUser(int $userId, CampaignRepository $campaignRepository): JsonResponse
    {
        /** @var User $user */
        $user = User::find($userId);
        if (empty($user)) {
            throw new NotFoundHttpException();
        }
        if ($user->isAdmin()) {
            throw new UnprocessableEntityHttpException('Administrator cannot be deleted');
        }

        DB::beginTransaction();
        try {
            $campaigns = $campaignRepository->findByUserId($userId);
            foreach ($campaigns as $campaign) {
                $campaign->conversions()->delete();
                $campaignRepository->delete($campaign);
            }
            BidStrategy::deleteByUserId($userId);

            $sites = $user->sites();
            foreach ($sites->get() as $site) {
                $site->zones()->delete();
            }
            $sites->delete();

            RefLink::fetchByUser($userId)->each(fn (RefLink $refLink) => $refLink->delete());
            Token::deleteByUserId($userId);
            Classification::deleteByUserId($userId);
            UserSettings::deleteByUserId($userId);

            $user->maskEmailAndWalletAddress();
            $user->clearApiKey();
            $user->delete();

            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return self::json([], Response::HTTP_NO_CONTENT);
    }
}
