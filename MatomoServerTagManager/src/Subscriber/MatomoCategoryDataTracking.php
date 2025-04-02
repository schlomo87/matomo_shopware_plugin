<?php

namespace SwClp\MatomoServerTagManager\Subscriber;

use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use SwClp\MatomoServerTagManager\Service\MatomoConfigService;
use SwClp\MatomoServerTagManager\Service\CategoryHandlerService;
use Symfony\Component\HttpFoundation\RequestStack;
use SwClp\MatomoServerTagManager\Service\TrackingDataCollectionService;

class MatomoCategoryDataTracking implements EventSubscriberInterface
{
    private const UNKNOWN_CATEGORY = 'unknown';
    private LoggerInterface $logger;
    private MatomoConfigService $matomoConfigService;
    private CategoryHandlerService $categoryHandlerService;
    private RequestStack $requestStack;
    private TrackingDataCollectionService $trackingDataCollection;

    public function __construct(
        LoggerInterface $logger,
        MatomoConfigService $matomoConfigService,
        CategoryHandlerService $categoryHandlerService,
        RequestStack $requestStack,
        TrackingDataCollectionService $trackingDataCollection
    ) {
        $this->logger = $logger;
        $this->matomoConfigService = $matomoConfigService;
        $this->categoryHandlerService = $categoryHandlerService;
        $this->requestStack = $requestStack;
        $this->trackingDataCollection = $trackingDataCollection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NavigationPageLoadedEvent::class => 'onNavigationPageLoaded'
        ];
    }

    public function onNavigationPageLoaded(NavigationPageLoadedEvent $event): void
    {
        try {
            $this->logger->debug('MatomoServerTagManager: MatomoCategoryDataTracking: onNavigationPageLoaded: START');
            $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
            if (!$this->matomoConfigService->isMatomoTrackingEnabled($salesChannelId)) {
                $this->logger->debug('MatomoServerTagManager: MatomoCategoryDataTracking: onNavigationPageLoaded: MatomoEventTrackingDisabled');
                return;
            }
            $request = $this->requestStack->getCurrentRequest();
            if (!$request) {
                return;
            }
            $page = $event->getPage();
            $category = $page->getNavigationId() ? $page->getCategory() : null;
            if (!$category) {
                $this->logger->debug('MatomoServerTagManager: MatomoCategoryDataTracking: onNavigationPageLoaded: NoCategoryFound');
                return;
            }
            $categoryName = $category->getTranslated()['name'] ?? self::UNKNOWN_CATEGORY;
            $this->categoryHandlerService->setCategoryViewName($categoryName);
            $this->categoryHandlerService->clearLastParentId();
            $categoryNameTransformed = $this->trackingDataCollection->getTransformedValue($categoryName);
            $trackingData = $this->trackingDataCollection->getTrackingData();
            if ($trackingData !== null) {
                $this->trackingDataCollection->addTrackingData('eventCategory', 'category');
                $this->trackingDataCollection->addTrackingData('eventAction', 'view');
                $this->trackingDataCollection->addTrackingData('eventName', $categoryNameTransformed);
                $this->trackingDataCollection->addTrackingData('commerceCategoryName', $categoryName);
            }
        } catch (Exception $e) {
            $this->logger->error('MatomoServerTagManager: MatomoCategoryDataTracking: onNavigationPageLoaded: Error' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }
}