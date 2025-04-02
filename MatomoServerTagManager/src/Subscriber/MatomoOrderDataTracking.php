<?php

namespace SwClp\MatomoServerTagManager\Subscriber;

use Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use SwClp\MatomoServerTagManager\Service\CategoryHandlerService;
use SwClp\MatomoServerTagManager\Service\MatomoConfigService;
use SwClp\MatomoServerTagManager\Service\TrackingDataCollectionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;

class MatomoOrderDataTracking implements EventSubscriberInterface
{
    private const UNKNOWN_CATEGORY = 'unknown';
    private LoggerInterface $logger;
    private MatomoConfigService $matomoConfigService;
    private RequestStack $requestStack;
    private CategoryHandlerService $categoryHandlerService;
    private TrackingDataCollectionService $trackingDataCollection;
    private EntityRepository $orderRepository;

    public function __construct(
        LoggerInterface $logger,
        MatomoConfigService $matomoConfigService,
        RequestStack $requestStack,
        CategoryHandlerService $categoryHandlerService,
        TrackingDataCollectionService $trackingDataCollection,
        EntityRepository $orderRepository
    ) {
        $this->logger = $logger;
        $this->matomoConfigService = $matomoConfigService;
        $this->requestStack = $requestStack;
        $this->categoryHandlerService = $categoryHandlerService;
        $this->trackingDataCollection = $trackingDataCollection;
        $this->orderRepository = $orderRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutFinishPageLoadedEvent::class => 'onCheckoutFinish'
        ];
    }

    public function onCheckoutFinish(CheckoutFinishPageLoadedEvent $event): void
    {
        try {
            $this->logger->debug('MatomoServerTagManager: MatomoOrderDataTracking: onCheckoutFinish: START');
            $salesChannelContext = $event->getSalesChannelContext();
            $salesChannelId = $salesChannelContext->getSalesChannelId();
            $context = $salesChannelContext->getContext();
            if (!$this->matomoConfigService->isMatomoTrackingEnabled($salesChannelId)) {
                $this->logger->debug('MatomoServerTagManager: MatomoOrderDataTracking: onCheckoutFinish: MatomoTrackingDisabled');
                return;
            }
            $request = $this->requestStack->getCurrentRequest();
            if (!$request) {
                $this->logger->debug('MatomoServerTagManager: MatomoOrderDataTracking: onCheckoutFinish: noRequest');
                return;
            }
            $page = $event->getPage();
            $order = $page->getOrder();
            $googleClickId = $this->getSessionGoogleClickId();
            if (!empty($googleClickId)) {
                $this->updateOrderGoogleClickId($order, $googleClickId, $context);
            }
            if (!$order->getLineItems()) {
                $this->logger->error('MatomoServerTagManager: MatomoOrderDataTracking: onCheckoutFinish: noLineItems: ', [
                    'orderId' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber()
                ]);
                return;
            }

            $lineItems = $this->getOrderProducts($order);
            $discountValue = $this->getOrderPromotionDiscountAmount($order);
            $paymentMethodName = $this->getOrderPaymentMethodName($order);
            $differentAddress = $this->hasShippingAddressDifferentFromBilling($order);
            $billingAddressCountry = $order->getBillingAddress()?->getCountry()?->getName() ?? self::UNKNOWN_CATEGORY;
            $shippingAddressCountry = $order->getDeliveries()?->first()?->getShippingOrderAddress()?->getCountry()?->getName() ?? self::UNKNOWN_CATEGORY;

            $trackingData = $this->trackingDataCollection->getTrackingData();
            if ($trackingData !== null) {
                $this->trackingDataCollection->addTrackingData('eventCategory', 'order');
                $this->trackingDataCollection->addTrackingData('eventAction', 'view');
                $this->trackingDataCollection->addTrackingData('eventName', 'purchase');
                $this->trackingDataCollection->addTrackingData('idGoal', 0);
                $this->trackingDataCollection->addTrackingData('commerceOrderNumber', $order->getOrderNumber());
                $this->trackingDataCollection->addTrackingData('commerceOrderId', $order->getId());
                $this->trackingDataCollection->addTrackingData('cartItems', $lineItems);
                $this->trackingDataCollection->addTrackingData('cartRevenue', $order->getAmountTotal());
                $this->trackingDataCollection->addTrackingData('commerceSubtotal', $order->getAmountNet());
                $this->trackingDataCollection->addTrackingData('commerceTax', $order->getPrice()->getCalculatedTaxes()->getAmount());
                $this->trackingDataCollection->addTrackingData('commerceShipping', $order->getShippingTotal());
                $this->trackingDataCollection->addTrackingData('commerceDiscount', $discountValue);
                $this->trackingDataCollection->addTrackingData('commercePayment', $paymentMethodName);
                $this->trackingDataCollection->addTrackingData('commerceDifferentAddress', $differentAddress);
                $this->trackingDataCollection->addTrackingData('context', $context);
                $this->trackingDataCollection->addTrackingData('countryBilling', $billingAddressCountry);
                $this->trackingDataCollection->addTrackingData('countryShipping', $shippingAddressCountry);
            }
            $this->categoryHandlerService->clearCartItems();
        } catch (Exception $e) {
            $this->logger->error('MatomoServerTagManager: MatomoProductDataTracking: onProductPageLoaded: Error: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    private function getOrderProducts(OrderEntity $order): string
    {
        $orderItems = [];
        $lineItems = $order->getLineItems();
        $sessionProductCategory = $this->categoryHandlerService->getCategoryProductView();

        if ($lineItems) {
            foreach ($lineItems as $item) {
                if ($item->getType() !== 'product') {
                    continue;
                }
                $productNumber = $item->getPayload()['productNumber'] ?? '';
                $productName = $item->getLabel() ?? $this->getProductNameFallback($item, $productNumber);

                $productCategory = $sessionProductCategory[$productNumber]['category'] ?? self::UNKNOWN_CATEGORY;
                if (empty($productCategory)) {
                    $this->logger->error('MatomoServerTagManager: MatomoOrderDataTracking: getOrderProducts: noCategoryFound: ', [
                        'productNumber' => $productNumber
                    ]);
                }
                $productPrice = $item->getUnitPrice();
                $productQuantity = $item->getQuantity();

                $orderItems[] = [
                    $productNumber,
                    $productName,
                    $productCategory,
                    $productPrice,
                    $productQuantity
                ];
            }
        }
        return json_encode($orderItems);
    }

    private function getProductNameFallback($item, ?string $productNumber): string
    {
        $this->logger->warning('[Matomo OrderTrackingSubscriber] Produkt-Label ist null für Produkt', [
            'productId' => $item->getId(),
            'productNumber' => $productNumber,
            'payload' => $item->getPayload()
        ]);

        return $item->getPayload()['name'] ?? $productNumber ?? self::UNKNOWN_CATEGORY;
    }

    private function getOrderPromotionDiscountAmount(OrderEntity $order): float
    {
        if (!$order->getLineItems()) {
            return 0.0;
        }

        $promotionItems = $order->getLineItems()->filter(function ($lineItem) {
            return $lineItem->getType() === 'promotion';
        });

        $totalDiscount = 0.0;
        foreach ($promotionItems as $promotionItem) {
            $totalDiscount += abs($promotionItem->getTotalPrice());
        }

        return $totalDiscount;
    }

    private function getOrderPaymentMethodName(OrderEntity $order): ?string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return self::UNKNOWN_CATEGORY;
        }
        $lastTransaction = $transactions->last();
        if ($lastTransaction === null) {
            return self::UNKNOWN_CATEGORY;
        }
        $paymentMethod = $lastTransaction->getPaymentMethod();
        return $paymentMethod?->getName();
    }

    public function hasShippingAddressDifferentFromBilling(OrderEntity $order): string
    {
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getDeliveries()?->first()?->getShippingOrderAddress();

        if ($billingAddress === null || $shippingAddress === null) {
            return self::UNKNOWN_CATEGORY;
        }
        return $this->areAddressesDifferent($billingAddress, $shippingAddress);
    }

    private function areAddressesDifferent(OrderAddressEntity $billingAddress, OrderAddressEntity $shippingAddress): string
    {
        if (
            $billingAddress->getStreet() !== $shippingAddress->getStreet() ||
            $billingAddress->getZipcode() !== $shippingAddress->getZipcode() ||
            $billingAddress->getCity() !== $shippingAddress->getCity() ||
            $billingAddress->getCountryId() !== $shippingAddress->getCountryId() ||
            $billingAddress->getFirstName() !== $shippingAddress->getFirstName() ||
            $billingAddress->getLastName() !== $shippingAddress->getLastName() ||
            $billingAddress->getCompany() !== $shippingAddress->getCompany()
        ) {
            return 'Rechnungsadresse != Lieferadresse';
        }

        return 'Rechnungsadresse == Lieferadresse';
    }

    private function getSessionGoogleClickId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->hasSession()) {
            return $request->getSession()->get('matomo_google_click_id');
        }
        return null;
    }

    private function clearSessionGoogleClickId(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove('matomo_google_click_id');
    }

    private function updateOrderGoogleClickId(OrderEntity $order, string $googleClickId, Context $context): void
    {
        $customFields = $order->getCustomFields() ?? [];
        $customFields['custom_google_click_id'] = $googleClickId;
        $order->setCustomFields($customFields);
        $this->orderRepository->update(
            [['id' => $order->getId(), 'customFields' => $customFields]],
            $context
        );
        $this->clearSessionGoogleClickId();
    }

}