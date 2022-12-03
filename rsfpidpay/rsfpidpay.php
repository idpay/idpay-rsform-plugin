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

    public function options($api_key, $sandbox)
    {
        $options = array('Content-Type' => 'application/json',
            'X-API-KEY' => $api_key,
            'X-SANDBOX' => $sandbox,
        );
        return $options;
    }

    function redirectTo(&$application, $url, $message, $messageType)
    {
        $application->enqueueMessage(JText::_($message), $messageType);
        return $application->redirect(JRoute::_($url, false));
    }

    function onRsformBackendAfterShowComponents()
    {
        $app = JFactory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('plg_system_rsfpidpay');
        $formId = $app->input->getInt('formId');
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

    function onRsformGetPayment(&$items, $formId)
    {
        if ($components = RSFormProHelper::componentExists($formId, $this->componentId)) {
            $data = RSFormProHelper::getComponentProperties($components[0]);
            $item = new stdClass();
            $item->value = $this->componentValue;
            $item->text = $data['LABEL'] . '(پرداخت امن با آی‌دی‌پی)';

            $items[] = $item;
        }
    }

    function onRsformBackendAfterCreateComponentPreview($args = array())
    {
        if ($args['ComponentTypeName'] == 'idpay') {
            $args['out'] = '<td>&nbsp;idpay</td>';
            $args['out'] .= '<td><img src=' . JURI::root(true) . '/plugins/system/rsfpidpay/assets/images/logo.png />' . $args['data']['LABEL'] . '</td>';
        }
    }

    function onRsformDoPayment($payValue, $formId, $SubmissionId, $price, $products, $code)
    {
        $app = JFactory::getApplication();

        // Load IDPAY Component IN Form
        $components = RSFormProHelper::componentExists($formId, $this->componentId);
        // Get Attributes  IDPAY Component
        $data = RSFormProHelper::getComponentProperties($components[0]);

        // Calculate  Total Order Price
        if ($data['TOTAL'] == 'YES') {
            $price = (int)$_POST['form']['rsfp_Total'];
        } elseif ($data['TOTAL'] == 'NO') {
            if ($data['FIELDNAME'] == 'Select the desired field') {
                $msg = 'فیلدی برای قیمت انتخاب نشده است.';
                $this->redirectTo($app, "index.php?option=com_rsform&formId={$formId}", $msg, 'Error');
            }
            $price = $_POST['form'][$data['FIELDNAME']];
        }

        if (is_array($price))
            $price = (int)array_sum($price);

        if (!$price) {
            $msg = 'مبلغی وارد نشده است';
            $this->redirectTo($app, "index.php?option=com_rsform&formId={$formId}", $msg, 'Error');
        }

        $currency = RSFormProHelper::getConfig('idpay.currency');
        $price = $this->idpayGetAmount($price, $currency);

        if ($price > 1000) {
            $api_key = RSFormProHelper::getConfig('idpay.api');
            $sandbox = RSFormProHelper::getConfig('idpay.sandbox') == 'no' ? 'false' : 'true';
            $amount = $price;
            $desc = 'پرداخت سفارش شماره: ' . $formId;
            $callback = JRoute::_(JUri::base() . "index.php?option=com_rsform&task=plugin&plugin_task=idpay.notify&code={$code}", false);
            //$callback = JURI::root() . 'index.php?option=com_rsform&task=plugin&plugin_task=idpay.notify&code=' . $code;

            if (empty($amount)) {
                $msg = 'واحد پول انتخاب شده پشتیبانی نمی شود.';
                $this->redirectTo($app, "index.php?option=com_rsform&formId={$formId}", $msg, 'Error');
            }
            $user = JFactory::getApplication()->getSession()->get('user');
            $data = array(
                'order_id' => $formId,
                'amount' => $amount,
                'phone' => '',
                'mail' => $user->email,
                'desc' => $desc,
                'callback' => $callback
            );
            $url = 'https://api.idpay.ir/v1.1/payment';
            $options = $this->options($api_key, $sandbox);
            $result = $this->http->post($url, json_encode($data, true), $options);
            $http_status = $result->code;
            $result = json_decode($result->body);

            if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
                $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($result->status));

                $msg = 'خطا هنگام ایجاد تراکنش. وضعیت خطا:' . $http_status . "<br>" .
                       'کد خطا: ' . $result->error_code . ' پیغام خطا ' . $result->error_message;
                $this->redirectTo($app, "index.php?option=com_rsform&formId={$formId}", $msg, 'Error');
            }
            //Save Transaction To DB
            $db = JFactory::getContainer()->get('DatabaseDriver');
            $sql = 'INSERT INTO `#__rsform_submission_values` (FormId, SubmissionId, FieldName,FieldValue) VALUES (' . $formId . ',' . $SubmissionId . ',"IDPAY_TRANSACTION","' . $result->id . '")';
            $db->setQuery($sql);
            $db->execute();
            $app->redirect(JRoute::_($result->link, false));

        } else {
            $msg = 'مبلغ وارد شده کمتر از ۱۰۰۰۰ ریال می باشد';
            $this->redirectTo($app, "index.php?option=com_rsform&formId={$formId}", $msg, 'Error');
        }
    }

    function onRsformFrontendSwitchTasks()
    {
        $app = JFactory::getApplication();
        if ($app->input->getCmd('plugin_task') == 'idpay.notify') {
            $jinput = $app->input;
            //get status result of payment api
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $track_id = $_POST['track_id'];
                $formId = $_POST['order_id'];
                $pid = $_POST['id'];
                $pOrderId = $_POST['order_id'];
                $status = $_POST['status'];
            } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $track_id = $_GET['track_id'];
                $formId = $_GET['order_id'];
                $pid = $_GET['id'];
                $pOrderId = $_GET['order_id'];
                $status = $_GET['status'];
            }

            $code = $jinput->get->get('code', '', 'STRING');
            $db = JFactory::getContainer()->get('DatabaseDriver');
            $db->setQuery("SELECT SubmissionId FROM #__rsform_submissions s WHERE s.FormId='" . $formId . "' AND MD5(CONCAT(s.SubmissionId,s.DateSubmitted)) = '" . $db->escape($code) . "'");
            $SubmissionId = $db->loadResult();
            $components = RSFormProHelper::componentExists($formId, $this->componentId);
            $data = RSFormProHelper::getComponentProperties($components[0]);

            if ($data['TOTAL'] == 'YES') {
                $fieldname = "rsfp_Total";
            } elseif ($data['TOTAL'] == 'NO') {
                $fieldname = $data['FIELDNAME'];
            }

            $price = round($this::getPayerPrice($formId, $SubmissionId, $fieldname), 0);

            //convert to currency
            $currency = RSFormProHelper::getConfig('idpay.currency');
            $price = $this->idpayGetAmount($price, $currency);

            $order_id = $formId;
            if (!empty($pid) && !empty($pOrderId) && $pOrderId == $order_id) {

                //in waiting confirm
                if ($status == 10) {

                    $api_key = RSFormProHelper::getConfig('idpay.api');
                    $sandbox = RSFormProHelper::getConfig('idpay.sandbox') == 'no' ? 'false' : 'true';
                    $data = array('id' => $pid, 'order_id' => $order_id,);
                    $url = 'https://api.idpay.ir/v1.1/payment/verify';
                    $options = $this->options($api_key, $sandbox);

                    // send request and get result
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
                        $msg = $this->idpayGetFailedMessage($verify_track_id, $order_id, $verify_status);
                        $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($verify_status));
                        $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                        $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');

                        //successful verify
                    } else {

                        //check double spending
                        $db = JFactory::getContainer()->get('DatabaseDriver');
                        $sql = 'SElECT FieldValue FROM ' . "#__rsform_submission_values" . '  WHERE FieldName="IDPAY_TRANSACTION" AND FormId=' . $formId . ' AND SubmissionId = ' . $SubmissionId;
                        $db->setQuery($sql);
                        $db->execute();
                        $exist = $db->loadObjectList();
                        var_dump($exist);
                        $exist = count($exist);

                        if ($verify_order_id !== $order_id or !$exist) {
                            $msg = $this->idpayGetFailedMessage($verify_track_id, $order_id, 0);
                            $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages(0));
                            $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                        }

                        $mainframe = JFactory::getApplication();
                        $mainframe->triggerEvent('rsfp_afterConfirmPayment', array($SubmissionId));
                        $msgForSaveDataTDataBase = $this->otherStatusMessages($verify_status) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                        $this->updateAfterEvent($formId, $SubmissionId, $msgForSaveDataTDataBase);
                        $msg = $this->idpayGetSuccessMessage($verify_track_id, $order_id, $verify_status);

                        $app->enqueueMessage(JText::_($msg), 'success');
                        $app->redirect(JRoute::_("index.php?option=com_rsform&formId={$formId}", false));

                        //  $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                        //  $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'success');
                    }

                } else {
                    //save pay failed pay message(payment api)
                    $msg = $this->idpayGetFailedMessage($track_id, $order_id, $status);
                    $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($status));
                    $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                    $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                }

            } else {

                $msg = $this->idpayGetFailedMessage($track_id, $order_id, $status);
                $this->updateAfterEvent($formId, $SubmissionId, $this->otherStatusMessages($status));
                $link = JURI::root() . 'index.php?option=com_rsform&formId=' . $formId;
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');

            }

        } else {
            return NULL;
        }
    }

    function onRsformBackendAfterShowConfigurationTabs($tabs)
    {
        $lang = JFactory::getApplication()->getLanguage();
        $lang->load('plg_system_rsfpidpay');
        $tabs->addTitle('IDPAY Gateway', 'form-TRANGELIDPAY');
        $tabs->addContent($this->ConfigurationScreen());
    }

    function loadFormData()
    {
        return [
            'idpay.api' => RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.api')),
            'idpay.sandbox' => RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.sandbox')),
            'idpay.currency' => RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.currency')),
            'idpay.success_massage' => RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.success_massage')),
            'idpay.failed_massage' => RSFormProHelper::htmlEscape(RSFormProHelper::getConfig('idpay.failed_massage'))
        ];
    }

    function ConfigurationScreen()
    {
        ob_start();
        JForm::addFormPath(__DIR__ . '/forms');
        $form = new JForm('plg_system_rsfpidpay.configuration', array('control' => 'rsformConfig'));
        $form->loadFile('configuration', array('control' => 'rsformConfig'), false, false);
        $form->bind($this->loadFormData());
        ?>
        <div id="page-paypal" class="form-horizontal">
            <?php
            foreach ($form->getFieldsets() as $fieldset) {
                if ($fields = $form->getFieldset($fieldset->name)) {
                    foreach ($fields as $field) {
                        echo $field->renderField();
                    }
                }
            }
            ?>
            <div class="alert alert-info">Help : Failed Payment Message & Success Payment Message</div>
            <div class="alert alert-success">
                پیامی که می خواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت
                کدهای {order_id} برای نمایش شماره سفارش و {track_id} برای نمایش کد رهگیری آیدی پی استفاده
                نمایید.
            </div>
            <div class="alert alert-error">
                پیامی که می خواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد کنید. همچنین می توانید از
                شورت کدهای {order_id} برای نمایش شماره سفارش و {track_id} برای نمایش کد رهگیری آیدی پی استفاده
                نمایید.
            </div>
        </div>
        <?php
        $contents = ob_get_contents();
        ob_end_clean();
        return $contents;
    }

    function getPayerPrice($formId, $SubmissionId, $fieldName)
    {
        $db = JFactory::getContainer()->get('DatabaseDriver');
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

    public function idpayGetFailedMessage($track_id, $order_id, $msgNumber = null)
    {
        //get defult massege
        $failedMassage = RSFormProHelper::getConfig('idpay.failed_massage');
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $failedMassage) . "<br>" . "$msg";
    }

    public function idpayGetSuccessMessage($track_id, $order_id)
    {
        //get defult success massage
        $successMassage = RSFormProHelper::getConfig('idpay.success_massage');
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $successMassage);
    }

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
            case "4":
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

    function idpayGetAmount($amount, $currency)
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

    public function updateAfterEvent($formId, $SubmissionId, $msg)
    {
        if (!$SubmissionId) {
            return false;
        }
        $db = JFactory::getContainer()->get('DatabaseDriver');
        $msg = "idpay: $msg";
        $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue=1 WHERE sv.FieldName='_STATUS' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
        $db->execute();
        $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue='" . $msg . "'  WHERE sv.FieldValue='idpay' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
        $db->execute();
    }

}
