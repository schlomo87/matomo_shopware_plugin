<?php

namespace SwClp\MatomoServerTagManager\Subscriber;

use Exception;
use SwClp\MatomoServerTagManager\Service\MatomoConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Page\Search\SearchPageLoadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use SwClp\MatomoServerTagManager\Service\TrackingDataCollectionService;

class MatomoSearchDataTracking implements EventSubscriberInterface
{
    private LoggerInterface $logger;
    private MatomoConfigService $matomoConfigService;
    private RequestStack $requestStack;
    private TrackingDataCollectionService $trackingDataCollection;

    public function __construct(
        LoggerInterface $logger,
        MatomoConfigService $matomoConfigService,
        RequestStack $requestStack,
        TrackingDataCollectionService $trackingDataCollection
    ) {
        $this->logger = $logger;
        $this->matomoConfigService = $matomoConfigService;
        $this->requestStack = $requestStack;
        $this->trackingDataCollection = $trackingDataCollection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SearchPageLoadedEvent::class => 'onProductSearchPageLoaded'
        ];
    }

    public function onProductSearchPageLoaded(SearchPageLoadedEvent $event): void
    {
        try {
            $this->logger->debug('MatomoServerTagManager: MatomoSearchDataTracking: onProductSearchPageLoaded: START');
            $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
            if (!$this->matomoConfigService->isMatomoTrackingEnabled($salesChannelId)) {
                $this->logger->debug('MatomoServerTagManager: MatomoSearchDataTracking: onProductSearchPageLoaded: MatomoEventTrackingDisabled');
                return;
            }
            $request = $this->requestStack->getCurrentRequest();
            if (!$request) {
                return;
            }

            $searchTerm = $event->getPage()->getSearchTerm();
            $searchResultCount = count($event->getPage()->getListing()->getElements());
            $trackingData = $this->trackingDataCollection->getTrackingData();
            if ($trackingData !== null) {
                $this->trackingDataCollection->addTrackingData('eventCategory', 'search');
                $this->trackingDataCollection->addTrackingData('eventAction', 'view');
                $this->trackingDataCollection->addTrackingData('eventName', $this->trackingDataCollection->getTransformedValue($searchTerm));
                $this->trackingDataCollection->addTrackingData('searchTerm', $searchTerm);
                $this->trackingDataCollection->addTrackingData('searchCount', $searchResultCount);
            }
        } catch (Exception $e) {
            $this->logger->error('MatomoServerTagManager: MatomoSearchDataTracking: onProductSearchPageLoaded: Error: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

}