<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper;

use Adyen\Payment\Logger\AdyenLogger;
use Exception;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order;

class PaymentResponseHandler
{
    const AUTHORISED = 'Authorised';
    const REFUSED = 'Refused';
    const REDIRECT_SHOPPER = 'RedirectShopper';
    const IDENTIFY_SHOPPER = 'IdentifyShopper';
    const CHALLENGE_SHOPPER = 'ChallengeShopper';
    const RECEIVED = 'Received';
    const PENDING = 'Pending';
    const PRESENT_TO_SHOPPER = 'PresentToShopper';
    const ERROR = 'Error';
    const CANCELLED = 'Cancelled';
    const ADYEN_TOKENIZATION = 'Adyen Tokenization';
    const VAULT = 'Magento Vault';
    const POS_SUCCESS = 'Success';

    const ACTION_REQUIRED_STATUSES = [
        self::REDIRECT_SHOPPER,
        self::IDENTIFY_SHOPPER,
        self::CHALLENGE_SHOPPER,
        self::PENDING
    ];

    /**
     * @var AdyenLogger
     */
    private AdyenLogger $adyenLogger;
    private Vault $vaultHelper;
    private Order $orderResourceModel;
    private Data $dataHelper;
    private Quote $quoteHelper;
    private \Adyen\Payment\Helper\Order $orderHelper;
    private OrderRepository $orderRepository;
    private HistoryFactory $orderHistoryFactory;
    private StateData $stateDataHelper;

    public function __construct(
        AdyenLogger $adyenLogger,
        Vault $vaultHelper,
        Order $orderResourceModel,
        Data $dataHelper,
        Quote $quoteHelper,
        \Adyen\Payment\Helper\Order $orderHelper,
        OrderRepository $orderRepository,
        HistoryFactory $orderHistoryFactory,
        StateData $stateDataHelper
    ) {
        $this->adyenLogger = $adyenLogger;
        $this->vaultHelper = $vaultHelper;
        $this->orderResourceModel = $orderResourceModel;
        $this->dataHelper = $dataHelper;
        $this->quoteHelper = $quoteHelper;
        $this->orderHelper = $orderHelper;
        $this->orderRepository = $orderRepository;
        $this->orderHistoryFactory = $orderHistoryFactory;
        $this->stateDataHelper = $stateDataHelper;
    }

    public function formatPaymentResponse(
        string $resultCode,
        array $action = null,
        array $additionalData = null
    ): array {
        switch ($resultCode) {
            case self::AUTHORISED:
            case self::REFUSED:
            case self::ERROR:
            case self::POS_SUCCESS:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode
                ];
            case self::REDIRECT_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
            case self::PENDING:
                return [
                    "isFinal" => false,
                    "resultCode" => $resultCode,
                    "action" => $action
                ];
            case self::PRESENT_TO_SHOPPER:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode,
                    "action" => $action
                ];
            case self::RECEIVED:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode,
                    "additionalData" => $additionalData
                ];
            default:
                return [
                    "isFinal" => true,
                    "resultCode" => self::ERROR,
                ];
        }
    }

    /**
     * @param array $paymentsDetailsResponse
     * @param OrderInterface $order
     * @return bool
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function handlePaymentsDetailsResponse(
        array $paymentsDetailsResponse,
        OrderInterface $order
    ): bool {
        if (empty($paymentsDetailsResponse)) {
            $this->adyenLogger->error("Payment details call failed, paymentsResponse is empty");
            return false;
        }

        $this->adyenLogger->addAdyenResult('Updating the order');
        $payment = $order->getPayment();

        $authResult = $paymentsDetailsResponse['authResult'] ?? $paymentsDetailsResponse['resultCode'] ?? null;
        if (is_null($authResult)) {
            // In case the result is unknown we log the request and don't update the history
            $this->adyenLogger->error(
                "Unexpected result query parameter. Response: " . json_encode($paymentsDetailsResponse)
            );

            return false;
        }

        $paymentMethod = $paymentsDetailsResponse['paymentMethod']['brand'] ??
            $paymentsDetailsResponse['paymentMethod']['type'] ??
            '';

        $pspReference = isset($paymentsDetailsResponse['pspReference']) ?
            trim((string) $paymentsDetailsResponse['pspReference']) :
            '';

        $type = 'Adyen paymentsDetails response:';
        $comment = __(
            '%1 <br /> authResult: %2 <br /> pspReference: %3 <br /> paymentMethod: %4',
            $type,
            $authResult,
            $pspReference,
            $paymentMethod
        );

        if (!empty($paymentsDetailsResponse['resultCode'])) {
            $payment->setAdditionalInformation('resultCode', $paymentsDetailsResponse['resultCode']);
        }

        if (!empty($paymentsDetailsResponse['action'])) {
            $payment->setAdditionalInformation('action', $paymentsDetailsResponse['action']);
        }

        if (!empty($paymentsDetailsResponse['additionalData'])) {
            $payment->setAdditionalInformation('additionalData', $paymentsDetailsResponse['additionalData']);
        }

        if (!empty($paymentsDetailsResponse['pspReference'])) {
            $payment->setAdditionalInformation('pspReference', $paymentsDetailsResponse['pspReference']);
        }

        if (!empty($paymentsDetailsResponse['details'])) {
            $payment->setAdditionalInformation('details', $paymentsDetailsResponse['details']);
        }

        if (!empty($paymentsDetailsResponse['donationToken'])) {
            $payment->setAdditionalInformation('donationToken', $paymentsDetailsResponse['donationToken']);
        }

        // Handle recurring details
        $this->vaultHelper->handlePaymentResponseRecurringDetails($payment, $paymentsDetailsResponse);

        // If the response is valid, update the order status.
        if (!in_array($paymentsDetailsResponse['resultCode'], PaymentResponseHandler::ACTION_REQUIRED_STATUSES)) {
            /*
             * Change order state from pending_payment to new and expect authorisation webhook
             * if no additional action is required according to /paymentsDetails response.
             * Otherwise keep the order state as pending_payment.
             */
            $order = $this->orderHelper->setStatusOrderCreation($order);
            $this->orderRepository->save($order);
        }

        // Cleanup state data if exists.
        try {
            $this->stateDataHelper->cleanQuoteStateData($order->getQuoteId(), $authResult);
        } catch (Exception $exception) {
            $this->adyenLogger->error(__('Error cleaning the payment state data: %s', $exception->getMessage()));
        }

        switch ($paymentsDetailsResponse['resultCode']) {
            case self::AUTHORISED:
                if (!empty($paymentsDetailsResponse['pspReference'])) {
                    // set pspReference as transactionId
                    $payment->setCcTransId($paymentsDetailsResponse['pspReference']);
                    $payment->setLastTransId($paymentsDetailsResponse['pspReference']);

                    // set transaction
                    $payment->setTransactionId($paymentsDetailsResponse['pspReference']);
                }

                try {
                    $this->quoteHelper->disableQuote($order->getQuoteId());
                } catch (Exception $e) {
                    $this->adyenLogger->error('Failed to disable quote: ' . $e->getMessage(), [
                        'quoteId' => $order->getQuoteId()
                    ]);
                }

                $result = true;
                break;
            case self::PENDING:
                /* Change order state from pending_payment to new and expect authorisation webhook
                 * if no additional action is required according to /paymentDetails response. */
                $order = $this->orderHelper->setStatusOrderCreation($order);
                $this->orderRepository->save($order);

                // do nothing wait for the notification
                if (strpos((string) $paymentMethod, "bankTransfer") !== false) {
                    $comment .= "<br /><br />Waiting for the customer to transfer the money.";
                } elseif ($paymentMethod == "sepadirectdebit") {
                    $comment .= "<br /><br />This request will be send to the bank at the end of the day.";
                } else {
                    $comment .= "<br /><br />The payment result is not confirmed (yet).
                                 <br />Once the payment is authorised, the order status will be updated accordingly.
                                 <br />If the order is stuck on this status, the payment can be seen as unsuccessful.
                                 <br />The order can be automatically cancelled based on the OFFER_CLOSED notification.
                                 Please contact Adyen Support to enable this.";
                }
                $this->adyenLogger->addAdyenResult('Do nothing wait for the notification');

                $result = true;
                break;
            case self::PRESENT_TO_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
            case self::REDIRECT_SHOPPER:
                $this->adyenLogger->addAdyenResult("Additional action is required for the payment.");
                $result = true;
                break;
            case self::RECEIVED:
                $result = true;
                if (str_contains((string)$paymentMethod, "alipay_hk")) {
                    $result = false;
                }
                $this->adyenLogger->addAdyenResult('Do nothing wait for the notification');
                break;
            case self::REFUSED:
            case self::CANCELLED:
                // Cancel order in case result is refused
                if (null !== $order) {
                    // Check if the current state allows for changing to new for cancellation
                    if ($order->canCancel()) {
                        // Proceed to set cancellation action flag and cancel the order
                        $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
                        $this->dataHelper->cancelOrder($order);
                    } else {
                        $this->adyenLogger->addAdyenResult('The order cannot be cancelled');
                    }
                }
                $result = false;
                break;
            default:
                $this->adyenLogger->error(
                    sprintf("Payment details call failed for action, resultCode is %s Raw API responds: %s.
                    Cancel or Hold the order on OFFER_CLOSED notification.",
                        $paymentsDetailsResponse['resultCode'],
                        json_encode($paymentsDetailsResponse)
                    ));

                $result = false;
                break;
        }

        $history = $this->orderHistoryFactory->create()
            ->setStatus($order->getStatus())
            ->setComment($comment)
            ->setEntityName('order')
            ->setOrder($order);

        $history->save();

        // needed because then we need to save $order objects
        $order->setAdyenResulturlEventCode($authResult);
        $this->orderResourceModel->save($order);

        return $result;
    }
}
