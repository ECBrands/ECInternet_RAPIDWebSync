<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */

/** @noinspection PhpFullyQualifiedNameUsageInspection */
/** @noinspection PhpStatementHasEmptyBodyInspection */
/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnusedPrivateMethodInspection */

declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Test\Integration\Helper;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Module\FullModuleList;
use Magento\TestFramework\Helper\Bootstrap;
use ECInternet\RAPIDWebSync\Helper\Import;

/**
 * @magentoDbIsolation disabled
 */
class ImportTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Import
     */
    private $_import;

    /**
     * @var \Magento\Catalog\Api\CategoryListInterface
     */
    private $_categoryList;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $_productRepository;

    /**
     * @var \Magento\Catalog\Helper\Product
     */
    private $_productHelper;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    private $_searchCriteriaBuilder;

    /**
     * @var \Magento\Framework\Module\FullModuleList
     */
    private $_fullModuleList;

    private $_refreshDb = false;

    protected function setUp(): void
    {
        // Load classes from here
        $objectManager = Bootstrap::getObjectManager();

        // Test class
        $this->_import = $objectManager->get(Import::class);

        // Other classes
        $this->_categoryList          = $objectManager->get(CategoryListInterface::class);
        $this->_productRepository     = $objectManager->get(ProductRepositoryInterface::class);
        $this->_productHelper         = $objectManager->get(ProductHelper::class);
        $this->_fullModuleList        = $objectManager->get(FullModuleList::class);
        $this->_searchCriteriaBuilder = $objectManager->get(SearchCriteriaBuilder::class);
    }

    public function testAddProduct()
    {
        $sku = 'TestProduct';

        $data = [
            "sku"              => $sku,
            "attribute_set_id" => 4,
            "name"             => 'Test Product',
            "weight"           => "1",
            "status"           => "1",
            "price"            => "61.99",
            "visibility"       => "4",
            'url_key'          => 'test_product',
            "allow_on_web"     => "1",
            "categories"       => "Home Office;;Home Office|Test Products;;Home Office|Test Products|New Products"
        ];

        // Add product
        $response = $this->_import->addProduct($data);

        // Confirm valid response
        $this->assertIsArray($response);
        fwrite(STDERR, 'Response:' . print_r($response, true));
        $this->assertArrayHasKey('sku', $response);
        $this->assertEquals($sku, $response['sku']);
        $this->assertArrayHasKey('new', $response);
        if ($this->_refreshDb) {
            $this->assertEquals(true, $response['new']);
        }
        $this->assertArrayHasKey('id', $response);
        if ($this->_refreshDb) {
            //$this->assertEquals(1, $response['id']);
        }
        $this->assertArrayNotHasKey('error', $response);

        // Confirm product added
        /** @var \Magento\Catalog\Api\Data\ProductInterface $product */
        $product = $this->_productRepository->get($sku);
        $this->assertEquals($sku, $product->getSku());
        $this->assertEquals(4, $product->getAttributeSetId());
        $this->assertEquals('Test Product', $product->getName());
        $this->assertEquals(1, $product->getWeight());
        $this->assertEquals(1, $product->getStatus());
        $this->assertEquals(61.99, $product->getPrice());
        $this->assertEquals(4, $product->getVisibility());
        $this->assertInstanceOf(\Magento\Catalog\Model\Product::class, $product);
        /** @var \Magento\Catalog\Model\Product $product */
        $this->assertEquals('test-product', $product->getUrlKey());

        // Url Rewrite (product base)
        $productUrl = $this->_productHelper->getProductUrl($product);
        $urlEndpoint = $this->getUrlEndpoint($productUrl);
        // TODO: Find method for getting everything before
        $this->assertEquals('test-product.html', $urlEndpoint, "Incorrect product url.  Expected 'test-product.html', got '$urlEndpoint'");

        // Cache custom attributes
        $allowOnWeb = $product->getCustomAttribute('allow_on_web');

        // Process custom attributes
        $this->assertNotNull($allowOnWeb);
        $this->assertEquals(1, $allowOnWeb->getValue());
    }

    /**
     * Is extension currently installed?
     *
     * @param string $extensionName
     *
     * @return bool
     */
    private function isExtensionInstalled($extensionName)
    {
        return (bool)array_search($extensionName, $this->_fullModuleList->getNames());
    }

    private function getUrlEndpoint($productUrl)
    {
        return substr($productUrl, strrpos($productUrl, '/') + 1);
    }

    /**
     * @param string $categoryName
     *
     * @return \Magento\Catalog\Api\Data\CategoryInterface[]
     */
    private function getCategoriesByName($categoryName)
    {
        $this->_searchCriteriaBuilder->addFilter('name', $categoryName);
        $searchCriteria = $this->_searchCriteriaBuilder->create();

        return $this->_categoryList->getList($searchCriteria)->getItems();
    }
}
