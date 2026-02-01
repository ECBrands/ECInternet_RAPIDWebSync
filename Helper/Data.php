<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadataInterface;

/**
 * Helper
 */
class Data extends AbstractHelper
{
    private const CONFIG_PATH_GENERATE_CATALOG_PRODUCT_REWRITES = 'catalog/seo/generate_category_product_rewrites';

    /**
     * @var \ECInternet\RAPIDWebSync\Model\Magento\Environment
     */
    private $magentoEnvironment;

    /**
     * Data constructor.
     *
     * @param \Magento\Framework\App\Helper\Context              $context
     * @param \ECInternet\RAPIDWebSync\Model\Magento\Environment $magentoEnvironment
     */
    public function __construct(
        Context $context,
        \ECInternet\RAPIDWebSync\Model\Magento\Environment $magentoEnvironment
    ) {
        $this->magentoEnvironment = $magentoEnvironment;

        parent::__construct($context);
    }

    /**
     * Should we generated rewrites?
     *
     * @return bool
     * @since 2.3.3
     */
    public function shouldGenerateCatalogProductRewrites()
    {
        if (version_compare($this->getMagentoVersion(), '2.3.3', '>=')) {
            return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_GENERATE_CATALOG_PRODUCT_REWRITES);
        }

        return true;
    }

    public function isProductConfigurable(array $product)
    {
        return isset($product['type_id']) && $product['type_id'] === 'configurable';
    }

    //////////////////////////////////////////////////
    ///
    /// STRING / ARRAY FUNCTIONS
    ///
    //////////////////////////////////////////////////

    /**
     * Transforms a 1-d array into a comma-separated list of single-quote(')-wrapped values.
     *
     * @param array $values
     *
     * @return string
     */
    public function arrayToCommaSeparatedValues(array $values)
    {
        $array = [];

        foreach ($values as $value) {
            $array[] = "'$value'";
        }

        return implode(',', $array);
    }

    /**
     * Transform a 2-d array into a comma-separated list of update prepared placeholders.
     * "arr2update"
     *
     * @param array $updateArray
     *
     * @return string
     */
    public function arrayToCommaSeparatedUpdateString(array $updateArray)
    {
        $array = [];

        foreach ($updateArray as $updateKey => $updateValue) {
            $array[] = "$updateKey=?";
        }

        return implode(',', $array);
    }

    /**
     * Transforms a 1-d array into a comma-separated list of unnamed placeholders.
     * "arr2values"
     *
     * @param array $array
     *
     * @return string
     */
    public function arrayToCommaSeparatedValueString(array $array)
    {
        return substr(str_repeat('?,', count($array)), 0, -1);
    }

    /**
     * Transforms a comma-separated list to a 1-d array of trimmed values.
     * "csl2arr"
     *
     * @param string $list
     * @param string $separator
     *
     * @return string[]
     */
    public function commaSeparatedListToTrimmedArray(string $list, string $separator = ',')
    {
        $array = explode($separator, $list);

        foreach ($array as $i => $value) {
            $array[$i] = trim($value);
        }

        return $array;
    }

    /**
     * Filters a key value array over a list of keys.
     *
     * Replaces __NULL__ magic value with true null
     *
     * @param array    $keyValueArray
     * @param string[] $keys
     *
     * @return array
     */
    public function filterKeyValueArray(array $keyValueArray, array $keys)
    {
        $out = [];

        // Iterate over keys.
        // If key exists in our array, and it's not '__NULL__', include it.
        foreach ($keys as $key) {
            if (isset($keyValueArray[$key]) && $keyValueArray[$key] !== '__NULL__') {
                $out[$key] = $keyValueArray[$key];
            } else {
                $out[$key] = null;
            }
        }

        return $out;
    }

    /**
     * Build url slug from string
     *
     * @param string $string
     * @param bool   $allowSlash
     *
     * @return string
     * @noinspection PhpUnnecessaryLocalVariableInspection
     */
    public function slug(string $string, bool $allowSlash = false)
    {
        $regex = $allowSlash ? '[^a-z0-9-/]' : '[^a-z0-9-]';

        $string = strtolower(trim($string));
        $string = preg_replace("|$regex|", '-', $string);
        $string = preg_replace('|-+|', '-', $string);
        $string = preg_replace('|-$|', '', $string);

        return $string;
    }

    /**
     * Get Product edition
     *
     * @return string
     */
    public function getMagentoEdition()
    {
        return $this->magentoEnvironment->getMagentoEdition();
    }

    /**
     * Get Product version
     *
     * @return string
     */
    public function getMagentoVersion()
    {
        return $this->magentoEnvironment->getMagentoVersion();
    }

    /**
     * @return string
     */
    public function getProductIdColumn()
    {
        return $this->magentoEnvironment->getProductIdColumn();
    }
}
