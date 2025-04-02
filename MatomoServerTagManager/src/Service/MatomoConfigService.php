<?php declare(strict_types=1);

namespace SwClp\MatomoServerTagManager\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class MatomoConfigService
{
    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public function isMatomoTrackingEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get('MatomoServerTagManager.config.matomoTrackingActive', $salesChannelId);
    }

    public function getMatomoUrl(?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->get('MatomoServerTagManager.config.matomoTrackingUrl', $salesChannelId);
    }

    public function getSiteId(?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->get('MatomoServerTagManager.config.matomoSiteId', $salesChannelId);
    }

    public function getApiToken(?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->get('MatomoServerTagManager.config.matomoApiKey', $salesChannelId);
    }

    public function getExcludedReferrers(?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->get('MatomoServerTagManager.config.setExcludedReferrers', $salesChannelId);
    }

    public function isMatomoEventTrackingEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get('MatomoServerTagManager.config.matomoEventTrackingActive', $salesChannelId);
    }

    public function isMatomoEcommerceTrackingEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get('MatomoServerTagManager.config.matomoEcommerceTrackingActive', $salesChannelId);
    }
}
