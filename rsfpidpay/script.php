<?php
/**
 * IDPay payment plugin
 *
 * @developer JMDMahdi, vispa, mnbp1371
 * @publisher IDPay
 * @package VirtueMart
 * @subpackage payment
 * @copyright (C) 2020 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
defined( '_JEXEC' ) or die( 'Restricted access' );

class plgSystemRSFPIdpayInstallerScript
{
	public function preflight($type, $parent) {
		if ($type != 'uninstall') {
			$app = JFactory::getApplication();
			
			if (!file_exists(JPATH_ADMINISTRATOR.'/components/com_rsform/helpers/rsform.php')) {
				$app->enqueueMessage('Please install the RSForm! Pro component before continuing.', 'error');
				return false;
			}
			
			if (!file_exists(JPATH_ADMINISTRATOR.'/components/com_rsform/helpers/version.php')) {
				$app->enqueueMessage('Please upgrade RSForm! Pro to at least R45 before continuing!', 'error');
				return false;
			}
			
			if (!file_exists(JPATH_PLUGINS.'/system/rsfppayment/rsfppayment.php')) {
				$app->enqueueMessage('Please install the RSForm! Pro Payment Plugin first!', 'error');
				return false;
			}
			
			$jversion = new JVersion();
			if (!$jversion->isCompatible('3.6.0')) {
				$app->enqueueMessage('Please upgrade to at least Joomla! 3.6.x before continuing!', 'error');
				return false;
			}
		}
		
		return true;
	}
	
	public function postflight($type, $parent) {
		if ($type == 'update') {
			$db = JFactory::getDbo();
			$db->setQuery("SELECT * FROM #__rsform_config WHERE `SettingName`='idpay.return'");
			if (!$db->loadResult()) {
				$db->setQuery("INSERT IGNORE INTO `#__rsform_config` (`SettingName`, `SettingValue`) VALUES ('idpay.return', '')");
				$db->execute();
			}
		}
	}
}
