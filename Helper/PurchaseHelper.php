<?php
/**
 * Copyright 2015 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Signifyd\Connect\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use \Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order\Item;
use Signifyd\Core\SignifydModel;
use Signifyd\Models\Address as SignifydAddress;
use Signifyd\Models\Card;
use Signifyd\Models\CaseModel;
use Signifyd\Models\Product;
use Signifyd\Models\Purchase;
use Signifyd\Models\Recipient;
use Signifyd\Models\UserAccount;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\App\ProductMetadata;
use Magento\Customer\Model\Customer;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;

/**
 * Class PurchaseHelper
 * Handles the conversion from Magento Order to Signifyd Case and sends to Signifyd service.
 * @package Signifyd\Connect\Helper
 */
class PurchaseHelper
{
    /**
     * @var \Signifyd\Connect\Helper\LogHelper
     */
    protected $_logger;

    /**
     * @var \Signifyd\Core\SignifydAPI
     */
    protected $_api;

    /**
     * @var DateTime
     */
    protected $_dateTime;

    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $_moduleList;

    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress
     */
    protected $_remoteAddress;

    /**
     * @var
     */
    protected $_protectedMetadata;

    /**
     * @var Customer
     */
    protected $_customer;

    /**
     * @var Casedata
     */
    protected $_caseData;

    /**
     * @var OrderCollection
     */
    protected $_orderCollection;

    /**
     * PurchaseHelper constructor.
     * @param ObjectManagerInterface $objectManager
     * @param LogHelper $logger
     * @param DateTime $dateTime
     * @param ScopeConfigInterface $scopeConfig
     * @param SignifydAPIMagento $api
     * @param ModuleListInterface $moduleList
     * @param RemoteAddress $remoteAddress
     * @param ProductMetadata $productMetadata
     * @param Customer $customer
     * @param OrderCollection $orderCollection
     * @param Casedata $caseData
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        LogHelper $logger,
        DateTime $dateTime,
        ScopeConfigInterface $scopeConfig,
        SignifydAPIMagento $api,
        ModuleListInterface $moduleList,
        RemoteAddress $remoteAddress,
        ProductMetadata $productMetadata,
        Customer $customer,
        OrderCollection $orderCollection,
        Casedata $caseData
    ) {
        $this->_logger = $logger;
        $this->_objectManager = $objectManager;
        $this->_moduleList = $moduleList;
        $this->_remoteAddress = $remoteAddress;
        $this->_productMetadata = $productMetadata;
        $this->_customer = $customer;
        $this->_orderCollection = $orderCollection;
        $this->_caseData = $caseData;
        try {
            $this->_api = $api;

        } catch (\Exception $e) {
            $this->_logger->error($e);
        }

    }

    /**
     * Getting the ip address of the order
     * @param Order $order
     * @return mixed
     */
    protected function getIPAddress(Order $order)
    {
        if ($order->getRemoteIp()) {
            if ($order->getXForwardedFor()) {
                return $this->filterIp($order->getXForwardedFor());
            }

            return $this->filterIp($order->getRemoteIp());
        }

        return $this->filterIp($this->_remoteAddress->getRemoteAddress());
    }

    /**
     * Filter the ip address
     * @param $ip
     * @return mixed
     */
    protected function filterIp($ipString)
    {
        $matches = array();

        $pattern = '(([0-9]{1,3}(?:\.[0-9]{1,3}){3})|([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))';

        preg_match_all($pattern, $ipString, $matches);

        if (is_array($matches)) return $matches[0][0];

        return null;
    }

    /**
     * Getting the version of Magento and the version of the extension
     * @return array
     */
    protected function getVersions()
    {
        $version = array();
        $version['storePlatformVersion'] = $this->_productMetadata->getVersion();
        $version['signifydClientApp'] = 'Magento 2';
        $version['storePlatform'] = 'Magento 2';
        $version['signifydClientAppVersion'] = (string)($this->_moduleList->getOne('Signifyd_Connect')['setup_version']);
        return $version;
    }

    /**
     * @param Item $item
     * @return Product
     */
    protected function makeProduct(Item $item)
    {
        $product = SignifydModel::Make("\\Signifyd\\Models\\Product");
        $product->itemId = $item->getSku();
        $product->itemName = $item->getName();
        $product->itemPrice = $item->getPrice();
        $product->itemQuantity = (int)$item->getQtyOrdered();
        $product->itemUrl = $item->getProduct()->getProductUrl();
        $product->itemWeight = $item->getProduct()->getWeight();
        return $product;
    }

    /**
     * @param $order Order
     * @return Purchase
     */
    protected function makePurchase(Order $order)
    {
        // Get all of the purchased products
        $items = $order->getAllItems();
        $purchase = SignifydModel::Make("\\Signifyd\\Models\\Purchase");
        $purchase->orderChannel = "WEB";
        $purchase->products = array();
        foreach ($items as $item) {
            $purchase->products[] = $this->makeProduct($item);
        }

        $purchase->totalPrice = $order->getGrandTotal();
        $purchase->currency = $order->getOrderCurrencyCode();
        $purchase->orderId = $order->getIncrementId();
        $purchase->paymentGateway = $order->getPayment()->getMethod();
        $purchase->shippingPrice = floatval($order->getShippingAmount());
        $purchase->avsResponseCode = $order->getPayment()->getCcAvsStatus();
        $purchase->cvvResponseCode = $order->getPayment()->getCcSecureVerify();
        $purchase->createdAt = date('c', strtotime($order->getCreatedAt()));

        $purchase->browserIpAddress = $this->getIPAddress($order);

        return $purchase;
    }

    /**
     * @param $mageAddress Address
     * @return SignifydAddress
     */
    protected function formatSignifydAddress($mageAddress)
    {
        $address = SignifydModel::Make("\\Signifyd\\Models\\Address");

        $address->streetAddress = $mageAddress->getStreetLine(1);
        $address->unit = $mageAddress->getStreetLine(2);

        $address->city = $mageAddress->getCity();

        $address->provinceCode = $mageAddress->getRegionCode();
        $address->postalCode = $mageAddress->getPostcode();
        $address->countryCode = $mageAddress->getCountryId();

        $address->latitude = null;
        $address->longitude = null;

        return $address;
    }

    /**
     * @param $order Order
     * @return Recipient|null
     */
    protected function makeRecipient(Order $order)
    {
        $address = $order->getShippingAddress();

        if ($address == null) {
            return null;
        }

        $recipient = SignifydModel::Make("\\Signifyd\\Models\\Recipient");
        $recipient->deliveryAddress = $this->formatSignifydAddress($address);
        $recipient->fullName = $address->getName();
        $recipient->confirmationPhone = $address->getTelephone();
        $recipient->confirmationEmail = $address->getEmail();
        return $recipient;
    }

    /**
     * @param $order Order
     * @return Card|null
     */
    protected function makeCardInfo(Order $order)
    {
        $payment = $order->getPayment();
        $this->_logger->debug('Signifyd: Payment: ' . $payment->convertToJson());

        $billingAddress = $order->getBillingAddress();
        $card = SignifydModel::Make("\\Signifyd\\Models\\Card");
        $card->cardHolderName = $billingAddress->getFirstname() . '  ' . $billingAddress->getLastname();
        if(!is_null($payment->getCcLast4())){
            if(!is_null($payment->getCcOwner())){
                $card->cardHolderName = $payment->getCcOwner();
            }

            $card->last4 = $payment->getCcLast4();
            $card->expiryMonth = $payment->getCcExpMonth();
            $card->expiryYear = $payment->getCcExpYear();
            $card->hash = $payment->getCcNumberEnc();

            $ccNum = $payment->getData('cc_number');
            if ($ccNum && is_numeric($ccNum) && strlen((string)$ccNum) > 6) {
                $card->bin = substr((string)$ccNum, 0, 6);
            }
        }

        $card->billingAddress = $this->formatSignifydAddress($billingAddress);
        return $card;
    }


    /** Construct a user account blob
     * @param $order Order
     * @return UserAccount
     */
    protected function makeUserAccount(Order $order)
    {
        /* @var $user \Signifyd\Models\UserAccount */
        $user = SignifydModel::Make("\\Signifyd\\Models\\UserAccount");
        $user->emailAddress = $order->getCustomerEmail();
        $user->username = $order->getCustomerEmail();
        $user->accountNumber = $order->getCustomerId();
        $user->phone = $order->getBillingAddress()->getTelephone();


        $customer = $this->_customerload($order->getCustomerId());
        $this->_logger->debug("Customer data: " . json_encode($customer));
        if(!is_null($customer) && !$customer->isEmpty()) {
            $user->createdDate = date('c', strtotime($customer->getCreatedAt()));
        }

        $orders = $this->orderCollection->addFieldToFilter('customer_id', $order->getCustomerId())->load();

        $orderCount = 0;
        $orderTotal = 0.0;

        /** @var $o \Magento\Sales\Model\Order*/
        foreach($orders as $o) {
            $orderCount++;
            $orderTotal += floatval($o->getGrandTotal());
        }

        $user->aggregateOrderCount = $orderCount;
        $user->aggregateOrderDollars = $orderTotal;

        return $user;
    }

    /**
     * Loading the case
     * @param Order $order
     * @return \Signifyd\Connect\Model\Casedata
     */
    public function getCase(Order $order)
    {
        return $this->_caseDataObj->load($order->getIncrementId());
    }

    /**
     * Check if the related case exists
     * @param Order $order
     * @return bool
     */
    public function doesCaseExist(Order $order)
    {
        /** @var $case \Signifyd\Connect\Model\Casedata */
        $case = $this->getCase($order);
        return !($case->isEmpty() || $case->isObjectNew());
    }

    /**
     * Construct a new case object
     * @param $order Order
     * @return CaseModel
     */
    public function processOrderData($order)
    {
        $case = SignifydModel::Make("\\Signifyd\\Models\\CaseModel");
        $case->card = $this->makeCardInfo($order);
        $case->purchase = $this->makePurchase($order);
        $case->recipient = $this->makeRecipient($order);
        $case->userAccount = $this->makeUserAccount($order);
        $case->clientVersion = $this->getVersions();
        return $case;
    }

    /**
     * Saving the case to the database
     * @param $order
     * @return \Signifyd\Connect\Model\Casedata
     */
    public function createNewCase($order)
    {
        $case = $this->_caseData->setId($order->getIncrementId())
            ->setSignifydStatus("PENDING")
            ->setCreated(strftime('%Y-%m-%d %H:%M:%S', time()))
            ->setUpdated(strftime('%Y-%m-%d %H:%M:%S', time()))
            ->setEntriesText("");
        $case->save();
        return $case;
    }

    /**
     * @param $caseData
     * @return bool
     */
    public function postCaseToSignifyd($caseData, $order)
    {
        $this->_logger->request("Sending: " . json_encode($caseData));
        $id = $this->_api->createCase($caseData);

        if ($id) {
            $this->_logger->debug("Case sent. Id is $id");
        } else {
            $this->_logger->error("Case failed to send.");
            return false;
        }

        return $id;
    }

    /**
     * Check if case has guaranty
     * @param $order
     * @return bool
     */
    public function hasGuaranty($order)
    {
        $case = $this->getCase($order);
        return ($case->getGuarantee() == 'N/A')? false : true;
    }

}
