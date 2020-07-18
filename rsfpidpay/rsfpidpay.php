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

use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;
class plgSystemRSFPIdpay extends JPlugin
{


    var $componentId = 3543;
    var $componentValue = 'idpay';


    public function __construct(&$subject, $config, Http $http = null)
    {
        $this->http = $http ?: HttpFactory::getHttp();
        parent::__construct($subject, $config);
        $this->newComponents = array(3543);
    }


    /**
     * @param $api_key
     * @param $sandbox
     * @return array
     */
    public function options($api_key,$sandbox)
    {
        $options = array('Content-Type' => 'application/json',
            'X-API-KEY' => $api_key,
            'X-SANDBOX' => $sandbox,
        );
        return $options;
    }

    function rsfp_bk_onAfterShowComponents()
    {
        $lang = JFactory::getLanguage();
        $lang->load('plg_system_rsfpidpay');
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
            $item->text = $data['LABEL'] . '(پرداخت امن با آی‌دی‌پی)';

            //JURI::root(true).'/plugins/system/rsfpidpay/assets/images/logo.png
            $items[] = $item;
        }
    }


    function rsfp_bk_onAfterCreateComponentPreview($args = array())
    {

        if ($args['ComponentTypeName'] == 'idpay') {
            $args['out'] = '<td>&nbsp;idpay</td>';
            $args['out'] .= '<td><img src=' . JURI::root(true) . '/plugins/system/rsfpidpay/assets/images/logo.png />' . $args['data']['LABEL'] . '</td>';
        }
    }

    function rsfp_bk_onAfterShowConfigurationTabs($tabs)
    {
        $lang = JFactory::getLanguage();
        $lang->load('plg_system_rsfpidpay');
        $tabs->addTitle('تنظیمات درگاه idpay', 'form-TRANGELIDPAY');
        $tabs->addContent($this->idpayConfigurationScreen());
    }


    function rsfp_doPayment($payValue, $formId, $SubmissionId, $price, $products, $code)
    {


        $components = RSFormProHelper::componentExists($formId, $this->componentId);
        $data = RSFormProHelper::getComponentProperties($components[0]);
        $app = JFactory::getApplication();


        if ($data['TOTAL'] == 'YES') {
            $price = (int)$_POST['form']['rsfp_Total'];
        } elseif ($data['TOTAL'] == 'NO') {
            if ($data['FIELDNAME'] == 'Select the desired field') {
                $msg = 'فیلدی برای قیمت انتخاب نشده است.';
                $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }
            $price = $_POST['form'][$data['FIELDNAME']];
        }

        if (is_array($price))
            $price = (int)array_sum($price);

        if (!$price) {
            $msg = 'مبلغی وارد نشده است';
            $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }


        $currency = RSFormProHelper::getConfig('idpay.currency');
        $price = $this->rsfp_idpay_get_amount($price, $currency);


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

            $data = array('order_id' => $formId, 'amount' => $amount, 'phone' => '', 'mail' => '', 'desc' => $desc, 'callback' => $callback,);
            $url='https://api.idpay.ir/v1.1/payment';
            $options = $this->options($api_key,$sandbox);
            $result = $this->http->post($url, json_encode($data, true), $options);
            $http_status = $result->code;
            $result = json_decode($result->body);

            //save idpay_id in db
            $db = JFactory::getDBO();
            $sql = 'INSERT INTO `#__rsform_submission_values` (FormId, SubmissionId, FieldName,FieldValue) VALUES (' . $formId . ',' . $SubmissionId . ',"idpay_id","' . $result->id . '")';
            $db->setQuery($sql);
            $db->execute();


            if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
                $msg = 'خطا هنگام ایجاد تراکنش. وضعیت خطا:' . $http_status . "<br>" . 'کد خطا: ' . $result->error_code . ' پیغام خطا ' . $result->error_message;
                $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($result->status));
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }

            $app->redirect($result->link);
        } else {
            $msg = 'مبلغ وارد شده کمتر از ۱۰۰۰۰ ریال می باشد';
            $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }
    }


    function rsfp_f_onSwitchTasks()
    {


        if (JRequest::getVar('plugin_task') == 'idpay.notify') {


            $app = JFactory::getApplication();
            $jinput = $app->input;


            $track_id = $_POST['track_id'];
            $formId = $_POST['order_id'];
            $code = $jinput->get->get('code', '', 'STRING');
            $db = JFactory::getDBO();
            $db->setQuery("SELECT SubmissionId FROM #__rsform_submissions s WHERE s.FormId='" . $formId . "' AND MD5(CONCAT(s.SubmissionId,s.DateSubmitted)) = '" . $db->escape($code) . "'");
            $SubmissionId = $db->loadResult();
            $components = RSFormProHelper::componentExists($formId, $this->componentId);
            $data = RSFormProHelper::getComponentProperties($components[0]);



            $components = RSFormProHelper::componentExists($formId, $this->componentId);
            $data = RSFormProHelper::getComponentProperties($components[0]);
            $app = JFactory::getApplication();


            if ($data['TOTAL'] == 'YES') {
                $fieldname="rsfp_Total";
            } elseif ($data['TOTAL'] == 'NO') {
                $fieldname=$data['FIELDNAME'];
            }

            $price = round($this::getPayerPrice($formId, $SubmissionId,$fieldname), 0);

            //convert to currency
            $currency = RSFormProHelper::getConfig('idpay.currency');
            $price = $this->rsfp_idpay_get_amount($price, $currency);


            $pid = $_POST['id'];
            $porder_id = $_POST['order_id'];
            $order_id = $formId;

            //get status result of payment api
            $status = $_POST['status'];

            if (!empty($pid) && !empty($porder_id) && $porder_id == $order_id) {

                //in waiting confirm
                if ($status == 10) {
                    $api_key = RSFormProHelper::getConfig('idpay.api');
                    $sandbox = RSFormProHelper::getConfig('idpay.sandbox') == 'no' ? 'false' : 'true';

                    $data = array('id' => $pid, 'order_id' => $order_id,);

                    $url='https://api.idpay.ir/v1.1/payment/verify';
                    $options = $this->options($api_key,$sandbox);
                    $result = $this->http->post($url, json_encode($data, true), $options);
                    $http_status = $result->code;
                    $result = json_decode($result->body);


                    //http error
                    if ($http_status != 200) {
                        $msg = sprintf('خطا هنگام بررسی وضعیت تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
                        $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($status));
                        $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                        $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                    }

                    $verify_status = empty($result->status) ? NULL : $result->status;
                    $verify_track_id = empty($result->track_id) ? NULL : $result->track_id;
                    $verify_order_id = empty($result->order_id) ? NULL : $result->order_id;
                    $verify_amount = empty($result->amount) ? NULL : $result->amount;
                    $hashed_card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;
                    $card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;


                    //failed verify
                    if (empty($verify_status) || empty($verify_track_id) || empty($verify_amount) || $verify_amount != $price || $verify_status < 100) {
                        $msg = $this->idpay_get_failed_message($verify_track_id, $order_id, $verify_status);
                        $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($verify_status));
                        $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                        $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');


                        //successful verify
                    } else {


                        //check double spending
                        $db = JFactory::getDBO();
                        $sql = 'SElECT FieldValue FROM ' . "#__rsform_submission_values" . '  WHERE FieldName="idpay_id" AND FormId=' . 2 . ' AND SubmissionId = ' . $SubmissionId;
                        $db->setQuery($sql);
                        $db->execute();
                        $exist = $db->loadObjectList();
                        $exist = count($exist);

                        if ($verify_order_id !== $order_id or !$exist) {
                            $msg = $this->idpay_get_failed_message($verify_track_id, $order_id, 0);
                            $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages(0));
                            $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                        }



                        $mainframe = JFactory::getApplication();
                        $mainframe->triggerEvent('rsfp_afterConfirmPayment', array($SubmissionId));
                        $msgForSaveDataTDataBase = $this->otherStatusMessages($verify_status) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                        $this->updateAfterEvent($formId, $SubmissionId, $msgForSaveDataTDataBase);
                        $msg = $this->idpay_get_success_message($verify_track_id, $order_id, $verify_status);
                        $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                        $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'success');

                    }

                } else {
                    //save pay failed pay message(payment api)
                    $msg = $this->idpay_get_failed_message($track_id, $order_id, $status);
                    $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($status));
                    $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                    $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                }

            } else {

                $msg = $this->idpay_get_failed_message($track_id, $order_id, $status);
                $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($status));
                $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');


            }
        } else {
            return NULL;
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

                <tr>
                    <td width="200" style="width: 200px;" align="right" class="key">
                        <label><?php echo 'currency'; ?></label></td>
                    <td>
                        <?php
                        echo(RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.currency')));

                        ?>
                        <select name="rsformConfig[idpay.currency]">
                            <option value="RIAL"<?php echo(RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.currency')) == 'RIAL' ? 'selected="selected"' : ""); ?>>
                                ریال
                            </option>
                            <option value="IRT"<?php echo(RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.currency')) == 'IRT' ? 'selected="selected"' : ""); ?>>
                                تومان
                            </option>
                        </select>
                    </td>
                </tr>
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

    function getPayerPrice($formId, $SubmissionId, $fieldName)
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
            $db->qn('FieldName') . ' = ' . $db->q($fieldName)
        );
        $db->setQuery((string)$query);
        $result = $db->loadResult();

        return (int)$result;
    }


    public function idpay_get_failed_message($track_id, $order_id, $msgNumber = null)
    {
        //get defult massege
        $failedMassage = RSFormProHelper::getConfig('idpay.failed_massage');
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $failedMassage) . "<br>" . "$msg";
    }

    public function idpay_get_success_message($track_id, $order_id)
    {
        //get defult success massage
        $successMassage = RSFormProHelper::getConfig('idpay.success_massage');
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $successMassage);
    }


    /**
     * @param $msgNumber
     * @get status from $_POST['status]
     * @return string
     */
    public function otherStatusMessages($msgNumber = null)
    {

        switch ($msgNumber) {
            case "1":
                $msg = "پرداخت انجام نشده است";
                break;
            case "2":
                $msg = "پرداخت ناموفق بوده است";
                break;
            case "3":
                $msg = "خطا رخ داده است";
                break;
            case "3":
                $msg = "بلوکه شده";
                break;
            case "5":
                $msg = "برگشت به پرداخت کننده";
                break;
            case "6":
                $msg = "برگشت خورده سیستمی";
                break;
            case "7":
                $msg = "انصراف از پرداخت";
                break;
            case "8":
                $msg = "به درگاه پرداخت منتقل شد";
                break;
            case "10":
                $msg = "در انتظار تایید پرداخت";
                break;
            case "100":
                $msg = "پرداخت تایید شده است";
                break;
            case "101":
                $msg = "پرداخت قبلا تایید شده است";
                break;
            case "200":
                $msg = "به دریافت کننده واریز شد";
                break;
            case "0":
                $msg = "سواستفاده از تراکنش قبلی";
                break;
            case null:
                $msg = "خطا دور از انتظار";
                $msgNumber = '1000';
                break;
        }

        return $msg . ' -وضعیت: ' . "$msgNumber";

    }

    /**
     * @param $amount
     * @param $currency
     * @return float|int
     */
    function rsfp_idpay_get_amount($amount, $currency)
    {
        switch (strtolower($currency)) {
            case strtolower('IRR'):
            case strtolower('RIAL'):
                return $amount;

            case strtolower('تومان ایران'):
            case strtolower('تومان'):
            case strtolower('IRT'):
            case strtolower('Iranian_TOMAN'):
            case strtolower('Iran_TOMAN'):
            case strtolower('Iranian-TOMAN'):
            case strtolower('Iran-TOMAN'):
            case strtolower('TOMAN'):
            case strtolower('Iran TOMAN'):
            case strtolower('Iranian TOMAN'):
                return $amount * 10;

            case strtolower('IRHR'):
                return $amount * 1000;

            case strtolower('IRHT'):
                return $amount * 10000;

            default:
                return 0;
        }
    }

    /**
     * @param $formId
     * @param $SubmissionId
     * @param $msg
     * @return bool
     */
    public function updateAfterEvent($formId, $SubmissionId, $msg)
    {
        if (!$SubmissionId) {
            return false;
        }
        $db = JFactory::getDBO();
        $msg = "idpay: $msg";
        $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue=1 WHERE sv.FieldName='_STATUS' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
        $db->execute();
        $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue='" . $msg . "'  WHERE sv.FieldValue='idpay' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
        $db->execute();


    }
}
