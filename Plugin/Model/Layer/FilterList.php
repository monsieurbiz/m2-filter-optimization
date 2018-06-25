<?php
/**
 * This file is part of Mbiz_FilterOptimization for Magento.
 *
 * @license All rights reserved
 * @author Maxime Huran <m.huran@monsieurbiz.com> <@MaximeHuran>
 * @category Mbiz
 * @package Mbiz_FilterOptimization
 * @copyright Copyright (c) 2018 Monsieur Biz (https://monsieurbiz.com/)
 */

namespace Mbiz\FilterOptimization\Plugin\Model\Layer;

use Magento\Catalog\Model\Layer\FilterList as CatalogFilterList;
use Magento\CatalogSearch\Model\Layer\Filter\Attribute as AttributeFilter;
use Magento\CatalogSearch\Model\Layer\Filter\Category as CategoryFilter;
use Magento\CatalogSearch\Model\Layer\Filter\Price as PriceFilter;
use Magento\Framework\App\Cache\Type\Config;
use Magento\Framework\Registry;

class FilterList
{
    const PREFIX_CACHE_LAYER_FILTER = 'FILTERS_';
    const AVAILABLE_FILTERS_CACHE_KEY = 'available_filters_cache_key';
    const AVAILABLE_FILTERS_REGISTRY = 'available_filters_registry';
    const PRICE_FILTER_KEY = 'price';
    const CATEGORY_FILTER_KEY = 'category_ids';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var string
     */
    protected $cacheKey = null;

    /**
     * @param Config $config
     * @param Registry $registry
     */
    public function __construct(Config $config, Registry $registry)
    {
        $this->config = $config;
        $this->registry = $registry;
    }

    /**
     * Remove filters without products (Get/put in cache for better performance)
     *
     * @param CatalogFilterList $subject
     * @param $filters
     * @return array
     */
    public function afterGetFilters(CatalogFilterList $subject, $filters)
    {
        // Check we are on a category view page (ignore catalogsearch)
        $category = $this->registry->registry('current_category');
        if (!$category) {
            return $filters;
        }

        // Check if no filter
        if (empty($filters)) {
            return $filters;
        }

        // Get the cache key
        foreach ($filters as $key => $filter) {
            $this->cacheKey = self::PREFIX_CACHE_LAYER_FILTER . $filter->getLayer()->getStateKey();
            break;
        }

        // Be sure we have a cache key
        if (empty($this->cacheKey)) {
            return $filters;
        }

        // Save cache key in registry
        if ($this->registry->registry(self::AVAILABLE_FILTERS_CACHE_KEY) === null) {
            $this->registry->register(self::AVAILABLE_FILTERS_CACHE_KEY, $this->cacheKey);
        }

        // Keep only available filters
        $availableFilters = $this->_getAvailableFilters($filters);
        foreach ($filters as $key => $filter) {
            if (!isset($availableFilters[$key])) {
                unset($filters[$key]);
            }
        }

        return $filters;
    }

    /**
     * Get available filters in cache if exists to avoid multiple SQL requests, else perform SQL requests
     *
     * @param $filters
     * @return array
     */
    protected function _getAvailableFilters($filters)
    {
        $availableProductsAttributes = $this->_getAvailableProductAttributes();

        $availableFilters = [];
        foreach ($filters as $key => $filter) {
            // Determine filter key depending on attribute type
            if ($filter instanceof CategoryFilter) {
                $filterKey = self::CATEGORY_FILTER_KEY;
            } elseif ($filter instanceof PriceFilter) {
                $filterKey = self::PRICE_FILTER_KEY;
            } elseif ($filter instanceof AttributeFilter) {
                $filterKey = $filter->getAttributeModel()->getAttributeCode();
            } else {
                // Ignore unknown type
                continue;
            }

            // Available attributes in product list (common for all stores)
            if (!in_array($filterKey, $availableProductsAttributes)) {
                continue;
            }

            // Attribute available, we stock it
            $availableFilters[$key] = ['filter_key' => $filterKey];
        }

        // Save in registry
        if ($this->registry->registry(self::AVAILABLE_FILTERS_REGISTRY) === null) {
            $this->registry->register(self::AVAILABLE_FILTERS_REGISTRY, $availableFilters);
        }

        return $availableFilters;
    }

    /**
     * Get available attributes depending on current category's product list in cache if exists
     *
     * @return array
     */
    protected function _getAvailableProductAttributes()
    {
        $availableProductsAttributes = $this->config->load($this->cacheKey);

        // Value in cache
        if ($availableProductsAttributes !== false) {
            return unserialize($availableProductsAttributes);
        }

        // Get attributes
        $availableProductsAttributes = [self::PRICE_FILTER_KEY, self::CATEGORY_FILTER_KEY];
        $availableProductsAttributes = array_merge(
            $availableProductsAttributes,
            $this->_getAvailableProductAttributesInSql()
        );

        // Save attributes in config cache
        $this->config->save(serialize($availableProductsAttributes), $this->cacheKey);

        return $availableProductsAttributes;
    }

    /**
     * Get available attributes depending on current category's product list with SQL request
     *
     * @return array
     */
    protected function _getAvailableProductAttributesInSql()
    {
        $attributeSetIds = [];
        $category = $this->registry->registry('current_category');

        foreach ($category->getProductCollection() as $product) {
            $attributeSetId = $product->getAttributeSetId();
            if (!in_array($attributeSetId, $attributeSetIds) && !empty($attributeSetId)) {
                $attributeSetIds[] = $attributeSetId;
            }
        }

        /** @var \Magento\Framework\DB\Adapter\AdapterInterface $connection */
        $connection = $category->getResource()->getConnection();
        $select = $connection->select()
            ->from(['eav_entity_attribute' => $connection->getTableName('eav_entity_attribute')])
            ->where('eav_entity_attribute.entity_type_id = ?', 4)
            ->where('eav_entity_attribute.attribute_set_id IN (?)', $attributeSetIds)
            ->joinLeft(['eav_attribute' => $connection->getTableName('eav_attribute')],
                'eav_entity_attribute.attribute_id = eav_attribute.attribute_id'
            )
            ->reset(\Zend_Db_Select::COLUMNS)
            ->columns('eav_attribute.attribute_code')
        ;

        $availableProductsAttributes = [];
        $query = $connection->query($select);
        while ($item = $query->fetch()) {
            if (isset($item['attribute_code'])) {
                $availableProductsAttributes[] = $item['attribute_code'];
            }
        }

        return $availableProductsAttributes;
    }

}
