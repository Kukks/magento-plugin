<?php
/**
 * @license Copyright 2011-2014 BitPay Inc., MIT License
 * @see https://github.com/bitpay/magento-plugin/blob/master/LICENSE
 */

/**
 * @route /bitpay/ipn
 */
class Bitpay_Core_IpnController extends Mage_Core_Controller_Front_Action
{
    /**
     * bitpay's IPN lands here
     *
     * @route /bitpay/ipn
     * @route /bitpay/ipn/index
     */
    public function indexAction()
    {
        if (false === ini_get('allow_url_fopen')) {
            ini_set('allow_url_fopen', true);
        }

        $raw_post_data = file_get_contents('php://input');

        if (false === $raw_post_data) {
            \Mage::helper('bitpay')->debugData('[ERROR] In Bitpay_Core_IpnController::indexAction(), Could not read from the php://input stream or invalid Bitpay IPN received.');
            throw new \Exception('Could not read from the php://input stream or invalid Bitpay IPN received.');
        }

        \Mage::helper('bitpay')->registerAutoloader();

        \Mage::helper('bitpay')->debugData(array(sprintf('[INFO] In Bitpay_Core_IpnController::indexAction(), Incoming IPN message from BitPay: '),$raw_post_data,));

        // Magento doesn't seem to have a way to get the Request body
        $ipn = json_decode($raw_post_data);
        
        if(isset($ipn->data))
        {
            $ipn = $ipn->data;
        }
        
        if (true === empty($ipn)) {
            \Mage::helper('bitpay')->debugData('[ERROR] In Bitpay_Core_IpnController::indexAction(), Could not decode the JSON payload from BitPay.');
            throw new \Exception('Could not decode the JSON payload from BitPay.');
        }

        if (true === empty($ipn->id) || false === isset($ipn->posData)) {
            \Mage::helper('bitpay')->debugData(sprintf('[ERROR] In Bitpay_Core_IpnController::indexAction(), Did not receive order ID in IPN: ', $ipn));
            throw new \Exception('Invalid Bitpay payment notification message received - did not receive order ID.');
        }

        $ipn->posData     = is_string($ipn->posData) ? json_decode($ipn->posData) : $ipn->posData;
        $ipn->buyerFields = isset($ipn->buyerFields) ? $ipn->buyerFields : new stdClass();

        \Mage::helper('bitpay')->debugData($ipn);

        // Log IPN
        $mageIpn = \Mage::getModel('bitpay/ipn')->addData(
            array(
                'invoice_id'       => isset($ipn->id) ? $ipn->id : '',
                'url'              => isset($ipn->url) ? $ipn->url : '',
                'pos_data'         => json_encode($ipn->posData),
                'status'           => isset($ipn->status) ? $ipn->status : '',
                'price'            => isset($ipn->price) ? $ipn->price : '',
                'currency'         => isset($ipn->currency) ? $ipn->currency : '',
                'invoice_time'     => isset($ipn->invoiceTime) ? intval($ipn->invoiceTime / 1000) : '',
                'expiration_time'  => isset($ipn->expirationTime) ? intval($ipn->expirationTime / 1000) : '',
                'current_time'     => isset($ipn->currentTime) ? intval($ipn->currentTime / 1000) : '',
                'exception_status' => isset($ipn->exceptionStatus) ? $ipn->exceptionStatus : '',
                'transactionCurrency' => isset($ipn->transactionCurrency) ? $ipn->transactionCurrency : ''
            )
        )->save();


        // Order isn't being created for iframe...
        if (isset($ipn->posData->orderId)) {
            $order = \Mage::getModel('sales/order')->loadByIncrementId($ipn->posData->orderId);
        } else {
            $order = \Mage::getModel('sales/order')->load($ipn->posData->quoteId, 'quote_id');
        }

        if (false === isset($order) || true === empty($order)) {
            \Mage::helper('bitpay')->debugData('[ERROR] In Bitpay_Core_IpnController::indexAction(), Invalid Bitpay IPN received.');
            \Mage::throwException('Invalid Bitpay IPN received.');
        }

        $orderId = $order->getId();
        if (false === isset($orderId) || true === empty($orderId)) {
            \Mage::helper('bitpay')->debugData('[ERROR] In Bitpay_Core_IpnController::indexAction(), Invalid Bitpay IPN received.');
            \Mage::throwException('Invalid Bitpay IPN received.');
        }

        /**
         * Ask BitPay to retreive the invoice so we can make sure the invoices
         * match up and no one is using an automated tool to post IPN's to merchants
         * store.
         */
        $invoice = \Mage::getModel('bitpay/method_bitcoin')->fetchInvoice($ipn->id);

        if (false === isset($invoice) || true === empty($invoice)) {
            \Mage::helper('bitpay')->debugData('[ERROR] In Bitpay_Core_IpnController::indexAction(), Could not retrieve the invoice details for the ipn ID of ' . $ipn->id);
            \Mage::throwException('Could not retrieve the invoice details for the ipn ID of ' . $ipn->id);
        }

        // Does the status match?
       /* if ($invoice->getStatus() != $ipn->status) {
            \Mage::getModel('bitpay/method_bitcoin')->debugData('[ERROR] In Bitpay_Core_IpnController::indexAction(), IPN status and status from BitPay are different. Rejecting this IPN!');
            \Mage::throwException('There was an error processing the IPN - statuses are different. Rejecting this IPN!');
        }*/

        // Does the price match?
        if ($invoice->getPrice() != $ipn->price) {
            \Mage::getModel('bitpay/method_bitcoin')>debugData('[ERROR] In Bitpay_Core_IpnController::indexAction(), IPN price and invoice price are different. Rejecting this IPN!');
            \Mage::throwException('There was an error processing the IPN - invoice price does not match the IPN price. Rejecting this IPN!');
        }

        // use state as defined by Merchant
        $state = \Mage::getStoreConfig(sprintf('payment/bitpay/invoice_%s', $invoice->getStatus()));

        if (false === isset($state) || true === empty($state)) {
            \Mage::helper('bitpay')->debugData('[ERROR] In Bitpay_Core_IpnController::indexAction(), Could not retrieve the defined state parameter to update this order to in the Bitpay IPN controller.');
            \Mage::throwException('Could not retrieve the defined state parameter to update this order in the Bitpay IPN controller.');
        }

        // Check if status should be updated
        switch ($order->getStatus()) {
            case Mage_Sales_Model_Order::STATE_CANCELED:
            case Mage_Sales_Model_Order::STATUS_FRAUD: 
            case Mage_Sales_Model_Order::STATE_CLOSED: 
            case Mage_Sales_Model_Order::STATE_COMPLETE: 
            case Mage_Sales_Model_Order::STATE_HOLDED:
                // Do not Update 
                break;
            case Mage_Sales_Model_Order::STATE_PENDING_PAYMENT: 
            case Mage_Sales_Model_Order::STATE_PROCESSING: 
            default:
                $order->addStatusToHistory(
                    $state,
                    sprintf('[INFO] In Bitpay_Core_IpnController::indexAction(), Incoming IPN status "%s" updated order state to "%s"', $invoice->getStatus(), $state)
                )->save();
                break;
        }
        
        if($ipn->status == 'expired')
        {
            $order->cancel();
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Cancel Transaction.');
            $order->setStatus("canceled");
            $order->save();
        }

        $order_confirmation = \Mage::getStoreConfig('payment/bitpay/order_confirmation');
        if($order_confirmation == '1')
        {
            $order->sendNewOrderEmail();
        }

    }
}
