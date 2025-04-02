<?php

namespace SwClp\MatomoServerTagManager\Subscriber;

use Exception;
use Throwable;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use SwClp\MatomoServerTagManager\Service\CategoryHandlerService;
use SwClp\MatomoServerTagManager\Service\MatomoConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use SwClp\MatomoServerTagManager\Service\TrackingDataCollectionService;

class MatomoProductDataTracking implements EventSubscriberInterface
{
    private const UNKNOWN_PRODUCT = 'unknown';
    private LoggerInterface $logger;
    private MatomoConfigService $matomoConfigService;
    private RequestStack $requestStack;
    private EntityRepository $categoryRepository;
    private CategoryHandlerService $categoryHandlerService;
    private TrackingDataCollectionService $trackingDataCollection;

    public function __construct(
        LoggerInterface $logger,
        MatomoConfigService $matomoConfigService,
        RequestStack $requestStack,
        EntityRepository $categoryRepository,
        CategoryHandlerService $categoryHandlerService,
        TrackingDataCollectionService $trackingDataCollection,
    ) {
        $this->logger = $logger;
        $this->matomoConfigService = $matomoConfigService;
        $this->requestStack = $requestStack;
        $this->categoryRepository = $categoryRepository;
        $this->categoryHandlerService = $categoryHandlerService;
        $this->trackingDataCollection = $trackingDataCollection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded'
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        try {
            $this->logger->debug('MatomoServerTagManager: MatomoProductDataTracking: onProductPageLoaded: START');
            $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
            if (!$this->matomoConfigService->isMatomoTrackingEnabled($salesChannelId)) {
                $this->logger->debug('MatomoServerTagManager: MatomoProductDataTracking: onProductPageLoaded: MatomoEventTrackingDisabled');
                return;
            }
            $request = $this->requestStack->getCurrentRequest();
            if (!$request) {
                return;
            }

            $activeRuleId = $event->getSalesChannelContext()->getRuleIds();
            $product = $event->getPage()->getProduct();

            $categoryName = $this->getSessionCategoryName($product);
            if (empty($categoryName)) {
                $categoryName = $this->getProductCategoryName($request->get('navigationId'), $event->getSalesChannelContext()->getContext());
            }
            $this->categoryHandlerService->setCategoryProductView($product->getProductNumber() ?? self::UNKNOWN_PRODUCT, $categoryName);
            $productPrice = $this->getProductPrice($product, $activeRuleId);
            $productName = $this->getProductName($product);

            $trackingData = $this->trackingDataCollection->getTrackingData();
            if ($trackingData !== null) {
                $this->trackingDataCollection->addTrackingData('eventCategory', 'product');
                $this->trackingDataCollection->addTrackingData('eventAction', 'view');
                $this->trackingDataCollection->addTrackingData('eventName', $this->trackingDataCollection->getTransformedValue($productName));
                $this->trackingDataCollection->addTrackingData('commerceCategoryName', $categoryName);
                $this->trackingDataCollection->addTrackingData('commerceProductPrice', $productPrice);
                $this->trackingDataCollection->addTrackingData('commerceProductNumber', $product->getProductNumber() ?? self::UNKNOWN_PRODUCT);
                $this->trackingDataCollection->addTrackingData('commerceProductName', $productName);
            }
        } catch (Exception $e) {
            $this->logger->error('MatomoServerTagManager: MatomoProductDataTracking: onProductPageLoaded: Error: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    private function getProductCategoryName($navigationId, $context): string
    {
        try {
            if (!$navigationId) {
                return self::UNKNOWN_PRODUCT;
            }
            $criteria = new Criteria([$navigationId]);
            $criteria->addAssociation('breadcrumb');
            $criteria->setLimit(1);
            $category = $this->categoryRepository->search($criteria, $context)->first();
            $breadcrumb = $category ? $this->getCategoryBreadcrumb($category) : null;
            if ($breadcrumb) {
                return $breadcrumb;
            }
            return self::UNKNOWN_PRODUCT;
        } catch (Throwable $e) {
            $this->logger->warning('MatomoServerTagManager: MatomoProductDataTracking: getProductCategoryName: Error: ', [
                'navigationId' => $navigationId,
                'error' => $e->getMessage()
            ]);
            return self::UNKNOWN_PRODUCT;
        }
    }

    private function getSessionCategoryName(SalesChannelProductEntity $product): ?string
    {
        try {
            $currentParentId = $product->getParentId();
            $lastParentId = $this->categoryHandlerService->getLastParentId();
            $categoryName = $this->categoryHandlerService->getCategoryViewName();

            if ($lastParentId !== null && $lastParentId !== $currentParentId) {
                $this->categoryHandlerService->clearCategoryViewName();
                $this->categoryHandlerService->setLastParentId($currentParentId);
                return null;
            }
            if ($lastParentId === null) {
                $this->categoryHandlerService->setLastParentId($currentParentId);
            }
            return $categoryName;
        } catch (Throwable $e) {
            $this->logger->warning('MatomoServerTagManager: MatomoProductDataTrackerService: getSessionCategoryName: Error', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getCategoryBreadcrumb(?CategoryEntity $category): ?string
    {
        try {
            if (!$category || !$category->getBreadcrumb()) {
                return null;
            }
            $breadcrumbArray = $category->getBreadcrumb();
            if (empty($breadcrumbArray)) {
                $this->logger->debug('MatomoServerTagManager: MatomoProductDataTracking: getCategoryBreadcrumb: noBreadcrumb: ', ['categoryId' => $category->getId()]);
                return null;
            }

            $lastEntry = end($breadcrumbArray);
            if (!is_string($lastEntry) || $lastEntry === '') {
                $this->logger->debug('MatomoServerTagManager: MatomoProductDataTracking: getCategoryBreadcrumb: errorBreadcrumb: ', ['entry' => $lastEntry]);
                return null;
            }
            return $lastEntry;
        } catch (Throwable $e) {
            $this->logger->warning('MatomoServerTagManager: MatomoProductDataTracking: getCategoryBreadcrumb: Error:', [
                'categoryId' => $category->getId(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getProductPrice(SalesChannelProductEntity $product, array $activeRuleIds): float
    {
        try {
            $priceRules = $product->getPrices();
            if ($priceRules && $priceRules->count() > 0) {
                $activeRuleIdSet = array_flip($activeRuleIds);

                foreach ($priceRules as $priceRule) {
                    $ruleId = $priceRule->getRuleId();
                    if (isset($activeRuleIdSet[$ruleId])) {
                        $price = $priceRule->getPrice()?->first()?->getGross() ?? 0;
                        return (float) number_format($price, 2, '.', '');
                    }
                }
            }
            $unitPrice = $product->getCalculatedPrice()?->getUnitPrice() ?? 0;
            return (float) number_format($unitPrice, 2, '.', '');
        } catch (Throwable $e) {
            $this->logger->warning('MatomoServerTagManager: MatomoProductDataTracking: getProductPrice: Error:', ['error' => $e->getMessage()]);
            return 0.00;
        }
    }

    private function getProductName(SalesChannelProductEntity $product): string
    {
        $productName = $product->getTranslation('name');
        if (empty($productName)) {
            $productName = $product->getName();
        }
        return $productName ?? self::UNKNOWN_PRODUCT;
    }

}