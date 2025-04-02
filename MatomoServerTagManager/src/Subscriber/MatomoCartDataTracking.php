<?php

namespace SwClp\MatomoServerTagManager\Subscriber;

use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use SwClp\MatomoServerTagManager\Service\CategoryHandlerService;
use SwClp\MatomoServerTagManager\Service\MatomoBaseDataTrackerService;
use SwClp\MatomoServerTagManager\Service\MatomoConfigService;
use SwClp\MatomoServerTagManager\Service\RequestBaseDataExtractor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class MatomoCartDataTracking implements EventSubscriberInterface
{
    private const UNKNOWN_CATEGORY = 'unknown';
    private LoggerInterface $logger;
    private MatomoConfigService $matomoConfigService;
    private RequestStack $requestStack;
    private CategoryHandlerService $categoryHandlerService;
    private MatomoBaseDataTrackerService $matomoTrackerService;
    private RequestBaseDataExtractor $requestDataExtractor;

    public function __construct(
        LoggerInterface $logger,
        MatomoConfigService $matomoConfigService,
        RequestStack $requestStack,
        CategoryHandlerService $categoryHandlerService,
        MatomoBaseDataTrackerService $matomoTrackerService,
        RequestBaseDataExtractor $requestDataExtractor
    ) {
        $this->logger = $logger;
        $this->matomoConfigService = $matomoConfigService;
        $this->requestStack = $requestStack;
        $this->categoryHandlerService = $categoryHandlerService;
        $this->matomoTrackerService = $matomoTrackerService;
        $this->requestDataExtractor = $requestDataExtractor;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AfterLineItemAddedEvent::class => 'onAfterLineItemAdded'
        ];
    }

    public function onAfterLineItemAdded(AfterLineItemAddedEvent $event): void
    {
        try {
            $this->logger->debug('MatomoServerTagManager: MatomoCartDataTracking: onAfterLineItemAdded: START');
            $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
            if (!$this->matomoConfigService->isMatomoTrackingEnabled($salesChannelId)) {
                $this->logger->debug('MatomoServerTagManager: MatomoCartDataTracking: onAfterLineItemAdded: MatomoEventTrackingDisabled');
                return;
            }
            $request = $this->requestStack->getCurrentRequest();
            if (!$request) {
                return;
            }
            $eventTrackingEnabled = $this->matomoConfigService->isMatomoEventTrackingEnabled($salesChannelId);
            $ecommerceTrackingEnabled = $this->matomoConfigService->isMatomoEcommerceTrackingEnabled($salesChannelId);
            if (!$eventTrackingEnabled && !$ecommerceTrackingEnabled) {
                $this->logger->debug('MatomoServerTagManager: MatomoCartDataTracking: onAfterLineItemAdded: noTrackingEnabled');
                return;
            }

            $cart = $event->getCart();
            $cartTotal = $cart->getPrice()->getTotalPrice();
            $cartItems = $this->getCartProducts($cart);
            $trackingData = $this->requestDataExtractor->extractTrackingData($request);
            $postData = $this->matomoTrackerService->getAddToCartData($trackingData);

            if ($eventTrackingEnabled) {
                $postData['e_c'] = 'cart';
                $postData['e_a'] = 'click';
                $postData['e_n'] = 'add_to_cart';
            }
            if ($ecommerceTrackingEnabled) {
                $postData['idgoal'] = 0;
                $postData['ec_items'] = $cartItems;
                $postData['revenue'] = $cartTotal;
            }
            $this->logger->debug('MatomoServerTagManager: MatomoCartDataTracking: onAfterLineItemAdded: allPostData:', [
                'postData' => $postData
            ]);
            $this->matomoTrackerService->sendTrackingRequest($postData,$trackingData['salesChannelId']);

        } catch (Exception $e) {
            $this->logger->error('MatomoServerTagManager: MatomoCartDataTracking: onAfterLineItemAdded: Error: ' . $e->getMessage(), [
                'exception' => $e,
                'cartToken' => $event->getCart()->getToken()
            ]);
        }
    }

    private function getCartProducts($cart): string
    {
        $formattedProducts = [];
        $lineItems = $cart->getLineItems();
        $sessionProductCategory = $this->categoryHandlerService->getCategoryProductView();

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() !== 'product') {
                continue;
            }

            $productNumber = $lineItem->getPayload()['productNumber'] ?? $lineItem->getKey();
            $productName = $lineItem->getLabel() ?? $this->getProductNameFallback($lineItem, $productNumber);
            $productCategory = $sessionProductCategory[$productNumber]['category'] ?? self::UNKNOWN_CATEGORY;
            if (empty($productCategory)) {
                $this->logger->warning('MatomoServerTagManager: MatomoCartDataTracking: getCartProducts: noProductCategoryFound:', [
                    'productNumber' => $productNumber
                ]);
            }

            $productPrice = $lineItem->getPrice()->getUnitPrice();
            $quantity = $lineItem->getQuantity();

            $formattedProducts[] = [
                $productNumber,
                $productName,
                $productCategory,
                $productPrice,
                $quantity
            ];
        }
        return json_encode($formattedProducts);
    }

    private function getProductNameFallback($item, ?string $productNumber): string
    {
        $this->logger->warning('MatomoServerTagManager: MatomoCartDataTracking: getCartProducts: noProductLabelFound: fallbackActive:', [
            'productId' => $item->getId(),
            'productNumber' => $productNumber,
            'payload' => $item->getPayload()
        ]);

        return $item->getPayload()['name'] ?? $productNumber ?? self::UNKNOWN_CATEGORY;
    }
}