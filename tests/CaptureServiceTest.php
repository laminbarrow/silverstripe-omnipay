<?php

use SilverStripe\Omnipay\Service\CaptureService;
use Omnipay\Common\Message\NotificationInterface;

/**
 * Test the capture service
 */
class CaptureServiceTest extends BaseNotificationServiceTest
{
    protected $gatewayMethod = 'capture';

    protected $fixtureIdentifier = 'payment6';

    protected $fixtureReceipt = 'authorizedPaymentReceipt';

    protected $startStatus = 'Authorized';

    protected $pendingStatus = 'PendingCapture';

    protected $endStatus = 'Captured';

    protected $successFromFixtureMessages = array(
        array( // response that was loaded from the fixture
            'ClassName' => 'AuthorizedResponse',
            'Reference' => 'authorizedPaymentReceipt'
        ),
        array( // the generated Capture request
            'ClassName' => 'CaptureRequest',
            'Reference' => 'authorizedPaymentReceipt'
        ),
        array( // the generated Capture response
            'ClassName' => 'CapturedResponse',
            'Reference' => 'authorizedPaymentReceipt'
        )
    );

    protected $successMessages = array(
        array( // the generated capture request
            'ClassName' => 'CaptureRequest',
            'Reference' => 'testThisRecipe123'
        ),
        array( // the generated capture response
            'ClassName' => 'CapturedResponse',
            'Reference' => 'testThisRecipe123'
        )
    );

    protected $failureMessages = array(
        array( // response that was loaded from the fixture
            'ClassName' => 'AuthorizedResponse',
            'Reference' => 'authorizedPaymentReceipt'
        ),
        array( // the generated capture request
            'ClassName' => 'CaptureRequest',
            'Reference' => 'authorizedPaymentReceipt'
        ),
        array( // the generated capture response
            'ClassName' => 'CaptureError',
            'Reference' => 'authorizedPaymentReceipt'
        )
    );

    protected $notificationFailureMessages = array(
        array(
            'ClassName' => 'AuthorizedResponse',
            'Reference' => 'authorizedPaymentReceipt'
        ),
        array(
            'ClassName' => 'CaptureRequest',
            'Reference' => 'authorizedPaymentReceipt'
        ),
        array(
            'ClassName' => 'NotificationError',
            'Reference' => 'authorizedPaymentReceipt'
        )
    );

    protected $errorMessageClass = 'CaptureError';

    protected $successPaymentExtensionHooks = array(
        'onCaptured'
    );

    protected $initiateServiceExtensionHooks = array(
        'onBeforeCapture',
        'onAfterCapture',
        'onAfterSendCapture',
        'updateServiceResponse'
    );

    protected $initiateFailedServiceExtensionHooks = array(
        'onBeforeCapture',
        'onAfterCapture',
        'updateServiceResponse'
    );

    public function setUp()
    {
        parent::setUp();
        $this->logInWithPermission('CAPTURE_PAYMENTS');
        CaptureService::add_extension('PaymentTest_ServiceExtensionHooks');
    }

    public function tearDown()
    {
        parent::tearDown();
        CaptureService::remove_extension('PaymentTest_ServiceExtensionHooks');
    }

    protected function getService(Payment $payment)
    {
        return CaptureService::create($payment);
    }

    public function testFullCapture()
    {
        // load an authorized payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);

        $stubGateway = $this->buildPaymentGatewayStub(true, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        // We supply the amount, but specify the full amount here. So this should be equal to a full capture
        $service->initiate(array('amount' => '123.45'));

        // there should be NO partial payments
        $this->assertEquals(0, $payment->getPartialPayments()->count());

        // check payment status
        $this->assertEquals($payment->Status, $this->endStatus, 'Payment status should be set to ' . $this->endStatus);
        $this->assertEquals('123.45', $payment->MoneyAmount);

        // check existance of messages and existence of references
        $this->assertDOSContains($this->successFromFixtureMessages, $payment->Messages());

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            $payment->getExtensionInstance('PaymentTest_PaymentExtensionHooks')->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            $this->initiateServiceExtensionHooks,
            $service->getExtensionInstance('PaymentTest_ServiceExtensionHooks')->getCalledMethods()
        );
    }

    public function testExcessCapture()
    {
        // load an authorized payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);

        Config::inst()->update('GatewayInfo', $payment->Gateway, array(
            'max_capture' => '20%',
            'can_capture' => 'full'
        ));

        $stubGateway = $this->buildPaymentGatewayStub(true, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        // We capture ~110% of the authorized payment
        $service->initiate(array('amount' => '135.80'));

        // there should be a new partial payment
        $this->assertEquals(1, $payment->getPartialPayments()->count());

        // the partial payment should be the excess amount
        $partialPayment = $payment->getPartialPayments()->first();
        $this->assertEquals('Captured', $partialPayment->Status);
        $this->assertEquals('12.35', $partialPayment->MoneyAmount);

        // check payment status
        $this->assertEquals($payment->Status, $this->endStatus, 'Payment status should be set to ' . $this->endStatus);
        $this->assertEquals('123.45', $payment->MoneyAmount);

        // check existance of messages and existence of references
        $this->assertDOSContains($this->successFromFixtureMessages, $payment->Messages());

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            $payment->getExtensionInstance('PaymentTest_PaymentExtensionHooks')->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            array_merge($this->initiateServiceExtensionHooks, array('updatePartialPayment')),
            $service->getExtensionInstance('PaymentTest_ServiceExtensionHooks')->getCalledMethods()
        );
    }

    public function testExcessCaptureViaNotification()
    {
        Config::inst()->update('GatewayInfo', 'PaymentExpress_PxPay', array(
            'max_capture' => '20%'
        ));

        // load a payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);

        // use notification on the gateway and only allow full captures
        Config::inst()->update('GatewayInfo', $payment->Gateway, array(
            'use_async_notification' => true,
            'can_capture' => 'full'
        ));

        $stubGateway = $this->buildPaymentGatewayStub(false, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        // the full 120%, must work, as excess capture isn't the same as a partial capture
        $service->initiate(array('amount' => '148.14'));

        // payment amount should still be the full amount!
        $this->assertEquals('123.45', $payment->MoneyAmount);

        // there must be a partial payment
        $this->assertEquals(1, $payment->getPartialPayments()->count());

        // the partial payment should be pending and contain the additional funds to capture
        $partialPayment = $payment->getPartialPayments()->first();
        $this->assertEquals('PendingCapture', $partialPayment->Status);
        $this->assertEquals('24.69', $partialPayment->MoneyAmount);

        // Now a notification comes in
        $this->get('paymentendpoint/'. $payment->Identifier .'/notify');

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            PaymentTest_PaymentExtensionHooks::findExtensionForID($payment->ID)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            array_merge($this->initiateServiceExtensionHooks, array('updatePartialPayment')),
            $service->getExtensionInstance('PaymentTest_ServiceExtensionHooks')->getCalledMethods()
        );

        // we'll have to "reload" the payment from the DB now
        $payment = Payment::get()->byID($payment->ID);

        // Status should be captured
        $this->assertEquals('Captured', $payment->Status);
        $this->assertEquals('123.45', $payment->MoneyAmount);

        // the partial payment should no longer be pending
        $partialPayment = $payment->getPartialPayments()->first();
        $this->assertEquals('Captured', $partialPayment->Status);
        $this->assertEquals('24.69', $partialPayment->MoneyAmount);

        // check existance of messages
        $this->assertDOSContains(array(
            array(
                'ClassName' => 'AuthorizedResponse',
                'Reference' => 'authorizedPaymentReceipt'
            ),
            array(
                'ClassName' => 'CaptureRequest',
                'Reference' => 'authorizedPaymentReceipt'
            ),
            array(
                'ClassName' => 'NotificationSuccessful',
                'Reference' => 'authorizedPaymentReceipt'
            ),
            array(
                'ClassName' => 'CapturedResponse',
                'Reference' => 'authorizedPaymentReceipt'
            )
        ), $payment->Messages());

        // try to complete a second time
        $service = $this->getService($payment);
        $serviceResponse = $service->complete();

        // the service should not respond with an error, since the payment is already captured
        $this->assertFalse($serviceResponse->isError());
        // since the payment is already completed, we should not touch omnipay again.
        $this->assertNull($serviceResponse->getOmnipayResponse());
    }

    public function testPartialCapture()
    {
        // load an authorized payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);

        $stubGateway = $this->buildPaymentGatewayStub(true, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        // We do a partial capture
        $service->initiate(array('amount' => '23.45'));

        // there should be a new partial payment
        $this->assertEquals(1, $payment->getPartialPayments()->count());

        $partialPayment = $payment->getPartialPayments()->first();
        $this->assertEquals('Captured', $partialPayment->Status);
        $this->assertEquals('23.45', $partialPayment->MoneyAmount);

        // check payment status. It should still be authorized, as it's not fully captured
        $this->assertEquals('Authorized', $payment->Status);
        // the original payment should now have less balance
        $this->assertEquals('100.00', $payment->MoneyAmount);

        // check existance of messages and existence of references
        $this->assertDOSContains(array(
            array(
                'ClassName' => 'AuthorizedResponse',
                'Reference' => 'authorizedPaymentReceipt',
            ),

            array(
                'ClassName' => 'CaptureRequest',
                'Reference' => 'authorizedPaymentReceipt',
            ),
            array(
                'ClassName' => 'PartiallyCapturedResponse',
                'Reference' => 'authorizedPaymentReceipt',
            ),
        ), $payment->Messages());

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            $payment->getExtensionInstance('PaymentTest_PaymentExtensionHooks')->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            array_merge($this->initiateServiceExtensionHooks, array('updatePartialPayment')),
            $service->getExtensionInstance('PaymentTest_ServiceExtensionHooks')->getCalledMethods()
        );
    }

    public function testPartialCaptureViaNotification()
    {
        // load a payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);

        // use notification on the gateway
        Config::inst()->update('GatewayInfo', $payment->Gateway, array(
            'use_async_notification' => true
        ));

        $stubGateway = $this->buildPaymentGatewayStub(false, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        $service->initiate(array('amount' => '100.45'));

        // payment amount should still be the full amount!
        $this->assertEquals('123.45', $payment->MoneyAmount);

        // there must be a partial payment
        $this->assertEquals(1, $payment->getPartialPayments()->count());

        // the partial payment should be pending and negative
        $partialPayment = $payment->getPartialPayments()->first();
        $this->assertEquals('PendingCapture', $partialPayment->Status);
        $this->assertEquals('-100.45', $partialPayment->MoneyAmount);

        // Now a notification comes in
        $this->get('paymentendpoint/'. $payment->Identifier .'/notify');

        // ensure payment hooks were called
        $this->assertEquals(
            $this->successPaymentExtensionHooks,
            PaymentTest_PaymentExtensionHooks::findExtensionForID($payment->ID)->getCalledMethods()
        );

        // ensure the correct service hooks were called
        $this->assertEquals(
            array_merge($this->initiateServiceExtensionHooks, array('updatePartialPayment')),
            $service->getExtensionInstance('PaymentTest_ServiceExtensionHooks')->getCalledMethods()
        );

        // we'll have to "reload" the payment from the DB now
        $payment = Payment::get()->byID($payment->ID);

        // Status should still be authorized
        $this->assertEquals('Authorized', $payment->Status);
        // the payment balance is reduced to 23.00
        $this->assertEquals('23.00', $payment->MoneyAmount);

        // the partial payment should no longer be pending and positive
        $partialPayment = $payment->getPartialPayments()->first();
        $this->assertEquals('Captured', $partialPayment->Status);
        $this->assertEquals('100.45', $partialPayment->MoneyAmount);

        // check existance of messages
        $this->assertDOSContains(array(
            array(
                'ClassName' => 'AuthorizedResponse',
                'Reference' => 'authorizedPaymentReceipt'
            ),
            array(
                'ClassName' => 'CaptureRequest',
                'Reference' => 'authorizedPaymentReceipt'
            ),
            array(
                'ClassName' => 'NotificationSuccessful',
                'Reference' => 'authorizedPaymentReceipt'
            ),
            array(
                'ClassName' => 'PartiallyCapturedResponse',
                'Reference' => 'authorizedPaymentReceipt'
            )
        ), $payment->Messages());

        // try to complete a second time
        $service = $this->getService($payment);
        $serviceResponse = $service->complete();

        // the service should respond with an error, since the payment is not (fully) captured
        $this->assertTrue($serviceResponse->isError());
        // since the payment is already completed, we should not touch omnipay again.
        $this->assertNull($serviceResponse->getOmnipayResponse());
    }

    public function testMultipleInitiateCallsBeforeNotificationArrives()
    {
        // load a payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);

        // use notification on the gateway
        Config::inst()->update('GatewayInfo', $payment->Gateway, array(
            'use_async_notification' => true
        ));

        $stubGateway = $this->buildPaymentGatewayStub(false, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        // try to initiate two captures without waiting for one to complete
        $service->initiate(array('amount' => '100.00'));

        $exception = null;
        try {
            // the second attempt must throw an exception!
            $service->initiate(array('amount' => '23.75'));
        } catch (Exception $ex) {
            $exception = $ex;
        }

        $this->assertInstanceOf('SilverStripe\Omnipay\Exception\InvalidConfigurationException', $exception);

        // there must be a partial payment
        $this->assertEquals(1, $payment->getPartialPayments()->count());

        // the partial payment should be pending and have the first initiated amount
        $partialPayment = $payment->getPartialPayments()->first();
        $this->assertEquals('PendingCapture', $partialPayment->Status);
        $this->assertEquals('-100.00', $partialPayment->MoneyAmount);

        // check existance of messages
        $this->assertDOSContains(array(
            array(
                'ClassName' => 'AuthorizedResponse',
                'Reference' => 'authorizedPaymentReceipt'
            ),
            array(
                'ClassName' => 'CaptureRequest',
                'Reference' => 'authorizedPaymentReceipt'
            )
        ), $payment->Messages());
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\InvalidParameterException
     */
    public function testLargerAmount()
    {
        $stubGateway = $this->buildPaymentGatewayStub(true, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        // load a captured payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);
        $service = $this->getService($payment);

        // We supply the amount, but specify an amount that is way over what was authorized
        // This will throw an InvalidParameterException
        $service->initiate(array('amount' => '1000000.00'));
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\InvalidParameterException
     */
    public function testInvalidAmount()
    {
        $stubGateway = $this->buildPaymentGatewayStub(true, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        // load a captured payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);
        $service = $this->getService($payment);

        // We supply the amount, but specify an amount that is not a number
        // This will throw an InvalidParameterException
        $service->initiate(array('amount' => 'test'));
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\InvalidParameterException
     */
    public function testNegativeAmount()
    {
        $stubGateway = $this->buildPaymentGatewayStub(true, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        // load a captured payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);
        $service = $this->getService($payment);

        // We supply the amount, but specify an amount that is not a positive number
        // This will throw an InvalidParameterException
        $service->initiate(array('amount' => '-1'));
    }

    /**
     * @expectedException \SilverStripe\Omnipay\Exception\InvalidParameterException
     */
    public function testPartialCaptureUnsupported()
    {
        $stubGateway = $this->buildPaymentGatewayStub(true, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        // load a captured payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);
        $service = $this->getService($payment);

        // only allow full capture, thus disabling partial refunds
        Config::inst()->update('GatewayInfo', $payment->Gateway, array(
            'can_capture' => 'full'
        ));

        // We supply a partial amount
        // This will throw an InvalidParameterException
        $service->initiate(array('amount' => '10.00'));
    }

    public function testPartialCaptureFailed()
    {
        $stubGateway = $this->buildPaymentGatewayStub(false, $this->fixtureReceipt);
        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        // load an authorized payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);
        $service = $this->getService($payment);

        $service->initiate(array('amount' => '100.00'));

        // there should be NO partial payments
        $this->assertEquals(0, $payment->getPartialPayments()->count());

        // Payment should be unaltered
        $this->assertEquals('Authorized', $payment->Status);
        $this->assertEquals('123.45', $payment->MoneyAmount);
    }

    public function testPartialCaptureViaNotificationFailed()
    {
        // load a payment from fixture
        $payment = $this->objFromFixture("Payment", $this->fixtureIdentifier);

        // use notification on the gateway
        Config::inst()->update('GatewayInfo', $payment->Gateway, array(
            'use_async_notification' => true
        ));

        $stubGateway = $this->buildPaymentGatewayStub(
            false,
            $this->fixtureReceipt,
            NotificationInterface::STATUS_FAILED
        );

        // register our mock gateway factory as injection
        Injector::inst()->registerService($this->stubGatewayFactory($stubGateway), 'Omnipay\Common\GatewayFactory');

        $service = $this->getService($payment);

        $service->initiate(array('amount' => '53.45'));

        // Now a notification comes in (will fail)
        $this->get('paymentendpoint/'. $payment->Identifier .'/notify');

        // we'll have to "reload" the payment from the DB now
        $payment = Payment::get()->byID($payment->ID);

        // Status should be reset
        $this->assertEquals('Authorized', $payment->Status);
        // the payment balance is unaltered
        $this->assertEquals('123.45', $payment->MoneyAmount);

        // the partial payment should be void
        $partialPayment = $payment->getPartialPayments()->first();
        $this->assertEquals('Void', $partialPayment->Status);
        $this->assertEquals('-53.45', $partialPayment->MoneyAmount);

        // check existance of messages
        $this->assertDOSContains($this->notificationFailureMessages, $payment->Messages());
    }
}