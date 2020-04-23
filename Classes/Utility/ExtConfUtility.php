<?php
/**
 * This file is part of the "form_to_database" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Lavitto\FormToDatabase\Utility;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Class FormValueUtility
 *
 * @package Lavitto\FormToDatabase\Utility
 */
class ExtConfUtility implements SingletonInterface
{

    /**
     * @var array
     */
    protected $extConf;

    /**
     * @var array
     */
    protected $defaultExtConf = [
        'hideLocationInList' => false,
        'csvDelimiter' => ';'
    ];

    /**
     * Initialize the ExtConfUtility
     */
    public function initializeObject(): void
    {
        $extConfSerialized = $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['form_to_database'] ?? null;
        $extConf = [];
        if ($extConfSerialized !== null) {
            $extConf = unserialize($extConfSerialized, ['allowed_classes' => false]);
        }
        $this->extConf = array_merge($this->defaultExtConf, $extConf);
    }

    /**
     * Returns the full configuration
     *
     * @return array
     */
    public function getFullConfig(): array
    {
        return $this->extConf;
    }

    /**
     * Gets a configuration
     *
     * @param string $key
     * @return string|null
     */
    public function getConfig(string $key): ?string
    {
        return trim($this->extConf[$key]);
    }
}
