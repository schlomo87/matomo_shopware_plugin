<?php declare(strict_types=1);

namespace SwClp\MatomoServerTagManager\Subscriber;

use Random\RandomException;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use SwClp\MatomoServerTagManager\Service\MatomoBaseDataTrackerService;
use SwClp\MatomoServerTagManager\Service\RequestBaseDataExtractor;
use Psr\Log\LoggerInterface;
use SwClp\MatomoServerTagManager\Service\TrackingDataCollectionService;

class MatomoBaseDataTracking implements EventSubscriberInterface
{
    protected SystemConfigService $systemConfigService;
    protected ?array $trackingData = null;
    protected RequestBaseDataExtractor $requestDataExtractor;
    protected MatomoBaseDataTrackerService $matomoTrackerService;
    protected LoggerInterface $logger;
    protected TrackingDataCollectionService $trackingDataCollection;


    public function __construct(
        SystemConfigService $systemConfigService,
        RequestBaseDataExtractor $requestDataExtractor,
        MatomoBaseDataTrackerService $matomoTrackerService,
        LoggerInterface $logger,
        TrackingDataCollectionService $trackingDataCollection
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->requestDataExtractor = $requestDataExtractor;
        $this->matomoTrackerService = $matomoTrackerService;
        $this->logger = $logger;
        $this->trackingDataCollection = $trackingDataCollection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
    }

    /**
     * @throws RandomException
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTracking: onKernelRequest: START');
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->isXmlHttpRequest()) {
            return;
        }
        if (!$request->attributes->has(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID)) {
            return;
        }
        if ($request->getMethod() !== Request::METHOD_GET) {
            return;
        }

        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTracking: onKernelRequest: allConditionsFulfilled');
        $this->trackingData = $this->requestDataExtractor->extractTrackingData($request);
        $this->trackingDataCollection->setBaseTrackingData($this->trackingData);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTracking: onKernelResponse: START');
        if (!$event->isMainRequest() || $this->trackingData === null) {
            return;
        }

        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();

        if (!in_array($statusCode, [200, 301, 302, 404])) {
            $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTracking: onKernelResponse: statusCode.',[
                'statusCode' => $statusCode
            ]);
            return;
        }

        if ($this->trackingDataCollection->getTrackingData() !== null) {
            $this->trackingData = $this->trackingDataCollection->getTrackingData();
        }

        $this->trackingData['statusCode'] = $statusCode;
        $this->matomoTrackerService->trackPageView($this->trackingData);
        $this->trackingData = null;
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $this->logger->debug('MatomoServerTagManager: MatomoBaseDataTracking: onKernelException: START');
        if (!$event->isMainRequest() || $this->trackingData === null) {
            return;
        }

        $exception = $event->getThrowable();
        $statusCode = $exception instanceof NotFoundHttpException
            ? Response::HTTP_NOT_FOUND
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        if ($this->trackingDataCollection->getTrackingData() !== null) {
            $this->trackingData = $this->trackingDataCollection->getTrackingData();
        }

        $this->trackingData['statusCode'] = $statusCode;
        $this->logger->error('MatomoServerTagManager: MatomoBaseDataTracking: onKernelException: allConditionsFulfilled:', [
            'trackingData' => $this->trackingData
        ]);
        $this->matomoTrackerService->trackPageView($this->trackingData);
        $this->trackingData = null;
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        if ($this->trackingData === null) {
            return;
        }

        if (isset($event->getParameters()['page']) && method_exists($event->getParameters()['page'], 'getMetaInformation')) {
            $metaInformation = $event->getParameters()['page']->getMetaInformation();
            if ($metaInformation && method_exists($metaInformation, 'getMetaTitle')) {
                $this->trackingData['title'] = $metaInformation->getMetaTitle() ?? $this->trackingData['title'];
            }
        }
    }

}