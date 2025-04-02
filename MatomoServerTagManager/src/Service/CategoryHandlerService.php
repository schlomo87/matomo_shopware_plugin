<?php

namespace SwClp\MatomoServerTagManager\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

readonly class CategoryHandlerService
{
    public function __construct(
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {}

    public function setCategoryViewName(?string $categoryName): void
    {
        $this->logger->debug('MatomoServerTagManager: CategoryHandlerService: setCategoryViewName: START');
        $session = $this->requestStack->getSession();

        if ($categoryName === null) {
            $session->remove('matomo_category_view_name');
            return;
        }
        $session->set('matomo_category_view_name', $categoryName);
    }

    public function getCategoryViewName(): ?string
    {
        $session = $this->requestStack->getSession();
        return $session->get('matomo_category_view_name');
    }

    public function clearCategoryViewName(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove('matomo_category_view_name');
    }

    public function setLastParentId(?string $lastParentId): void
    {
        $session = $this->requestStack->getSession();
        if ($lastParentId === null) {
            $session->remove('matomo_last_parent_id');
            return;
        }
        $session->set('matomo_last_parent_id', $lastParentId);
    }

    public function getLastParentId(): ?string
    {
        $session = $this->requestStack->getSession();
        return $session->get('matomo_last_parent_id');
    }

    public function clearLastParentId(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove('matomo_last_parent_id');
    }

    public function setCategoryProductView(string $productNumber, string $category): void
    {
        $session = $this->requestStack->getSession();
        $productItems = $session->get('matomo_product_items', []);

        $productItems[$productNumber] = [
            'category' => $category,
            'timestamp' => time()
        ];

        $session->set('matomo_product_items', $productItems);
    }

    public function getCategoryProductView(): array
    {
        $session = $this->requestStack->getSession();
        return $session->get('matomo_product_items', []);
    }

    public function clearCartItems(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove('matomo_product_items');
    }

}