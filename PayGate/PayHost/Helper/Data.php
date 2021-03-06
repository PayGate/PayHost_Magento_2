<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Helper;

use Exception;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\BaseFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PayGate\PayHost\Model\Config as PayGateConfig;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * PayGate Data helper
 */
class Data extends AbstractHelper
{
    const PAYHOSTURL = "https://secure.paygate.co.za/payhost/process.trans";

    /**
     * Cache for shouldAskToCreateBillingAgreement()
     *
     * @var bool
     */
    protected static $_shouldAskToCreateBillingAgreement = false;

    /**
     * @var \Magento\Payment\Helper\Data
     */
    protected $_paymentData;
    /**
     * @var LoggerInterface
     */
    protected $_logger;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var CurrencyFactory
     */
    protected $currencyFactory;
    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
     */
    protected $_transactionBuilder;
    /**
     * @var TransactionSearchResultInterfaceFactory
     */
    protected $transactionSearchResultInterfaceFactory;
    /**
     * @var OrderSender
     */
    protected $OrderSender;
    /**
     * @var InvoiceService
     */
    protected $_invoiceService;
    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;
    /**
     * @var DBTransaction
     */
    protected $dbTransaction;
    /**
     * @var array
     */
    private $methodCodes;
    /**
     * @var ConfigFactory
     */
    private $configFactory;
    /**
     * @var ConfigFactory
     */
    private $_paygateconfig;

    /**
     * @param Context $context
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param BaseFactory $configFactory
     * @param StoreManagerInterface $storeManager
     * @param CurrencyFactory $currencyFactory
     * @param array $methodCodes
     */
    public function __construct(
        Context $context,
        \Magento\Payment\Helper\Data $paymentData,
        BaseFactory $configFactory,
        PayGateConfig $paygateconfig,
        StoreManagerInterface $storeManager,
        CurrencyFactory $currencyFactory,
        Builder $_transactionBuilder,
        TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory,
        OrderSender $OrderSender,
        DBTransaction $dbTransaction,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        array $methodCodes
    ) {
        $this->_logger = $context->getLogger();

        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof, methodCodes is : ', $methodCodes);

        $this->_paymentData   = $paymentData;
        $this->methodCodes    = $methodCodes;
        $this->configFactory  = $configFactory;
        $this->_paygateconfig = $paygateconfig;

        /* Currency Converter */
        $this->storeManager    = $storeManager;
        $this->currencyFactory = $currencyFactory;

        parent::__construct($context);
        $this->_logger->debug($pre . 'eof');

        $this->transactionSearchResultInterfaceFactory = $transactionSearchResultInterfaceFactory;
        $this->_transactionBuilder                     = $_transactionBuilder;
        $this->OrderSender                             = $OrderSender;
        $this->_invoiceService                         = $invoiceService;
        $this->invoiceSender                           = $invoiceSender;
        $this->dbTransaction                           = $dbTransaction;
    }

    /**
     * Check whether customer should be asked confirmation whether to sign a billing agreement
     * should always return false.
     *
     * @return bool
     */
    public function shouldAskToCreateBillingAgreement()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . "bof");
        $this->_logger->debug($pre . "eof");

        return self::$_shouldAskToCreateBillingAgreement;
    }

    /**
     * Retrieve available billing agreement methods
     *
     * @param null|string|bool|int|Store $store
     * @param Quote|null $quote
     *
     * @return MethodInterface[]
     */
    public function getBillingAgreementMethods($store = null, $quote = null)
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $result = [];
        foreach ($this->_paymentData->getStoreMethods($store, $quote) as $method) {
            if ($method instanceof MethodInterface) {
                $result[] = $method;
            }
        }
        $this->_logger->debug($pre . 'eof | result : ', $result);

        return $result;
    }

    /*
    ** Convert Currency to Order Currency
    ** If both currency are same dont do any changes
    ** store_currency_code & order_currency_code are fields in sales_order table
    */
    public function convertToOrderCurrency($order, $price)
    {
        $storeCurrency = $order->getStoreCurrencyCode();
        $orderCurrency = $order->getOrderCurrencyCode();
        if ($storeCurrency != $orderCurrency) {
            $rate = $this->currencyFactory->create()->load($storeCurrency)->getAnyRate($orderCurrency);

            return $price * $rate;
        }

        return $price;
    }

    public function getTransactionData($payment, $txn_id)
    {
        $transactionSearchResult = $this->transactionSearchResultInterfaceFactory;

        return $transactionSearchResult->create()->addPaymentIdFilter($payment->getId())->getFirstItem();
    }

    public function createTransaction($order = null, $paymentData = array())
    {
        try {
            // Get payment object from order object
            $payment = $order->getPayment();
            $payment->setLastTransId($paymentData['PAY_REQUEST_ID'])
                    ->setTransactionId($paymentData['PAY_REQUEST_ID'])
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => (array)$paymentData]
                    );
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __('The authorized amount is %1.', $formatedPrice);
            // Get the object of builder class
            $trans       = $this->_transactionBuilder;
            $transaction = $trans->setPayment($payment)
                                 ->setOrder($order)
                                 ->setTransactionId($paymentData['PAY_REQUEST_ID'])
                                 ->setAdditionalInformation(
                                     [Transaction::RAW_DETAILS => (array)$paymentData]
                                 )
                                 ->setFailSafe(true)
                // Build method creates the transaction and returns the object
                                 ->build(Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);
            $payment->save();
            $order->save();

            return $transaction->save()->getTransactionId();
        } catch (Exception $e) {
            $this->_logger->error($e->getMessage());
        }
    }

    public function getConfigData($field)
    {
        return $this->_paygateconfig->getConfig($field);
    }

    public function getPayGateCredentials()
    {
        // If NOT test mode, use normal credentials
        $cred = array();
        if ($this->getConfigData('test_mode') != '1') {
            $cred['paygateId'] = $this->getConfigData('paygate_id');
            $cred['password']  = $this->getConfigData('encryption_key');
        } else {
            $cred['paygateId'] = '10011072130';
            $cred['password']  = 'test';
        }

        return $cred;
    }

    public function getQueryResult($transaction_id)
    {
        $queryFields = $this->prepareQueryXml($transaction_id);
        $response    = $this->curlPost(self::PAYHOSTURL, $queryFields);
        $respArray   = $this->formatXmlToArray($response);
        $ns2Status   = $respArray['ns2SingleFollowUpResponse']['ns2QueryResponse']['ns2Status'];

        return $ns2Status;
    }

    public function curlPost($url, $xml)
    {
        $curl = curl_init();

        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => self::PAYHOSTURL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => "$xml",
                CURLOPT_HTTPHEADER     => array(
                    "Content-Type: text/xml",
                    "SOAPAction: WebPaymentRequest"
                ),
            )
        );

        $response = curl_exec($curl);

        curl_close($curl);

        return $response;
    }

    public function formatXmlToArray($response)
    {
        $response = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
        $xml      = new SimpleXMLElement($response);
        $body     = $xml->xpath('//SOAP-ENV:Body')[0];

        return json_decode(json_encode((array)$body), true);
    }

    public function prepareQueryXml($pay_request_id)
    {
        $cred      = $this->getPayGateCredentials();
        $paygateId = $cred['paygateId'];
        $password  = $cred['password'];

        return '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
                <SOAP-ENV:Header/>
                <SOAP-ENV:Body>
                    <SingleFollowUpRequest xmlns="http://www.paygate.co.za/PayHOST">
						<QueryRequest>
							<Account>
								<PayGateId>' . $paygateId . '</PayGateId>
								<Password>' . $password . '</Password>
							</Account>
							<PayRequestId>' . $pay_request_id . '</PayRequestId>
						</QueryRequest>
					</SingleFollowUpRequest>
                </SOAP-ENV:Body>
            </SOAP-ENV:Envelope>';
    }

    public function updatePaymentStatus($order, $resp)
    {
        if (is_array($resp) && (count($resp) > 0)) {
            if ($resp['ns2TransactionStatusCode'] == 1) {
                $status = Order::STATE_PROCESSING;
                $order->setStatus($status);
                $order->setState($status);
                $order->save();
                try {
                    $this->generateInvoice($order);
                } catch (Exception $ex) {
                    $this->_logger->error($ex->getMessage());
                }
            } else {
                $status = Order::STATE_CANCELED;
                $order->setStatus($status);
                $order->setState($status);
                $order->save();
            }
            $this->createTransaction($order, $resp);
        }
    }

    public function generateInvoice($order)
    {
        $order_successful_email = $this->getConfigData('order_email');

        if ($order_successful_email != '0') {
            $this->OrderSender->send($order);
            $order->addStatusHistoryComment(
                __('Notified customer about order #%1.', $order->getId())
            )->setIsCustomerNotified(true)->save();
        }

        // Capture invoice when payment is successfull
        $invoice = $this->_invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->register();

        // Save the invoice to the order
        $transaction = $this->dbTransaction
            ->addObject($invoice)
            ->addObject($invoice->getOrder());

        $transaction->save();

        // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
        $send_invoice_email = $this->getConfigData('invoice_email');
        if ($send_invoice_email != '0') {
            $this->invoiceSender->send($invoice);
            $order->addStatusHistoryComment(
                __('Notified customer about invoice #%1.', $invoice->getId())
            )->setIsCustomerNotified(true)->save();
        }
    }
}
