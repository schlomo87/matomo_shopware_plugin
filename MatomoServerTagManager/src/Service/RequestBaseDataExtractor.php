<?php declare(strict_types=1);

namespace SwClp\MatomoServerTagManager\Service;

use Psr\Log\LoggerInterface;
use Random\RandomException;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestBaseDataExtractor
{
    private MatomoConfigService $matomoConfigService;
    private LoggerInterface $logger;

    public function __construct(
        MatomoConfigService $matomoConfigService,
        LoggerInterface $logger
    ) {
        $this->matomoConfigService = $matomoConfigService;
        $this->logger = $logger;
    }

    /**
     * @throws RandomException
     */
    public function extractTrackingData(Request $request): array
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: extractTrackingData: START');
        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $fullUrl = $this->getFullUrl($request);
        $pageTitle = $this->getPageTitle($request) ?: $fullUrl;

        return [
            'userIp' => $request->getClientIp(),
            'title' => $pageTitle,
            'url' => $fullUrl,
            'userId' => substr(bin2hex(hash('sha256', $request->getClientIp(), true)), 0, 16),
            'referer' => $this->getReferer($request, $salesChannelId),
            'resolution' => $this->getResolution($request),
            'userAgent' => $request->headers->get('User-Agent'),
            'userAgentData' => $this->getUserAgentData($request),
            'language' => $request->headers->get('Accept-Language'),
            'clientId' => $this->getClientId($request),
            'new_visit' => $this->getNewVisit($request),
            'campaignName' => $this->getCampaignParameter($request, 'mtm_campaign'),
            'campaignKeywords' => $this->getCampaignParameter($request, 'mtm_kwd'),
            'googleClickId' => $this->getGoogleClickId($request, 'gclid'),
            'pageViewId' => $this->getPageViewId($pageTitle),
            'salesChannelId' => $salesChannelId,
        ];
    }

    public function getPageTitle(Request $request): ?string
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getPageTitle: START');
        return $request->attributes->get('pageTitle');
    }

    public function getFullUrl(Request $request): string
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getFullUrl: START');
        $baseUrl = $request->getSchemeAndHttpHost();

        if ($request->attributes->has('sw-original-request-uri')) {
            return $baseUrl . $request->attributes->get('sw-original-request-uri');
        }

        if ($request->attributes->has('salesChannelBaseUrl')) {
            $salesChannelBaseUrl = $request->attributes->get('salesChannelBaseUrl');
            return rtrim($salesChannelBaseUrl, '/') . $request->getPathInfo();
        }

        return $baseUrl . $request->getRequestUri();
    }

    public function getReferer(Request $request, string $salesChannelId): ?string
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getReferer: START');
        $referer = $request->headers->get('Referer');
        if (empty($referer)) {
            return null;
        }

        $refererParts = parse_url($referer);
        if (!isset($refererParts['host'])) {
            return null;
        }
        $refererHost = $refererParts['host'];
        $currentHost = $request->getHost();

        if (strcasecmp($refererHost, $currentHost) === 0) {
            return null;
        }

        $excludedRefererDomains = $this->matomoConfigService->getExcludedReferrers($salesChannelId);
        if (!empty($excludedRefererDomains)) {
            $excludedDomains = array_map('trim', explode(',', $excludedRefererDomains));

            foreach ($excludedDomains as $domain) {
                if (empty($domain)) {
                    continue;
                }

                if (strcasecmp($refererHost, $domain) === 0) {
                    return null;
                }
            }
        }
        return $referer;
    }

    public function getResolution(Request $request): ?string
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getResolution: START');
        $session = $request->getSession();
        $width = $session->get('screen_width');
        $height = $session->get('screen_height');
        if ($width && $height) {
            return $width . 'x' . $height;
        }
        return null;
    }

    public function getUserAgentData(Request $request): string
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getUserAgentData: START');
        $headers = $request->headers;
        $clientHints = [
            'model' => $headers->get('Sec-CH-UA-Model'),
            'platform' => $headers->get('Sec-CH-UA-Platform'),
            'platformVersion' => $headers->get('Sec-CH-UA-Platform-Version'),
            'browserVersion' => $headers->get('Sec-CH-UA-Full-Version-List'),
            'mobile' => $headers->get('Sec-CH-UA-Mobile'),
            'brands' => $headers->get('Sec-CH-UA'),
            'acceptLanguage' => $headers->get('Accept-Language'),
            'doNotTrack' => $headers->get('DNT'),
            'viewportWidth' => $headers->get('Viewport-Width'),
            'viewportHeight' => $headers->get('Viewport-Height'),
            'devicePixelRatio' => $headers->get('Device-Pixel-Ratio'),
            'referrer' => $headers->get('Referer'),
            'connection' => $headers->get('Connection'),
            'acceptEncoding' => $headers->get('Accept-Encoding'),
        ];
        return json_encode(array_filter($clientHints));
    }

    /**
     * @throws RandomException
     */
    public function getClientId(Request $request): string
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getClientId: START');
        $session = $request->getSession();
        $sessionName = 'matomo_client_id';

        if ($session->has($sessionName)) {
            return $session->get($sessionName);
        }
        $clientId = bin2hex(random_bytes(8));
        $session->set($sessionName, $clientId);
        return $clientId;
    }

    public function getNewVisit(Request $request, ?Response $response = null): ?int
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getNewVisit: START');
        $isNewVisit = $request->cookies->get('matomo_new_visit');
        if ($isNewVisit && $response !== null) {
            $response->headers->clearCookie('matomo_new_visit');
        }
        return $isNewVisit ? 1 : null;
    }

    public function getCampaignParameter(Request $request, String $parameter): ?string
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getCampaignParameter: START');
        if ($request->query->has($parameter)) {
            return $request->query->get($parameter);
        }
        return null;
    }

    public function getPageViewId(string $pageTitle): ?string
    {
        $this->logger->debug('MatomoServerTagManager: RequestBaseDataExtractor: getPageViewId: START');
        $hash = md5($pageTitle);
        $hexDecValue = hexdec(substr($hash, 0, 6)) % 1000000;
        return str_pad((string)$hexDecValue, 6, '0', STR_PAD_LEFT);
    }

    public function getGoogleClickId(Request $request, string $parameter): ?string
    {
        $googleClickId = $this->getCampaignParameter($request, $parameter);
        if (!$googleClickId) {
            return null;
        }
        $session = $request->getSession();
        $session->set('matomo_google_click_id', $googleClickId);
        return $googleClickId;

    }

}