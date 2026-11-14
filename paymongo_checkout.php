<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$referenceNumber = $_GET['reference'] ?? '';
$redirect = BASE_URL . 'my_rentals.php';
if (!is_string($referenceNumber) || !preg_match('/\ACR-[A-F0-9]{32}\z/D', $referenceNumber)) {
    $_SESSION['swal_error'] = 'The PayMongo payment reference is invalid.';
    header('Location: ' . $redirect);
    exit;
}

$paymentModel = new App\Models\Payment($mysqli);
$payment = $paymentModel->getPayMongoByReference($referenceNumber, (int)$_SESSION['user_id']);
if (!$payment || $payment['payment_status'] !== App\Models\Payment::STATUS_PENDING ||
    empty($payment['transaction_id'])) {
    $_SESSION['swal_error'] = 'This e-wallet payment is not available.';
    header('Location: ' . $redirect);
    exit;
}

try {
    $payMongo = new App\Services\PayMongoService();
    $session = $payMongo->retrieveCheckoutSession($payment['transaction_id']);
    $attributes = $session['attributes'] ?? [];
    if (($attributes['reference_number'] ?? '') !== $referenceNumber ||
        !$payMongo->isExpectedMode($session)) {
        throw new RuntimeException('The PayMongo checkout session did not match this payment.');
    }

    if ($payMongo->hasPaidPayment($session)) {
        if (!$paymentModel->markPayMongoPaid($payment['transaction_id'], $referenceNumber)) {
            throw new RuntimeException('The confirmed PayMongo payment could not be recorded.');
        }
        $_SESSION['swal_success'] = 'Your e-wallet payment is confirmed.';
        header('Location: ' . $redirect);
        exit;
    }

    if (($attributes['status'] ?? '') !== 'active') {
        throw new RuntimeException('The PayMongo checkout session is no longer active.');
    }

    $checkoutUrl = $attributes['checkout_url'] ?? '';
    if (!is_string($checkoutUrl) || $checkoutUrl === '') {
        throw new RuntimeException('The PayMongo checkout URL is unavailable.');
    }

    header('Location: ' . $checkoutUrl);
    exit;
} catch (RuntimeException $exception) {
    error_log('Could not resume PayMongo checkout.');
    $_SESSION['swal_error'] = 'Could not open the e-wallet checkout. Please try again later.';
    header('Location: ' . $redirect);
    exit;
}
