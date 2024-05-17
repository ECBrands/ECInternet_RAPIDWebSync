<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Controller\Adminhtml\Export;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Images;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableProductType;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\View\Result\PageFactory;
use ECInternet\RAPIDWebSync\Helper\Configurable as ConfigurableHelper;
use ECInternet\RAPIDWebSync\Helper\Data;
use ECInternet\RAPIDWebSync\Helper\Link;
use ECInternet\RAPIDWebSync\Logger\Logger;
use Exception;

/**
 * Adminhtml Export Download Controller
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Download extends Action implements HttpGetActionInterface
{
    const FILE_NAME = 'ProductExport.csv';

    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $_resultPageFactory;

    /**
     * @var \Magento\Catalog\Api\CategoryRepositoryInterface
     */
    private $_categoryRepository;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $_productRepository;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory
     */
    private $_productAttributeCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    private $_productCollectionFactory;

    /**
     * @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable
     */
    private $_configurableProductType;

    /**
     * @var \Magento\Framework\App\Response\Http\FileFactory
     */
    private $_fileFactory;

    /**
     * @var \Magento\Framework\Filesystem\Io\File
     */
    private $_file;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    private $_directory;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Data
     */
    private $helper;

    /**
     * @var \ECInternet\RAPIDWebSync\Helper\Link
     */
    private $_link;

    /**
     * @var \ECInternet\RAPIDWebSync\Logger\Logger
     */
    private $_logger;

    /**
     * Index constructor.
     *
     * @param \Magento\Backend\App\Action\Context                                      $context
     * @param \Magento\Framework\View\Result\PageFactory                               $resultPageFactory
     * @param \Magento\Catalog\Api\CategoryRepositoryInterface                         $categoryRepository
     * @param \Magento\Catalog\Api\ProductRepositoryInterface                          $productRepository
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory           $productCollectionFactory
     * @param \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $attributeCollectionFactory
     * @param \Magento\ConfigurableProduct\Model\Product\Type\Configurable             $configurableProductType
     * @param \Magento\Framework\App\Response\Http\FileFactory                         $fileFactory
     * @param \Magento\Framework\Filesystem                                            $filesystem
     * @param \Magento\Framework\Filesystem\Io\File                                    $file
     * @param \ECInternet\RAPIDWebSync\Helper\Data                                     $helper
     * @param \ECInternet\RAPIDWebSync\Helper\Link                                     $link
     * @param \ECInternet\RAPIDWebSync\Logger\Logger                                   $logger
     *
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        CategoryRepositoryInterface $categoryRepository,
        ProductRepositoryInterface $productRepository,
        ProductCollectionFactory $productCollectionFactory,
        ProductAttributeCollectionFactory $attributeCollectionFactory,
        ConfigurableProductType $configurableProductType,
        FileFactory $fileFactory,
        Filesystem $filesystem,
        File $file,
        Data $helper,
        Link $link,
        Logger $logger
    ) {
        parent::__construct($context);

        $this->_resultPageFactory                 = $resultPageFactory;
        $this->_categoryRepository                = $categoryRepository;
        $this->_productRepository                 = $productRepository;
        $this->_productCollectionFactory          = $productCollectionFactory;
        $this->_productAttributeCollectionFactory = $attributeCollectionFactory;
        $this->_configurableProductType           = $configurableProductType;
        $this->_fileFactory                       = $fileFactory;
        $this->_file                              = $file;
        $this->_directory                         = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->helper                             = $helper;
        $this->_link                              = $link;
        $this->_logger                            = $logger;
    }

    /**
     * Build CSV
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $this->log('execute()');

        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->_resultPageFactory->create();

        // Build file path for CSV file
        $filePath = $this->getFilePath();

        try {
            $this->_directory->create('export');
        } catch (FileSystemException $e) {
            $this->log("execute() - Unable to create 'export' folder - {$e->getMessage()}.");
            throw $e;
        }

        // Open and lock file
        /** @var \Magento\Framework\Filesystem\File\WriteInterface $stream */
        $stream = $this->_directory->openFile($filePath, 'w+');
        $stream->lock();

        /** @var string[] $columns */
        $columns = [];

        /** @var \Magento\Eav\Model\Attribute[] $attributes */
        $attributes = [];

        /** @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection $productAttributes */
        $productAttributes = $this->_productAttributeCollectionFactory->create();
        foreach ($productAttributes as $productAttribute) {
            /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $productAttribute */
            if ($this->isAttributeValidForExport($productAttribute)) {
                $columns[]    = $productAttribute->getAttributeCode();
                $attributes[] = $productAttribute;
            }
        }

        // Add special attributes, sort, and add 'sku' and 'product_id' at beginning
        $columns[] = 'type_id';
        $columns[] = 'categories';
        $columns[] = Images::CODE_IMAGE;
        $columns[] = Images::CODE_SMALL_IMAGE;
        $columns[] = Images::CODE_THUMBNAIL;
        $columns[] = Images::CODE_MEDIA_GALLERY;
        $columns[] = 'related_products';
        $columns[] = ConfigurableHelper::CONFIGURABLE_ATTRIBUTES;
        $columns[] = ConfigurableHelper::SIMPLES_SKUS_FIELD;
        $columns[] = 'first_parent_sku';
        sort($columns);
        array_unshift($columns, 'sku', 'product_id');

        // Confirm zzzz_parity_check is in data
        if (!in_array('zzzz_parity_check', $columns)) {
            $columns[] = 'zzzz_parity_check';
        }

        try {
            $stream->writeCsv($columns);
        } catch (FileSystemException $e) {
            $this->log("execute() - Unable to write CSV - {$e->getMessage()}.");
            throw $e;
        }

        /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection $products */
        $products = $this->_productCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addMediaGalleryData();

        $productCount = $products->getSize();
        $i = 0;

        /** @var \Magento\Catalog\Model\Product $product */
        foreach ($products as $product) {
            $i++;

            // Cache sku and product type
            $sku  = $product->getSku();
            $type = $product->getTypeId();

            $this->log('execute()', [
                'index' => $i,
                'total' => $productCount,
                'id'    => $product->getId(),
                'sku'   => $sku,
                'type'  => $type
            ]);

            // Error handling
            if (!$this->isProductTypeValid($product)) {
                $this->log('execute()', ['error' => "invalid product type: $type"]);

                continue;
            }

            // Prevent null/empty SKUs.
            if (empty($sku)) {
                $this->log("execute() - Product ID [{$product->getId()}] has no SKU.");

                continue;
            }

            // Array for holding data returned from getData()
            $productData = [];

            // Iterate over attributes
            foreach ($attributes as $attribute) {
                // Cache for readability
                $attributeCode         = $attribute->getAttributeCode();
                $frontendInput         = $attribute->getFrontendInput();
                $productAttributeValue = $product->getData($attributeCode);

                $this->log('execute()', ['key' => $attributeCode, 'value' => $productAttributeValue]);

                // Handle null
                if ($productAttributeValue === null) {
                    $productData[$attributeCode] = '';
                    continue;
                }

                // Handle 'select'
                if ($frontendInput == 'select') {
                    $optionText = $attribute->getSource()->getOptionText($productAttributeValue);
                    $this->log('execute()', ['optionText' => $optionText]);

                    $productData[$attributeCode] = $optionText;
                    continue;
                }

                // Handle 'multiselect'
                if ($frontendInput == 'multiselect') {
                    $productData[$attributeCode] = $this->implodeMultiselectValues($attribute, $productAttributeValue);
                    continue;
                }

                // Replace newlines with single space
                $attributeValue = trim(preg_replace('/\s\s+/', ' ', $productAttributeValue));

                $productData[$attributeCode] = $attributeValue;
            }

            // Handle 'categories' and 'images' separately
            $productData['type_id']                                   = $product->getTypeId();
            $productData['categories']                                = $this->buildCategoryString($product);
            $productData[Images::CODE_IMAGE]                          = $this->buildImageString($product, Images::CODE_IMAGE);
            $productData[Images::CODE_SMALL_IMAGE]                    = $this->buildImageString($product, Images::CODE_SMALL_IMAGE);
            $productData[Images::CODE_THUMBNAIL]                      = $this->buildImageString($product, Images::CODE_THUMBNAIL);
            $productData[Images::CODE_MEDIA_GALLERY]                  = $this->buildMediaGalleryString($product);
            $productData['related_products']                          = $this->buildRelatedProducts($product);
            $productData[ConfigurableHelper::CONFIGURABLE_ATTRIBUTES] = $this->buildConfigurableAttributesString($product);
            $productData[ConfigurableHelper::SIMPLES_SKUS_FIELD]      = $this->buildSimplesSkusString($product);
            $productData['first_parent_sku']                          = $this->buildFirstParentSku($product);

            // Sort array by key.
            ksort($productData);

            // Push sku data to front
            $productData = ['sku' => $product->getSku(), 'product_id' => $product->getId()] + $productData;

            // Make sure 'zzzz_parity_check' is populated
            $productData['zzzz_parity_check'] = 'FINAL_COLUMN';

            try {
                $stream->writeCsv($productData);
            } catch (FileSystemException $e) {
                $this->log('Unable to writeCSV - ' . $e->getMessage());
                throw $e;
            }
        }

        $content          = [];
        $content['type']  = 'filename';
        $content['value'] = $filePath;
        $content['rm']    = '1';

        try {
            return $this->_fileFactory->create(self::FILE_NAME, $content, DirectoryList::VAR_DIR);
        } catch (Exception $e) {
            $this->log('Unable to create file - ' . $e->getMessage());
        }

        return $resultPage;
    }

    /**
     * Get ProductExport file path
     *
     * @return string
     */
    private function getFilePath()
    {
        return 'export/ProductExport-' . date('m_d_Y_H_i_s') . '.csv';
    }

    private function isProductTypeValid(
        Product $product
    ) {
        return in_array($product->getTypeId(), ['simple', 'configurable']);
    }

    /**
     * Should this attribute be added to the export?
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     *
     * @return bool
     */
    private function isAttributeValidForExport(
        Attribute $attribute
    ) {
        return
            // Static attributes are stored in the main table of an entity
            $attribute->getBackendType() != 'static' &&

            // Must display on frontend and not be image-like
            $attribute->getFrontendInput() &&
            $attribute->getFrontendInput() != 'gallery' &&
            $attribute->getFrontendInput() != 'media_image' &&
            $attribute->getDefaultFrontendLabel() &&

            // Explicitly exclude a few
            $attribute->getAttributeCode() != 'tier_price' &&
            $attribute->getAttributeCode() != 'quantity_and_stock_status';
    }

    /**
     * Implode the multiselect values into a string
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @param string                                             $productAttributeValue
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function implodeMultiselectValues(
        Attribute $attribute,
        $productAttributeValue
    ) {
        $this->log('implodeMultiselectValues()', [
            'attributeCode' => $attribute->getAttributeCode(),
            'value'         => $productAttributeValue
        ]);

        $multiselectValues = [];

        if ($productAttributeValue) {
            $multiselectKeys = explode(',', $productAttributeValue);
            foreach ($multiselectKeys as $multiselectKey) {
                $value = $attribute->getSource()->getOptionText($multiselectKey);
                if ($value != '') {
                    $multiselectValues[] = $value;
                }
            }
        }

        return implode(',', $multiselectValues);
    }

    /**
     * Build the product category string
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function buildCategoryString(
        Product $product
    ) {
        $categoryOutput = [];

        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $productCategories */
        $productCategories = $product->getCategoryCollection();
        foreach ($productCategories as $productCategory) {
            /** @var \Magento\Catalog\Model\Category $productCategory */
            if ($categoryPath = $productCategory->getPath()) {
                $categoryPathIds = explode('/', $categoryPath);

                $categoryNames = [];
                foreach ($categoryPathIds as $categoryPathId) {
                    // Skip root categories
                    if (!in_array($categoryPathId, [1, 2])) {
                        $categoryNames[] = $this->getCategoryName($categoryPathId);
                    }
                }

                // Build string for this category path
                $categoryString = implode($this->helper->getCategoryTreeDelimeter(), $categoryNames);

                // Add to our array
                $categoryOutput[] = $categoryString;
            }
        }

        return implode($this->helper->getCategoryDelimeter(), $categoryOutput);
    }

    /**
     * Build the product image string
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string                         $attributeCode
     *
     * @return string
     */
    private function buildImageString(
        Product $product,
        $attributeCode
    ) {
        if ($product->hasData($attributeCode)) {
            $attributeValue = $product->getData($attributeCode);

            return $this->stripImageString($attributeValue);
        }

        return '';
    }

    /**
     * Build the media gallery string
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    private function buildMediaGalleryString(
        Product $product
    ) {
        $output = [];

        /** @var \Magento\Framework\Data\Collection $mediaGalleryImages */
        $mediaGalleryImages = $product->getMediaGalleryImages();

        foreach ($mediaGalleryImages as $mediaGalleryImage) {
            if (isset($mediaGalleryImage['path'])) {
                if ($strippedImageString = $this->baseName($mediaGalleryImage['path'])) {
                    $output[] = $strippedImageString;
                }
            }
        }

        return implode($this->helper->getMediaGalleryDelimeter(), $output);
    }

    /**
     * Build 'related_products' output
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    private function buildRelatedProducts(
        Product $product
    ) {
        $id = $product->getId();

        if (is_numeric($id)) {
            return implode(',', $this->_link->getRelatedProductSkus((int)$id));
        }

        return '';
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    private function buildConfigurableAttributesString(
        Product $product
    ) {
        $values = [];

        if ($product->getTypeId() === 'configurable') {
            /** @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute[] $configurableAttributes */
            $configurableAttributes = $this->_configurableProductType->getConfigurableAttributes($product);
            foreach ($configurableAttributes as $configurableAttribute) {
                $values[] = $configurableAttribute->getProductAttribute()->getAttributeCode();
            }
        }

        return implode(',', $values);
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    private function buildSimplesSkusString(
        Product $product
    ) {
        $values = [];

        if ($product->getTypeId() === 'configurable') {
            /** @var \Magento\Catalog\Api\Data\ProductInterface[] $children */
            $children = $this->_configurableProductType->getUsedProducts($product);
            foreach ($children as $child) {
                $values[] = $child->getSku();
            }
        }

        return implode(',', $values);
    }

    /**
     * Build the 'first_parent_sku' string
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string|null
     */
    private function buildFirstParentSku(
        Product $product
    ) {
        /** @var string[] $parentIds */
        $parentIds = $this->_configurableProductType->getParentIdsByChild($product->getId());

        if (count($parentIds) > 0) {
            $productId = $parentIds[0];

            if ($parentProduct = $this->getProduct($productId)) {
                return $parentProduct->getSku();
            }
        }

        return null;
    }

    /**
     * Get product by id
     *
     * @param int $entityId
     *
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    private function getProduct($entityId)
    {
        try {
            return $this->_productRepository->getById($entityId);
        } catch (NoSuchEntityException $e) {
            $this->log("getProduct() - Unable to lookup productId [$entityId] - {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Lookup category name by ID
     *
     * @param int $categoryId
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getCategoryName($categoryId)
    {
        /** @var \Magento\Catalog\Model\Category $category */
        $category = $this->_categoryRepository->get($categoryId);

        return $category->getName();
    }

    /**
     * Removes directory path from beginning of image path string.
     *
     * @param string $imagePath
     *
     * @return string
     */
    private function stripImageString($imagePath)
    {
        // 4 characters for folders, 4 characters for file extension and dot
        if (strlen($imagePath) > 8) {
            return substr($imagePath, 5);
        }

        return '';
    }

    /**
     * Get basenamecat
     *
     * @param string $path
     *
     * @return string
     */
    private function baseName($path)
    {
        /** @var \Magento\Framework\Filesystem\Io\File $fileInfo */
        $fileInfo = $this->_file->getPathInfo($path);

        return $fileInfo['basename'];
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
        $this->_logger->info('Controller/Adminhtml/Export/Download - ' . $message, $extra);
    }
}
