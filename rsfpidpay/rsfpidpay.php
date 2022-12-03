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
use Joomla\Event\Event;

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

    function isNotDoubleSpending($submission_id, $order_id, $trans_id)
    {
        $reference = JFactory::getContainer()->get('DatabaseDriver');
        $sql = 'SElECT FieldValue AS IdpayTransaction FROM ' . "#__rsform_submission_values" . '  WHERE FieldName="IDPAY_TRANSACTION" AND FormId=' . $order_id . ' AND SubmissionId = ' . $submission_id;
        $reference->setQuery($sql);
        $reference->execute();
        $data = $reference->loadObjectList();
        if (count($data) == 1) {
            return reset($data)->IdpayTransaction == $trans_id;
        }
        return false;
    }

    function redirectTo(&$application, $url, $message, $messageType)
    {
        $application->enqueueMessage(JText::_($message), $messageType);
        return $application->redirect(JRoute::_(JUri::base() . $url, false));
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

    function onRsformDoPayment($payValue, $formId, $SubmissionId, $price, $products, $code) //Payment Function
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

    function onRsformFrontendSwitchTasks() //Callback Payment Function
    {
        $app = JFactory::getApplication();
        if ($app->input->getCmd('plugin_task') == 'idpay.notify') {

            $track_id = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST['track_id'] : $_GET['track_id'];
            $form_id = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST['order_id'] : $_GET['order_id'];
            $trans_id = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST['id'] : $_GET['id'];
            $status = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST['status'] : $_GET['status'];
            $order_id = $form_id;
            $code = $app->input->get->get('code', '', 'STRING');

            // Fetch SubmissionId
            $db = JFactory::getContainer()->get('DatabaseDriver');
            $db->setQuery("SELECT SubmissionId FROM #__rsform_submissions s WHERE s.FormId='" . $form_id . "' AND MD5(CONCAT(s.SubmissionId,s.DateSubmitted)) = '" . $db->escape($code) . "'");
            $submission_id = $db->loadResult();
            $components = RSFormProHelper::componentExists($form_id, $this->componentId);
            $data = RSFormProHelper::getComponentProperties($components[0]);

            $fieldName = $data['TOTAL'] == 'YES' ? "rsfp_Total" : ($data['TOTAL'] == 'NO' ? $data['FIELDNAME'] : null);
            $price = $this::getPayerPrice($form_id, $submission_id, $fieldName);

            //convert to currency
            $currency = RSFormProHelper::getConfig('idpay.currency');
            $price = $this->idpayGetAmount($price, $currency);

            if (!empty($trans_id) && !empty($order_id) && !empty($track_id) && !empty($status)) {

                if ($status == 10 && $this->isNotDoubleSpending($submission_id, $order_id, $trans_id) == true) {

                    $api_key = RSFormProHelper::getConfig('idpay.api');
                    $sandbox = RSFormProHelper::getConfig('idpay.sandbox') == 'no' ? 'false' : 'true';
                    $data = array(
                            'id' => $trans_id,
                            'order_id' => $order_id)
                    ;
                    $url = 'https://api.idpay.ir/v1.1/payment/verify';
                    $options = $this->options($api_key, $sandbox);
                    $result = $this->http->post($url, json_encode($data, true), $options);
                    $http_status = $result->code;
                    $result = json_decode($result->body);

                    if ($http_status != 200) {
                        $this->updateAfterEvent($form_id, $submission_id, $this->otherStatusMessages($status));

                        $msg = sprintf('خطا هنگام بررسی وضعیت تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
                        $this->redirectTo($app, "index.php?option=com_rsform&formId={$form_id}", $msg, 'Error');
                    }

                    $verify_status = empty($result->status) ? NULL : $result->status;
                    $verify_track_id = empty($result->track_id) ? NULL : $result->track_id;
                    $verify_order_id = empty($result->order_id) ? NULL : $result->order_id;
                    $verify_amount = empty($result->amount) ? NULL : $result->amount;
                    $hashed_card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;
                    $card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;

                    if (empty($verify_status) || empty($verify_track_id) || empty($verify_amount) || $verify_amount != $price || $verify_status < 100) {
                        $this->updateAfterEvent($form_id, $submission_id, $this->otherStatusMessages($verify_status));

                        $msg = $this->idpayGetFailedMessage($verify_track_id, $order_id, $verify_status);
                        $this->redirectTo($app, "index.php?option=com_rsform&formId={$form_id}", $msg, 'Error');
                    } else {

                        if ($verify_order_id !== $order_id) {
                            $this->updateAfterEvent($form_id, $submission_id, $this->otherStatusMessages(0));

                            $msg = $this->idpayGetFailedMessage($verify_track_id, $order_id, 0);
                            $this->redirectTo($app, "index.php?option=com_rsform&formId={$form_id}", $msg, 'Error');
                        }

                        $dispatcher = Joomla\CMS\Factory::getApplication()->getDispatcher();
                        $event = new Event('rsfp_afterConfirmPayment', array($submission_id));
                        $res = $dispatcher->dispatch('onCheckAnswer', $event);

                        $msgForSaveDataTDataBase = $this->otherStatusMessages($verify_status) . PHP_EOL . "کد پیگیری :  $verify_track_id " . PHP_EOL . "شماره کارت :  $card_no ";
                        $this->updateAfterEvent($form_id, $submission_id, $msgForSaveDataTDataBase);

                        $msg = $this->idpayGetSuccessMessage($verify_track_id, $order_id);
                        $this->redirectTo($app, "index.php?option=com_rsform&formId={$form_id}", $msg, 'success');
                    }

                } else {
                    $this->updateAfterEvent($form_id, $submission_id, $this->otherStatusMessages($status));

                    $msg = $this->idpayGetFailedMessage($track_id, $order_id, $status);
                    $this->redirectTo($app, "index.php?option=com_rsform&formId={$form_id}", $msg, 'Error');
                }

            } else {
                $this->updateAfterEvent($form_id, $submission_id, $this->otherStatusMessages($status));

                $msg = $this->idpayGetFailedMessage($track_id, $order_id, $status);
                $this->redirectTo($app, "index.php?option=com_rsform&formId={$form_id}", $msg, 'Error');
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
        $failedMassage = RSFormProHelper::getConfig('idpay.failed_massage');
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $failedMassage) . "<br>" . "$msg";
    }

    public function idpayGetSuccessMessage($track_id, $order_id)
    {
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
        if (!$SubmissionId) return false;
        $db = JFactory::getContainer()->get('DatabaseDriver');
        $msg = "idpay: $msg";
        $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue=1 WHERE sv.FieldName='_STATUS' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
        $db->execute();
        $db->setQuery("UPDATE #__rsform_submission_values sv SET sv.FieldValue='" . $msg . "'  WHERE sv.FieldValue='idpay' AND sv.FormId='" . $formId . "' AND sv.SubmissionId = '" . $SubmissionId . "'");
        $db->execute();
    }

}
