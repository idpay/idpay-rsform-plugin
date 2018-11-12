<?php
/**
 * IDPay payment plugin
 *
 * @developer JMDMahdi
 * @publisher IDPay
 * @package VirtueMart
 * @subpackage payment
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
defined('_JEXEC') or die('Restricted access');

class plgSystemRSFPIdpay extends JPlugin
{
    var $componentId = 3543;
    var $componentValue = 'idpay';

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->newComponents = array(3543);
    }

    function rsfp_bk_onAfterShowComponents()
    {
        $lang = JFactory::getLanguage();
        $lang->load('plg_system_rsfpidpay');
        $db = JFactory::getDBO();
        $formId = JRequest::getInt('formId');
        $link = "displayTemplate('" . $this->componentId . "')";
        if ($components = RSFormProHelper::componentExists($formId, $this->componentId))
            $link = "displayTemplate('" . $this->componentId . "', '" . $components[0] . "')";
        ?>
        <li class="rsform_navtitle"><?php echo 'درگاه idpay'; ?></li>
        <li><a href="javascript: void(0);" onclick="<?php echo $link; ?>;return false;"
               id="rsfpc<?php echo $this->componentId; ?>"><span
                        id="IDPAY"><?php echo JText::_('اضافه کردن درگاه idpay'); ?></span></a></li>
        <?php
    }

    function rsfp_getPayment(&$items, $formId)
    {
        if ($components = RSFormProHelper::componentExists($formId, $this->componentId)) {
            $data = RSFormProHelper::getComponentProperties($components[0]);
            $item = new stdClass();
            $item->value = $this->componentValue;
            $item->text = $data['LABEL'];
            // add to array
            $items[] = $item;
        }
    }

    function rsfp_doPayment($payValue, $formId, $SubmissionId, $price, $products, $code)
    {//test
        $app = JFactory::getApplication();
        // execute only for our plugin
        if ($payValue != $this->componentValue) return;
        $tax = RSFormProHelper::getConfig('idpay.tax.value');
        if ($tax)
            $nPrice = round($tax, 0) + round($price, 0);
        else
            $nPrice = round($price, 0);
        if ($nPrice > 100) {
            $api_key = RSFormProHelper::getConfig('idpay.api');
            $sandbox = RSFormProHelper::getConfig('idpay.sandbox') == 'no' ? 'false' : 'true';

            $amount = $nPrice;
            $desc = 'پرداخت سفارش شماره: ' . $formId;
            $callback = JURI::root() . 'index.php?option=com_rsform&task=plugin&plugin_task=idpay.notify&code=' . $code;

            if (empty($amount)) {
                $msg = 'واحد پول انتخاب شده پشتیبانی نمی شود.';
                $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }

            $data = array(
                'order_id' => $formId,
                'amount' => $amount,
                'phone' => '',
                'desc' => $desc,
                'callback' => $callback,
            );

            $ch = curl_init('https://api.idpay.ir/v1/payment');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'X-API-KEY:' . $api_key,
                'X-SANDBOX:' . $sandbox,
            ));

            $result = curl_exec($ch);
            $result = json_decode($result);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
                $msg = sprintf('خطا هنگام ایجاد تراکنش. کد خطا: %s', $http_status);
                $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }
            $app->redirect($result->link);
        } else {
            $msg = 'مبلغ وارد شده کمتر از ۱۰۰۰۰ ریال می باشد';
            $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }
    }

    function rsfp_bk_onAfterCreateComponentPreview($args = array())
    {
        if ($args['ComponentTypeName'] == 'idpay') {
            $args['out'] = '<td>&nbsp;</td>';
            $args['out'] .= '<td>' . $args['data']['LABEL'] . '</td>';
        }
    }

    function rsfp_bk_onAfterShowConfigurationTabs($tabs)
    {
        $lang = JFactory::getLanguage();
        $lang->load('plg_system_rsfpidpay');
        $tabs->addTitle('تنظیمات درگاه idpay', 'form-TRANGELZARINPAL');
        $tabs->addContent($this->idpayConfigurationScreen());
    }

    function rsfp_f_onSwitchTasks()
    {
        if (JRequest::getVar('plugin_task') == 'idpay.notify') {
            $app = JFactory::getApplication();
            $jinput = $app->input;
            $code = $jinput->get->get('code', '', 'STRING');
            $formId = $jinput->get->get('order_id', '0', 'INT');
            $db = JFactory::getDBO();
            $db->setQuery("SELECT SubmissionId FROM #__rsform_submissions s WHERE s.FormId='" . $formId . "' AND MD5(CONCAT(s.SubmissionId,s.DateSubmitted)) = '" . $db->escape($code) . "'");
            $SubmissionId = $db->loadResult();

            $price = round($this::getPayerPrice($formId, $SubmissionId), 0);
            $pid = $_POST['id'];
            $porder_id = $_POST['order_id'];
            $order_id = $formId;
            if (!empty($pid) && !empty($porder_id) && $porder_id == $order_id) {

                $api_key = RSFormProHelper::getConfig('idpay.api');
                $sandbox = RSFormProHelper::getConfig('idpay.sandbox') == 'no' ? 'false' : 'true';

                $data = array(
                    'id' => $pid,
                    'order_id' => $order_id,
                );

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1/payment/inquiry');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'X-API-KEY:' . $api_key,
                    'X-SANDBOX:' . $sandbox,
                ));

                $result = curl_exec($ch);
                $result = json_decode($result);
                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_status != 200) {
                    $msg = sprintf('خطا هنگام بررسی وضعیت تراکنش. کد خطا: %s', $http_status);
                    $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                    $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                }

                $inquiry_status = empty($result->status) ? NULL : $result->status;
                $inquiry_track_id = empty($result->track_id) ? NULL : $result->track_id;
                $inquiry_order_id = empty($result->order_id) ? NULL : $result->order_id;
                $inquiry_amount = empty($result->amount) ? NULL : $result->amount;

                if (empty($inquiry_status) || empty($inquiry_track_id) || empty($inquiry_amount) || $inquiry_amount != $price || $inquiry_status != 100) {
                    $msg = $this->idpay_get_failed_message($inquiry_track_id, $inquiry_order_id);
                    $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                    $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                } else {
                    if ($SubmissionId) {
                        $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue=1 WHERE sv.FieldName='_STATUS' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
                        $db->execute();
                        $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue='" . "کد پیگیری  " . $inquiry_track_id . "' WHERE sv.FieldName='transaction' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
                        $db->execute();
                        $mainframe = JFactory::getApplication();
                        $mainframe->triggerEvent('rsfp_afterConfirmPayment', array($SubmissionId));
                    }
                    $msg = $this->idpay_get_success_message($inquiry_track_id, $inquiry_order_id);
                    $app->enqueueMessage($msg, 'message');
                }
            } else {
                $msg = 'کاربر از انجام تراکنش منصرف شده است';
                $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }
        }
    }

    function idpayConfigurationScreen()
    {
        ob_start();
        ?>
        <div id="page-idpay" class="com-rsform-css-fix">
            <table class="admintable">
                <tr>
                    <td width="200" style="width: 200px;" align="right" class="key"><label
                                for="api"><?php echo 'API KEY'; ?></label></td>
                    <td><input type="text" name="rsformConfig[idpay.api]"
                               value="<?php echo RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.api')); ?>"
                               size="100" maxlength="64"></td>
                </tr>
                <tr>
                    <td width="200" style="width: 200px;" align="right" class="key">
                        <label><?php echo 'آزمایشگاه'; ?></label></td>
                    <td>
                        <select name="rsformConfig[idpay.sandbox]">
                            <option value="yes"<?php echo(RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.sandbox')) == 'yes' ? 'selected="selected"' : ""); ?>>
                                بله
                            </option>
                            <option value="no"<?php echo(RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.sandbox')) == 'no' ? 'selected="selected"' : ""); ?>>
                                خیر
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td width="200" style="width: 200px;" align="right" class="key">
                        <label><?php echo 'پیام پرداخت موفق'; ?></label></td>
                    <td><textarea
                                name="rsformConfig[idpay.success_massage]"><?php echo(!empty(RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.success_massage'))) ? RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.success_massage')) : "پرداخت شما با موفقیت انجام شد. کد رهگیری: {track_id}"); ?></textarea><br>متن
                        پیامی که می خواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت
                        کدهای {order_id} برای نمایش شماره سفارش و {track_id} برای نمایش کد رهگیری آیدی پی استفاده
                        نمایید.
                    </td>
                </tr>
                <tr>
                    <td width="200" style="width: 200px;" align="right" class="key">
                        <label><?php echo 'پیام پرداخت ناموفق'; ?></label></td>
                    <td><textarea
                                name="rsformConfig[idpay.failed_massage]"><?php echo(!empty(RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.failed_massage'))) ? RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.failed_massage')) : "پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید."); ?></textarea><br>متن
                        پیامی که می خواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد کنید. همچنین می توانید از
                        شورت کدهای {order_id} برای نمایش شماره سفارش و {track_id} برای نمایش کد رهگیری آیدی پی استفاده
                        نمایید.
                    </td>
                </tr>
            </table>
        </div>

        <?php
        $contents = ob_get_contents();
        ob_end_clean();
        return $contents;
    }

    public function idpay_get_failed_message($track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->params->get('failed_massage', ''));
    }

    public function idpay_get_success_message($track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->params->get('success_massage', ''));
    }

    function getPayerMobile($formId, $SubmissionId)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('FieldValue')
            ->from($db->qn('#__rsform_submission_values'));
        $query->where(
            $db->qn('FormId') . ' = ' . $db->q($formId)
            . ' AND ' .
            $db->qn('SubmissionId') . ' = ' . $db->q($SubmissionId)
            . ' AND ' .
            $db->qn('FieldName') . ' = ' . $db->q('mobile')
        );
        $db->setQuery((string)$query);
        $result = $db->loadResult();
        return $result;
    }

    function getPayerPrice($formId, $SubmissionId)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('FieldValue')
            ->from($db->qn('#__rsform_submission_values'));
        $query->where(
            $db->qn('FormId') . ' = ' . $db->q($formId)
            . ' AND ' .
            $db->qn('SubmissionId') . ' = ' . $db->q($SubmissionId)
            . ' AND ' .
            $db->qn('FieldName') . ' = ' . $db->q('price')
        );
        $db->setQuery((string)$query);
        $result = $db->loadResult();
        return $result;
    }
}
