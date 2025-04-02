<?php declare(strict_types=1);

namespace SwClp\MatomoServerTagManager\Service;

use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class MatomoBaseDataTrackerService
{
    private MatomoConfigService $matomoConfigService;
    private LoggerInterface $logger;
    private EntityRepository $orderRepository;

    public function __construct(
        MatomoConfigService $matomoConfigService,
        LoggerInterface $logger,
        EntityRepository $orderRepository
    ) {
        $this->matomoConfigService = $matomoConfigService;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
    }

    public function trackPageView(array $trackingData): void
    {
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTrackerService: trackPageView: START');
        $orderId = $trackingData['commerceOrderId'] ?? null;
        $context = $trackingData['context'] ?? null;
        $postData = $this->getPostData($trackingData);
        $this->sendTrackingRequest($postData, $trackingData['salesChannelId'], $orderId, $context);
    }

    public function getPostData(array $trackingData): array
    {
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTrackerService: getPostData: START');
        $siteId = $this->matomoConfigService->getSiteId($trackingData['salesChannelId']) ?: 1;
        $apiToken = $this->matomoConfigService->getApiToken($trackingData['salesChannelId']);

        $postData = array_merge(
            [
                'token_auth' => $apiToken,
                'cip' => $trackingData['userIp'],
                'idsite' => $siteId,
                'rec' => 1,
                'action_name' => $trackingData['title'],
                'url' => $trackingData['url'],
                '_id' => $trackingData['userId'],
                'rand' => mt_rand(),
                'apiv' => 1,
                'res' => $trackingData['resolution'],
                'h' => date('H'),
                'm' => date('i'),
                's' => date('s'),
                'ua' => $trackingData['userAgent'],
                'uadata' => $trackingData['userAgentData'],
                'cid' => $trackingData['clientId'],
                'pv_id' => $trackingData['pageViewId'],
                'cs' => 'uft-8'
            ],
            !empty($trackingData['referer']) ? ['urlref' => $trackingData['referer']] : [],
            !empty($trackingData['language']) ? ['lang' => $trackingData['language']] : [],
            !empty($trackingData['new_visit']) ? ['new_visit' => $trackingData['new_visit']] : [],
            !empty($trackingData['campaignName']) ? ['_rcn' => $trackingData['campaignName']] : [],
            !empty($trackingData['campaignKeywords']) ? ['_rck' => $trackingData['campaignKeywords']] : [],
            !empty($trackingData['googleClickId']) ? ['dimension1' => $trackingData['googleClickId']] : [],
            !empty($trackingData['commercePayment']) ? ['dimension2' => $trackingData['commercePayment']] : [],
            !empty($trackingData['commerceDifferentAddress']) ? ['dimension3' => $trackingData['commerceDifferentAddress']] : [],
            !empty($trackingData['countryBilling']) ? ['dimension4' => $trackingData['countryBilling']] : [],
            !empty($trackingData['countryShipping']) ? ['dimension5' => $trackingData['countryShipping']] : [],
        );
        if ($this->matomoConfigService->isMatomoEventTrackingEnabled($trackingData['salesChannelId'])) {
            $this->mapTrackingData($trackingData, $postData, [
                'eventCategory' => 'e_c',
                'eventAction' => 'e_a',
                'eventName' => 'e_n'
            ]);
        }
        if ($this->matomoConfigService->isMatomoEcommerceTrackingEnabled($trackingData['salesChannelId'])) {
            $this->mapTrackingData($trackingData, $postData, [
                'idGoal' => 'idgoal',
                'cartItems' => 'ec_items',
                'cartRevenue' => 'revenue',
                'commerceCategoryName' => '_pkc',
                'commerceProductPrice' => '_pkp',
                'commerceProductNumber' => '_pks',
                'commerceProductName' => '_pkn',
                'commerceOrderNumber' => 'ec_id',
                'commerceSubtotal' => 'ec_st',
                'commerceTax' => 'ec_tx',
                'commerceShipping' => 'ec_sh',
                'commerceDiscount' => 'ec_dt'
            ]);
        }

        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTrackerService: getPostData: postData', [
            'postData' => $postData
        ]);
        return $postData;
    }

    public function getAddToCartData(array $trackingData): array
    {
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTrackerService: getAddToCartData: START');
        $siteId = $this->matomoConfigService->getSiteId($trackingData['salesChannelId']) ?: 1;
        $apiToken = $this->matomoConfigService->getApiToken($trackingData['salesChannelId']);

        return [
            'token_auth' => $apiToken,
            'cip' => $trackingData['userIp'],
            'idsite' => $siteId,
            'rec' => 1,
            'action_name' => $trackingData['title'],
            'url' => $trackingData['url'],
            '_id' => $trackingData['userId'],
            'rand' => mt_rand(),
            'apiv' => 1,
            'res' => $trackingData['resolution'],
            'h' => date('H'),
            'm' => date('i'),
            's' => date('s'),
            'ua' => $trackingData['userAgent'],
            'uadata' => $trackingData['userAgentData'],
            'cid' => $trackingData['clientId'],
            'pv_id' => $trackingData['pageViewId'],
            'cs' => 'uft-8'
        ];
    }

    public function sendTrackingRequest(array $postData, string $salesChannelId, ?string $orderId = null, ?Context $context = null): void
    {
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTrackerService: sendTrackingRequest: START');
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTrackerService: sendTrackingRequest: data', [
            'postData' => $postData,
            'salesChannelId' => $salesChannelId,
        ]);
        $matomoUrl = $this->matomoConfigService->getMatomoUrl($salesChannelId) . "/matomo.php";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $matomoUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTrackerService: sendTrackingRequest: response', [
            'url' => $matomoUrl,
            'payload' => $postData,
            'http_code' => $httpCode,
            'response' => $response,
            'error' => curl_error($ch)
        ]);

        $isSuccess = $httpCode === 200;
        if (isset($postData['ec_id']) && $orderId !== null && $context !== null && $isSuccess) {
            $this->updateOrderTrackingStatus($orderId, $context);
        }

        if ($httpCode !== 200) {
            $this->logger->error('MatomoServerTagManager: MatomoBaseDataTrackerService: sendTrackingRequest: apiError', [
                'status' => $httpCode,
                'response' => substr($response, 0, 500)
            ]);
        }

        curl_close($ch);
    }

    private function mapTrackingData(array $trackingData, array &$postData, array $mapping): void
    {
        foreach ($mapping as $src => $dest) {
            if (isset($trackingData[$src])) {
                $postData[$dest] = $trackingData[$src];
            }
        }
    }

    private function updateOrderTrackingStatus(string $orderId, Context $context): void
    {
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTrackerService: updateOrderTrackingStatus', [
            'orderNumber' => $orderId
        ]);

        try {
            $this->orderRepository->update([
                [
                    'id' => $orderId,
                    'customFields' => [
                        'custom_matomo_tracking_order_success' => true
                    ]
                ]
            ], $context);
        } catch (Exception $e) {
            $this->logger->error('MatomoServerTagManager: MatomoBaseDataTrackerService: updateOrderTrackingStatus: Error: ', [
                'orderNumber' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    }

}