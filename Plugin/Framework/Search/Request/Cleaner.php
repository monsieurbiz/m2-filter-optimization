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

namespace Mbiz\FilterOptimization\Plugin\Framework\Search\Request;

use Magento\Framework\Search\Request\Cleaner as FrameworkCleaner;
use Magento\Framework\App\Cache\Type\Config;
use Magento\Framework\Registry;
use Mbiz\FilterOptimization\Plugin\Model\Layer\FilterList;

class Cleaner
{
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
     * @var bool
     */
    protected $ignoreCall = true;

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
     * @param FrameworkCleaner $subject
     * @param $requestData
     * @return array
     */
    public function afterClean(FrameworkCleaner $subject, $requestData)
    {
        // Check we are on a category view page (ignore catalogsearch)
        $category = $this->registry->registry('current_category');
        if (!$category) {
            return $requestData;
        }

        // If cache not saved, preserve all data (Else Exception "Bucket does not exist")
        $availableProductsAttributes = $this->_getAvailableProductAttributes();
        if (!$availableProductsAttributes) {
            return $requestData;
        }

        // This first step unset attributes which are not in cache, cache is the list of product attributes of the category
        foreach ($requestData['aggregations'] as $key => $aggregation) {
            if (!in_array($aggregation['field'], $availableProductsAttributes)) {
                unset($requestData['aggregations'][$key]);
            }
        }

        // If registry not saved, preserve all data (Else Exception "Bucket does not exist")
        $availableFilters = $this->_getAvailableFilters();
        if (!$availableFilters) {
            return $requestData;
        }

        // This second step unset attributes which have no filter value, is specific foreach store

        // Build an array with attribute keys
        $availableAttributes = [];
        foreach ($availableFilters as $availableFilter) {
            $availableAttributes[] = $availableFilter['filter_key'];
        }

        // Unset not used attributes
        foreach ($requestData['aggregations'] as $key => $aggregation) {
            if (!in_array($aggregation['field'], $availableAttributes)) {
                unset($requestData['aggregations'][$key]);
            }
        }

        return $requestData;
    }

    /**
     * Get available filters in registry
     *
     * @see FilterList
     * @return array | false
     */
    protected function _getAvailableFilters()
    {
        if ($this->registry->registry(FilterList::AVAILABLE_FILTERS_REGISTRY) === null) {
            return $this->registry->registry(FilterList::AVAILABLE_FILTERS_REGISTRY);
        }

        return false;
    }

    /**
     * Get available product attributes in cache
     *
     * @see FilterList
     * @return array | false
     */
    protected function _getAvailableProductAttributes()
    {
        $this->cacheKey = $this->registry->registry(FilterList::AVAILABLE_FILTERS_CACHE_KEY);
        if (!$this->cacheKey) {
            return false;
        }

        $availableProductsAttributes = $this->config->load($this->cacheKey);

        // Value in cache
        if ($availableProductsAttributes !== false) {
            return unserialize($availableProductsAttributes);
        }

        return false;
    }
}
