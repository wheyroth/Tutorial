<?php
namespace SJBR\SrLanguageMenu\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013-2017 Stanislas Rolland <typo3(arobas)sjbr.ca>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Utility\ClientUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Utility\ArrayUtility;
use TYPO3\CMS\Fluid\Core\Widget\WidgetRequest;
use SJBR\SrLanguageMenu\Domain\Model\Page;
use SJBR\SrLanguageMenu\Domain\Model\PageLanguageOverlay;
use SJBR\SrLanguageMenu\Domain\Model\SystemLanguage;
use SJBR\SrLanguageMenu\Utility\LocalizationUtility;

/**
 * Controls the rendering of the language menu as a normal content element or as a Fluid widget
 */
class MenuController extends \TYPO3\CMS\Fluid\Core\Widget\AbstractWidgetController
{
	/**
	 * @var array
	 */
	protected $supportedRequestTypes = array(
		Request::class,
		WidgetRequest::class
	);

	/**
	 * @var string Name of the extension this controller belongs to
	 */
	protected $extensionName = 'SrLanguageMenu';

	/**
	 * @var string Name of the extension this controller belongs to
	 */
	protected $extensionKey = 'sr_language_menu';

	/**
	 * @var \SJBR\SrLanguageMenu\Domain\Repository\SystemLanguageRepository
	 * @inject
	 */
	protected $systemLanguageRepository;

	/**
	 * @var \SJBR\StaticInfoTables\Domain\Repository\LanguageRepository
	 * @inject
	 */
	protected $languageRepository;

	/**
	 * @var \SJBR\SrLanguageMenu\Domain\Repository\PageRepository
	 * @inject
	 */
	protected $pageRepository;

	/**
	 * @var \SJBR\SrLanguageMenu\Domain\Repository\PageLanguageOverlayRepository
	 * @inject
	 */
	protected $pageLanguageOverlayRepository;

	/**
	 * Initialize the action when rendering as a widget
	 * @return void
	 */
	public function initializeIndexAction() {
		if (is_array($this->widgetConfiguration)) {
			$this->settings = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS, $this->extensionName);
			if (isset($this->widgetConfiguration['languages'])) {
				$this->settings['languages'] = $this->widgetConfiguration['languages'];
			}
			if (isset($this->widgetConfiguration['layout'])) {
				$this->settings['layout'] = $this->widgetConfiguration['layout'];
			}
			if (isset($this->widgetConfiguration['languageTitle'])) {
				$this->settings['languageTitle'] = $this->widgetConfiguration['languageTitle'];
			}
		}
	}

	/**
	 * Show the menu
	 *
	 * @return string empty string
	 */
	public function indexAction() {

		// Something is wrong: in the select box view case, the get parameters of the form action are never received...
		$variables = GeneralUtility::_POST('tx_srlanguagemenu_languagemenu');
		if ($variables['action'] === 'redirect' && $variables['uri']) {
			$this->redirectAction($variables['uri']);
		}

		// Adjust settings
		$this->processSettings();

		// Get system languages
		/** @var SystemLanguage[] $systemLanguages */
		$systemLanguages = $this->systemLanguageRepository->findAllByUidInList($this->settings['languages'])->toArray();
		// Add default language
		$defaultLanguageISOCode = $this->settings['defaultLanguageISOCode'] ?  strtoupper($this->settings['defaultLanguageISOCode']) : 'EN';
		$defaultCountryISOCode = $this->settings['defaultCountryISOCode'] ?  strtoupper($this->settings['defaultCountryISOCode']) : '';
		$defaultIsoLanguage = $this->languageRepository->findOneByIsoCodes($defaultLanguageISOCode, $defaultCountryISOCode);
		if (!is_object($defaultIsoLanguage)) {
			$defaultCountryISOCode = '';
			$defaultIsoLanguage = $this->languageRepository->findOneByIsoCodes($defaultLanguageISOCode);
			if (!is_object($defaultIsoLanguage)) {
				$defaultLanguageISOCode = 'EN';
				$defaultIsoLanguage = $this->languageRepository->findOneByIsoCodes($defaultLanguageISOCode);
			}
		}

		$defaultSystemLanguage = $this->objectManager->get(SystemLanguage::class);
		$defaultSystemLanguage->setIsoLanguage($defaultIsoLanguage);
		if (trim($this->settings['defaultLanguageTitle'])) {
			$defaultLanguageTitle = trim($this->settings['defaultLanguageTitle']);
		} else {
			$defaultLanguageTitle = $defaultSystemLanguage->getIsoLanguage()->getNameLocalized();
		}
		$defaultSystemLanguage->setTitle($defaultLanguageTitle);
		array_unshift($systemLanguages, $defaultSystemLanguage);

		// Get the available page language overlays
		$availableOverlays = array();

		// Beware of inaccessible page
		$page = $this->pageRepository->findByUid($this->getFrontendObject()->id);
		if ($page instanceof Page) {
			// If "Hide default translation of page" is not set on the page...
			if (!($page->getL18nCfg()&1)) {
				// Add default language
				$availableOverlays[] = 0;
			}
			/** @var PageLanguageOverlay[] $pageLanguageOverlays */
			$pageLanguageOverlays = $this->pageLanguageOverlayRepository->findByPage($page)->toArray();
			foreach ($pageLanguageOverlays as $pageLanguageOverlay) {
				// The overlay may refer to a deleted Website language
				if (is_object($pageLanguageOverlay->getLanguage())) {
					$availableOverlays[] = $pageLanguageOverlay->getLanguage()->getUid();
				}
			}
		}

		// Do not show menu if hideIfNoAltLanguages is set and there are no alternate languages
		$this->settings['showMenu'] = !$this->settings['hideIfNoAltLanguages'] || (count($availableOverlays) > 1);

		// Build language options
		$options = array();
		// If $this->settings['languages'] is not empty, the languages will be sorted in the order it specifies
		$languages = GeneralUtility::trimExplode(',', $this->settings['languages'], TRUE);
		if (!empty($languages) && !in_array(0, $languages)) {
			array_unshift($languages, 0);
		}
		$index = 0;
		foreach ($systemLanguages as $systemLanguage) {
			$option = array(
				'uid' => $systemLanguage->getUid() ? $systemLanguage->getUid() : 0,
				'isoCodeA2' => is_object($systemLanguage->getIsoLanguage()) ? $systemLanguage->getIsoLanguage()->getIsoCodeA2() : '',
				'countryIsoCodeA2' => is_object($systemLanguage->getIsoLanguage()) ? $systemLanguage->getIsoLanguage()->getCountryIsoCodeA2(): ''
			);
			// Set combined ISO code
			$option['combinedIsoCode'] = strtolower($option['isoCodeA2']) . ($option['countryIsoCodeA2'] ? '_' . $option['countryIsoCodeA2'] : '');

			// Set the label
			switch ($this->settings['languageTitle']) {
				case '0':
					$option['title'] = is_object($systemLanguage->getIsoLanguage()) ? $systemLanguage->getIsoLanguage()->getNameLocalized() : '';
					break;
				case '1':
					$option['title'] = is_object($systemLanguage->getIsoLanguage()) ? $systemLanguage->getIsoLanguage()->getLocalName() : '';
					break;
				case '2':
					$option['title'] = $systemLanguage->getTitle();
					break;
				case '3':
					$option['title'] = strtoupper($option['combinedIsoCode']);
					break;
			}
			if (!$option['title']) {
				$option['title'] = $systemLanguage->getTitle();
			}

			// Set paths to flags
			$option['flagFile'] = $this->settings['flagsDirectory']
				. ($this->settings['alternateFlags'][$option['combinedIsoCode']] ?: $option['combinedIsoCode'])
				. '.' . $this->settings['flagsExtension'];

			// Set availability of overlay
			$option['isAvailable'] = in_array($option['uid'], $availableOverlays);
			$option['notAvailableTitle'] = $option['title'];
			if (!$option['isAvailable']) {
				// Switch localization target language
				LocalizationUtility::setAlternateLanguage($option['combinedIsoCode'], $this->extensionName);
				$option['notAvailableTitle'] = \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('translationNotAvailable', $this->extensionName, array($systemLanguage->getIsoLanguage() ? $systemLanguage->getIsoLanguage()->getLocalName() : $option['title']));
				// Restore configured localization target language
				LocalizationUtility::restoreConfiguredLanguage($this->extensionName);
			}

			// Add configured external url for missing overlay record
			if ($this->settings['useExternalUrl'][$option['combinedIsoCode']] || is_array($this->settings['useExternalUrl'][$option['combinedIsoCode']])) {
				if ($option['isAvailable']) {
					if ($this->settings['forceUseOfExternalUrl'] || (is_array($this->settings['useExternalUrl'][$option['combinedIsoCode']]) && $this->settings['useExternalUrl'][$option['combinedIsoCode']]['force'])) {
						$option['externalUrl'] = is_array($this->settings['useExternalUrl'][$option['combinedIsoCode']]) ? $this->settings['useExternalUrl'][$option['combinedIsoCode']]['_typoScriptNodeValue'] : $this->settings['useExternalUrl'][$option['combinedIsoCode']];
					}
				} else {
					$option['externalUrl'] = is_array($this->settings['useExternalUrl'][$option['combinedIsoCode']]) ? $this->settings['useExternalUrl'][$option['combinedIsoCode']]['_typoScriptNodeValue'] : $this->settings['useExternalUrl'][$option['combinedIsoCode']];
					$option['isAvailable'] = TRUE;
				}
			}

			// Set current language indicator
			$option['isCurrent'] = ($option['uid'] == $this->getFrontendObject()->config['config']['sys_language_uid']);

			// If $this->settings['languages'] is not empty, the languages will be sorted in the order it specifies
			$key = array_search($option['uid'], $languages);
			$key = ($key !== FALSE) ? $key : count($languages) + $index++;
			$options[$key] = $option;
		}
		ksort($options);

		// Show current language first, if configured
		if ($this->settings['showCurrentFirst']) {
			$key = array_search($this->getFrontendObject()->config['config']['sys_language_uid'], $languages);
			if ($key) {
				$option = $options[$key];
				unset($options[$key]);
				array_unshift($options, $option);
			}
		}

		// Render the menu
		$this->view->assign('settings', $this->settings);
		$this->view->assign('options', $options);
	}

	/**
	 * Process redirection request
	 *
	 * @param string $uri: uri to redirect to
	 * @return string empty string
	 */
	public function redirectAction($uri) {
		try {
			$this->redirectToUri($uri);
		} catch (StopActionException $e) {}
	}

	/**
	 * Reviews and adjusts plugin settings
	 *
	 * @return void
	 * @api
	 */
	protected function processSettings() {
		// Set the list of language uid's
		if (!$this->settings['languages']) {
			// Take the list from TypoScript, if any
			$this->settings['languages'] = strval($this->settings['languagesUidsList']);
		} else {
			// The list was set in the flexform
			$languagesArray = GeneralUtility::trimExplode(',', $this->settings['languages'], TRUE);
			$positionOfDefaultLanguage = min(intval($this->settings['positionOfDefaultLanguage']), count($languagesArray));
			array_splice($languagesArray, $positionOfDefaultLanguage, 0, array('0'));
			$this->settings['languages'] = implode(',', $languagesArray);
		}

		// Backward compatibility settings for language labels
		if (!isset($this->settings['languageTitle']) || !in_array($this->settings['languageTitle'], array(0, 1, 2, 3))) {
			if ($this->settings['useSysLanguageTitle']) {
				$this->settings['languageTitle'] = 2;
			} else if ($this->settings['useIsoLanguageCountryCode']) {
				$this->settings['languageTitle'] = 3;
			} else if ($this->settings['useSelfLanguageTitle']) {
				$this->settings['languageTitle'] = 1;
			} else {
				$this->settings['languageTitle'] = 0;
			}
		}

		// Map numeric layout to keyword
		if (!isset($this->settings['layout'])) {
			$this->settings['layout'] = $this->settings['defaultLayout'];
		}
		$allowedLayouts = array('Flags', 'Select', 'Links');
		// Allow keyword values coming from Fluid widget... and perhaps from TS setup
		if (!in_array($this->settings['layout'], $allowedLayouts)) {
			$this->settings['layout'] = $allowedLayouts[$this->settings['layout']];
			if (!$this->settings['layout']) {
				$this->settings['layout'] = 'Flags';
			}
		}

		// Flags directory
		$this->settings['flagsDirectory'] = ExtensionManagementUtility::siteRelPath($this->extensionKey) . 'Resources/Public/Images/Flags/';
		$this->settings['flagsExtension'] = 'png';
		if ($this->settings['englishFlagFile']) {
			$this->settings['flagsDirectory'] = dirname($this->getFrontendObject()->tmpl->getFileName(trim($this->settings['englishFlagFile']))) . '/';
			$this->settings['flagsExtension'] = pathinfo(trim($this->settings['englishFlagFile']), PATHINFO_EXTENSION);		
		}

		// 'Hide default translation of page' configuration option
		$this->settings['hideIfDefaultLanguage'] = GeneralUtility::hideIfDefaultLanguage($this->getFrontendObject()->page['l18n_cfg']);

		// Adjust parameters to remove
		if (isset($this->settings['removeParams'])) {
			if (!is_array($this->settings['removeParams'])) {
				$this->settings['removeParams'] = GeneralUtility::trimExplode(',', $this->settings['removeParams'], true);
			}
		} else {
			$this->settings['removeParams'] = array();
		}
		// Add L and cHash to url parameters to remove
		$this->settings['removeParams'] = array_merge($this->settings['removeParams'], array('L', 'cHash'));
		// Add disallowed parameters to parameters to remove
		if ($this->settings['allowedParams']) {
			$getVariables = GeneralUtility::_GET();
			if (isset($getVariables) && is_array($getVariables)) {
				$allowedParams = GeneralUtility::trimExplode(',', $this->settings['allowedParams'], true);
				$allowedParams = array_merge($allowedParams, array('L', 'id', 'type', 'MP'));
				$allowedParams = array_merge($allowedParams, GeneralUtility::trimExplode(',', $this->getFrontendObject()->config['config']['linkVars'] ?: '', true));
				$disallowedParams = array_diff(array_keys($getVariables), $allowedParams);
				// Add disallowed parameters to parameters to remove
				$this->settings['removeParams'] = array_merge($this->settings['removeParams'], $disallowedParams);
			}
		}

		// Identify IE > 9
		$browserInfo = ClientUtility::getBrowserInfo(GeneralUtility::getIndpEnv('HTTP_USER_AGENT'));
		$this->settings['isIeGreaterThan9'] =  $browserInfo['browser'] == 'msie' && intval($browserInfo['version']) > 9 ? 1 : 0;
	}

	/**
	 * Handles a request. The result output is returned by altering the given response.
	 *
	 * @param \TYPO3\CMS\Extbase\Mvc\RequestInterface $request The request object
	 * @param \TYPO3\CMS\Extbase\Mvc\ResponseInterface $response The response, modified by this handler
	 * @return void
	 * @api
	 */
	public function processRequest(\TYPO3\CMS\Extbase\Mvc\RequestInterface $request, \TYPO3\CMS\Extbase\Mvc\ResponseInterface $response) {
		if ($request instanceof WidgetRequest) {
			$this->widgetConfiguration = $request->getWidgetContext()->getWidgetConfiguration();
		}
		ActionController::processRequest($request, $response);
	}

	/**
	 * Allows the widget template root path to be overridden via the framework configuration,
	 * e.g. plugin.tx_extension.view.widget.<WidgetViewHelperClassName>.templateRootPaths
	 *
	 * @param ViewInterface $view
	 * @return void
	 * @see \TYPO3\CMS\Fluid\Core\Widget\AbstractWidgetController in TYPO3 CMS 7
	 */
	protected function setViewConfiguration(ViewInterface $view) {
		if ($this->request instanceof WidgetRequest) {
			$extbaseFrameworkConfiguration = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK, $this->extensionName);
			$widgetViewHelperClassName = $this->request->getWidgetContext()->getWidgetViewHelperClassName();
			if (isset($extbaseFrameworkConfiguration['view']['widget'][$widgetViewHelperClassName])) {
				$configurationOverridden = $extbaseFrameworkConfiguration;
				$configurationOverridden['view'] = array_replace_recursive($configurationOverridden['view'], $configurationOverridden['view']['widget'][$widgetViewHelperClassName]);
				$this->configurationManager->setConfiguration($configurationOverridden);
				ActionController::setViewConfiguration($view);
				$this->configurationManager->setConfiguration($extbaseFrameworkConfiguration);
			}
		} else {
			ActionController::setViewConfiguration($view);
		}
	}

	/**
	 * Returns an instance of the Frontend object.
	 *
	 * @return \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
	 */
	protected function getFrontendObject() {
		return $GLOBALS['TSFE'];
	}
}