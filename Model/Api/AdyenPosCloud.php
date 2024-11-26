<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2024 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model\Api;

use Adyen\Payment\Api\AdyenPosCloudInterface;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Sales\OrderRepository;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactoryInterface;

class AdyenPosCloud implements AdyenPosCloudInterface
{
    private CommandPoolInterface $commandPool;
    protected AdyenLogger $adyenLogger;
    protected OrderRepository $orderRepository;
    protected PaymentDataObjectFactoryInterface $paymentDataObjectFactory;

    public function __construct(
        CommandPoolInterface $commandPool,
        OrderRepository      $orderRepository,
        PaymentDataObjectFactoryInterface $paymentDataObjectFactory,
        AdyenLogger          $adyenLogger
    )
    {
        $this->commandPool = $commandPool;
        $this->orderRepository = $orderRepository;
        $this->paymentDataObjectFactory = $paymentDataObjectFactory;
        $this->adyenLogger = $adyenLogger;
    }

    public function pay(int $orderId): void
    {
        $order = $this->orderRepository->get($orderId);
        $this->execute($order);
    }

    protected function execute(OrderInterface $order): void
    {
        $payment = $order->getPayment();
        $paymentDataObject = $this->paymentDataObjectFactory->create($payment);
        $posCommand = $this->commandPool->get('authorize');
        $posCommand->execute(['payment' => $paymentDataObject]);
    }
}
