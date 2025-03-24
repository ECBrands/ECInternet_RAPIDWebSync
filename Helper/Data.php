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
use ECInternet\RAPIDWebSync\Logger\Logger;

/**
 * Helper
 */
class Data extends AbstractHelper
{
    const CONFIG_PATH_ENABLE_SPEED_LOGGING              = 'rapid_web_sync/general/speed_logging';

    const CONFIG_PATH_GENERATE_CATALOG_PRODUCT_REWRITES = 'catalog/seo/generate_category_product_rewrites';

    const COMMUNITY_EDITION_VALUE                       = 'Community';

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    protected $_logger;

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    private $_productMetadata;

    /**
     * Data constructor.
     *
     * @param \Magento\Framework\App\Helper\Context           $context
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetadata
     * @param \ECInternet\RAPIDWebSync\Logger\Logger          $logger
     */
    public function __construct(
        Context $context,
        ProductMetadataInterface $productMetadata,
        Logger $logger
    ) {
        parent::__construct($context);

        $this->_productMetadata = $productMetadata;
        $this->_logger          = $logger;
    }

    /**
     * Is speed logging enabled?
     *
     * @return bool
     */
    public function isSpeedLoggingEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_ENABLE_SPEED_LOGGING);
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

    /**
     * Log a speed test
     *
     * @param float  $start
     * @param float  $end
     * @param string $function
     */
    public function logSpeedTest(float $start, float $end, string $function)
    {
        if ($this->isSpeedLoggingEnabled()) {
            $elapsedTime = $end - $start;

            $this->log('--- SPEED TEST ---');
            $this->log("| Process [$function]");
            $this->log("| Elapsed time: [$elapsedTime seconds]");
            $this->log('--- SPEED TEST ---' . PHP_EOL);
        }
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
    public function arrayToCommaSeparatedValueString($array)
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

        $arrayCount = count($array);
        for ($i = 0; $i < $arrayCount; $i++) {
            $array[$i] = trim($array[$i]);
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
        return $this->_productMetadata->getEdition();
    }

    /**
     * Get Product version
     *
     * @return string
     */
    public function getMagentoVersion()
    {
        return $this->_productMetadata->getVersion();
    }

    /**
     * @return bool
     */
    public function isVersionCommunity()
    {
        return $this->getMagentoEdition() === self::COMMUNITY_EDITION_VALUE;
    }

    /**
     * @return string
     */
    public function getProductIdColumn()
    {
        return $this->isVersionCommunity() ? 'entity_id' : 'row_id';
    }

    /**
     * Write to extension log
     *
     * @param string $message
     * @param array  $extra
     *
     * @return void
     */
    private function log(string $message, array $extra = [])
    {
        $this->_logger->info('Helper/Data - ' . $message, $extra);
    }
}
