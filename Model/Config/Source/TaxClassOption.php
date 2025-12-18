<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Model\Config\Source;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Tax\Api\TaxClassManagementInterface;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Tax\Model\ClassModel;

/**
 * Options for Tax Class
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class TaxClassOption implements OptionSourceInterface
{
    /**
     * @var \Magento\Framework\Api\FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var \Magento\Tax\Api\TaxClassRepositoryInterface
     */
    private $taxClassRepository;

    /**
     * TaxClassOption constructor.
     *
     * @param \Magento\Framework\Api\FilterBuilder         $filterBuilder
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Magento\Tax\Api\TaxClassRepositoryInterface $taxClassRepository
     */
    public function __construct(
        FilterBuilder $filterBuilder,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        TaxClassRepositoryInterface $taxClassRepository
    ) {
        $this->filterBuilder         = $filterBuilder;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->taxClassRepository    = $taxClassRepository;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     * @throws \Magento\Framework\Exception\InputException
     */
    public function toOptionArray()
    {
        $options = [];

        $filter = $this->filterBuilder
            ->setField(ClassModel::KEY_TYPE)
            ->setValue(TaxClassManagementInterface::TYPE_PRODUCT)
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder->addFilters([$filter])->create();
        $searchResults  = $this->taxClassRepository->getList($searchCriteria);
        foreach ($searchResults->getItems() as $taxClass) {
            $options[] = [
                'value' => $taxClass->getClassId(),
                'label' => $taxClass->getClassName()
            ];
        }

        return $options;
    }
}
