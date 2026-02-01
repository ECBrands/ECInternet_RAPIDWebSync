<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use ECInternet\RAPIDWebSync\Model\Magento\Environment;

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
        Environment $magentoEnvironment
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
        if (version_compare($this->magentoEnvironment->getMagentoVersion(), '2.3.3', '>=')) {
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
     * @return string
     */
    public function getProductIdColumn()
    {
        return $this->magentoEnvironment->getProductIdColumn();
    }
}
