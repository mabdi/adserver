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

namespace Adshares\Adserver\Services\Publisher;

use Adshares\Adserver\Http\Requests\Campaign\TargetingProcessor;
use Adshares\Common\Application\Service\ConfigurationRepository;
use Adshares\Common\Exception\InvalidArgumentException;

class SiteCategoriesValidator
{
    private ConfigurationRepository $configurationRepository;

    private TargetingProcessor $targetingProcessor;

    public function __construct(ConfigurationRepository $configurationRepository)
    {
        $this->configurationRepository = $configurationRepository;
    }

    public function processCategories($categories, $medium, $vendor): array
    {
        if (!$medium) {
            throw new InvalidArgumentException('Field `medium` is required.');
        }
        if (!is_string($medium)) {
            throw new InvalidArgumentException('Field `medium` must be a string.');
        }
        if ($vendor !== null && !is_string($vendor)) {
            throw new InvalidArgumentException('Field `vendor` must be a string or null.');
        }

        if (!isset($this->targetingProcessor)) {
            $this->targetingProcessor = new TargetingProcessor($this->configurationRepository->fetchTaxonomy());
        }

        if (!$this->targetingProcessor->checkIfPathExist(['site', 'category'], $medium, $vendor)) {
            return ['unknown'];
        }

        if (!$categories) {
            throw new InvalidArgumentException('Field `categories` is required.');
        }
        if (!is_array($categories)) {
            throw new InvalidArgumentException('Field `categories` must be an array.');
        }
        $targeting = $this->targetingProcessor->processTargeting(
            ['site' => ['category' => $categories]],
            $medium,
            $vendor
        );

        if (!$targeting) {
            throw new InvalidArgumentException('Field categories[] must match targeting taxonomy');
        }

        return $targeting['site']['category'];
    }
}
