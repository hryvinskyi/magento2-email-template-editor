<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\SampleData;

use Hryvinskyi\EmailTemplateEditor\Api\MockVariableBuilderPoolInterface;
use Hryvinskyi\EmailTemplateEditor\Api\SampleDataProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateSampleDataMapperInterface;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address\Renderer as AddressRenderer;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Psr\Log\LoggerInterface;

class SpecificOrderProvider implements SampleDataProviderInterface
{
    /**
     * Shortest query the search will run; anything shorter is answered with an empty result
     *
     * A one-character term matches most of the orders table and is the most expensive thing this
     * search can be asked for, so it is refused before a collection is even created. The admin UI
     * enforces the same minimum before it sends the request.
     */
    private const MIN_QUERY_LENGTH = 2;

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param AddressRenderer $addressRenderer
     * @param PaymentHelper $paymentHelper
     * @param TemplateSampleDataMapperInterface $templateMapper
     * @param MockVariableBuilderPoolInterface $builderPool
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly AddressRenderer $addressRenderer,
        private readonly PaymentHelper $paymentHelper,
        private readonly TemplateSampleDataMapperInterface $templateMapper,
        private readonly MockVariableBuilderPoolInterface $builderPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Specific Order';
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'specific_order';
    }

    /**
     * @inheritDoc
     */
    public function supportsEntitySearch(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function searchEntities(string $query, int $storeId, int $limit = 10): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $results = [];

        try {
            $term = $this->escapeLikeWildcards($query);

            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToSelect(['entity_id', 'increment_id', 'customer_firstname', 'customer_lastname', 'customer_email', 'grand_total', 'order_currency_code']);

            if ($storeId) {
                $collection->addFieldToFilter('store_id', $storeId);
            }

            if ($this->isOrderNumberQuery($query)) {
                // A single trailing-wildcard condition on increment_id is a range scan on the
                // (increment_id, store_id) unique key. OR-ing anything else in here would put a
                // leading-wildcard branch back into the statement and force a scan of the table.
                $collection->addFieldToFilter('increment_id', ['like' => $term . '%']);
            } else {
                // No index covers these columns on sales_order, so this branch stays a scan of
                // the primary key walked backwards; the ordering and page size below are what
                // bound it.
                $collection->addFieldToFilter(
                    ['customer_email', 'customer_firstname', 'customer_lastname'],
                    [
                        ['like' => '%' . $term . '%'],
                        ['like' => '%' . $term . '%'],
                        ['like' => '%' . $term . '%'],
                    ]
                );
            }

            $collection->setOrder('entity_id', 'DESC');
            $collection->setPageSize($limit);

            foreach ($collection as $order) {
                $results[] = [
                    'id' => (string)$order->getEntityId(),
                    'label' => sprintf(
                        '#%s - %s %s - %s %s',
                        $order->getIncrementId(),
                        $order->getCustomerFirstname() ?? 'Guest',
                        $order->getCustomerLastname() ?? '',
                        $order->getOrderCurrencyCode(),
                        number_format((float)$order->getGrandTotal(), 2)
                    ),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to search orders: ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Check whether the query is shaped like an order number rather than free text
     *
     * Order increment ids are a sequence prefix followed by a zero-padded counter, and the prefix
     * Magento assigns is the numeric store id, so an order number is digits with at most a
     * separator between them. Requiring a leading digit and nothing but digits and separators
     * keeps names, e-mail addresses and anything else containing a letter out of this branch.
     *
     * @param string $query
     * @return bool
     */
    private function isOrderNumberQuery(string $query): bool
    {
        return preg_match('/^\d[\d\-\/]*$/', $query) === 1;
    }

    /**
     * Neutralise LIKE wildcards the user typed as ordinary characters
     *
     * A query of "%" would otherwise match every row. The value is still bound and quoted by the
     * adapter, so this is about the pattern the user asked for, not about injection.
     *
     * @param string $query
     * @return string
     */
    private function escapeLikeWildcards(string $query): string
    {
        return addcslashes($query, '\\%_');
    }

    /**
     * @inheritDoc
     */
    public function getVariables(string $templateIdentifier, int $storeId, ?string $entityId = null): array
    {
        $category = $this->templateMapper->getCategory($templateIdentifier);

        if (empty($entityId)) {
            return $this->builderPool->getBuilder($category)->build($templateIdentifier, $storeId);
        }

        try {
            $order = $this->orderRepository->get((int)$entityId);
            $store = $this->storeManager->getStore($order->getStoreId());

            $formattedShippingAddress = '';
            if ($order->getShippingAddress()) {
                $formattedShippingAddress = $this->addressRenderer->format($order->getShippingAddress(), 'html');
            }

            $formattedBillingAddress = '';
            if ($order->getBillingAddress()) {
                $formattedBillingAddress = $this->addressRenderer->format($order->getBillingAddress(), 'html');
            }

            $paymentHtml = '';
            try {
                $paymentHtml = $this->paymentHelper->getInfoBlockHtml(
                    $order->getPayment(),
                    $order->getStoreId()
                );
            } catch (\Exception) {
                $paymentHtml = $order->getPayment() ? $order->getPayment()->getMethod() : '';
            }

            $variables = [
                'order' => $order,
                'order_id' => $order->getIncrementId(),
                'order_data' => [
                    'customer_name' => $order->getCustomerName(),
                    'is_not_virtual' => !$order->getIsVirtual(),
                    'email_customer_note' => $order->getEmailCustomerNote() ?? '',
                    'frontend_status_label' => $order->getFrontendStatusLabel(),
                ],
                'billing' => $order->getBillingAddress(),
                'payment_html' => $paymentHtml,
                'store' => $store,
                'store_name' => $store->getName(),
                'store_url' => $store->getBaseUrl(),
                'formattedShippingAddress' => $formattedShippingAddress,
                'formattedBillingAddress' => $formattedBillingAddress,
                'order_area' => 'frontend',
                'customer_name' => $order->getCustomerName(),
                'customer' => new DataObject([
                    'name' => $order->getCustomerName(),
                    'firstname' => $order->getCustomerFirstname(),
                    'lastname' => $order->getCustomerLastname(),
                    'email' => $order->getCustomerEmail(),
                ]),
            ];

            $categoryExtras = $this->getCategoryExtras($category);

            return array_merge($variables, $categoryExtras);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load specific order sample data: ' . $e->getMessage());

            return $this->builderPool->getBuilder($category)->build($templateIdentifier, $storeId);
        }
    }

    /**
     * Get additional variables specific to the template category
     *
     * @param string $category
     * @return array<string, mixed>
     */
    private function getCategoryExtras(string $category): array
    {
        return match ($category) {
            'order_comment', 'invoice_comment', 'shipment_comment', 'creditmemo_comment' => [
                'comment' => 'Your order has been updated. Please review the details below.',
            ],
            'invoice', 'invoice_comment' => [
                'invoice' => new DataObject([
                    'increment_id' => '100000001',
                    'created_at' => date('M d, Y'),
                ]),
                'comment' => $category === 'invoice_comment'
                    ? 'Your order has been updated. Please review the details below.'
                    : null,
            ],
            'shipment', 'shipment_comment' => [
                'shipment' => new DataObject([
                    'increment_id' => '100000001',
                    'created_at' => date('M d, Y'),
                ]),
                'comment' => $category === 'shipment_comment'
                    ? 'Your order has been updated. Please review the details below.'
                    : null,
            ],
            'creditmemo', 'creditmemo_comment' => [
                'creditmemo' => new DataObject([
                    'increment_id' => '100000001',
                    'created_at' => date('M d, Y'),
                ]),
                'comment' => $category === 'creditmemo_comment'
                    ? 'Your order has been updated. Please review the details below.'
                    : null,
            ],
            default => [],
        };
    }
}
