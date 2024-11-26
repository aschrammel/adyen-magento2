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

namespace Adyen\Payment\Gateway\Request;

use Adyen\Payment\Helper\StateData;
use Adyen\Payment\Helper\Vault;
use Adyen\Payment\Model\Ui\AdyenCcConfigProvider;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

class RecurringVaultDataBuilder implements BuilderInterface
{
    private StateData $stateData;
    private Vault $vaultHelper;

    public function __construct(
        StateData $stateData,
        Vault $vaultHelper
    ) {
        $this->stateData = $stateData;
        $this->vaultHelper = $vaultHelper;
    }

    public function build(array $buildSubject): array
    {
        /** @var PaymentDataObject $paymentDataObject */
        $paymentDataObject = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDataObject->getPayment();
        $paymentMethod = $payment->getMethodInstance();
        $order = $payment->getOrder();
        $extensionAttributes = $payment->getExtensionAttributes();
        $paymentToken = $extensionAttributes->getVaultPaymentToken();
        $details = json_decode((string) ($paymentToken->getTokenDetails() ?: '{}'), true);

        // Initialize the request body with the current state data
        $requestBody = $this->stateData->getStateData($order->getQuoteId());

        // For now this will only be used by tokens created trough adyen_hpp payment methods
        if (array_key_exists(Vault::TOKEN_TYPE, $details)) {
            $requestBody['recurringProcessingModel'] = $details[Vault::TOKEN_TYPE];
        } else {
            // If recurringProcessingModel doesn't exist in the token details, use the default value from config.
            $requestBody['recurringProcessingModel'] = $this->vaultHelper->getPaymentMethodRecurringProcessingModel(
                $paymentMethod->getProviderCode(),
                $order->getStoreId()
            );
        }

        /*
         * allow3DS flag is required to trigger the native 3DS challenge.
         * Otherwise, shopper will be redirected to the issuer for challenge.
         */
        if ($paymentMethod->getCode() === AdyenCcConfigProvider::CC_VAULT_CODE) {
            $requestBody['additionalData']['allow3DS2'] = true;
        }

        /**
         * Build paymentMethod object for alternative payment methods
         */
        if ($paymentMethod->getCode() !== AdyenCcConfigProvider::CC_VAULT_CODE) {
            $requestBody['paymentMethod'] = [
                'type' => $details['type'],
                'storedPaymentMethodId' => $paymentToken->getGatewayToken()
            ];
        }

        $request['body'] = $requestBody;

        return $request;
    }
}
