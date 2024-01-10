<?php
/**
 * Copyright (C) EC Brands Corporation - All Rights Reserved
 * Contact Licensing@ECInternet.com for use guidelines
 */
declare(strict_types=1);

namespace ECInternet\RAPIDWebSync\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Logger channel
 */
class Logger extends \Monolog\Logger
{
    const CONFIG_PATH_DEBUG_LOGGING = 'rapid_web_sync/general/debug_logging';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $config;

    /**
     * Logger constructor.
     *
     * @param string                                             $name
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param array                                              $handlers
     * @param array                                              $processors
     */
    public function __construct(
        $name,
        ScopeConfigInterface $config,
        array $handlers = [],
        array $processors = []
    ) {
        parent::__construct($name, $handlers, $processors);

        $this->config = $config;
    }

    public function addRecord($level, $message, array $context = [])
    {
        if (!$this->config->isSetFlag(self::CONFIG_PATH_DEBUG_LOGGING)) {
            return false;
        }

        return parent::addRecord($level, $message, $context);
    }
}
