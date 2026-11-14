<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$referenceNumber = $_GET['reference'] ?? '';
$result = $_GET['result'] ?? '';
$userId = (int)$_SESSION['user_id'];
$redirect = BASE_URL . 'my_rentals.php';
if (!is_string($referenceNumber) || !preg_match('/\ACR-[A-F0-9]{32}\z/D', $referenceNumber)) {
    $_SESSION['swal_error'] = 'The PayMongo payment reference is invalid.';
    header('Location: ' . $redirect);
    exit;
}

$draftModel = new App\Models\PayMongoBookingDraft($mysqli);
$draft = $draftModel->getByReference($referenceNumber, $userId);
$paymentModel = new App\Models\Payment($mysqli);
$payment = $paymentModel->getPayMongoByReference($referenceNumber, $userId);

if ($result === 'cancel') {
    if ($draft) {
        $controller = new App\Controllers\RentalController($mysqli);
        $cancelResult = $controller->discardUnpaidPayMongoDraft($referenceNumber, $userId);
        $_SESSION[$cancelResult['success'] ? 'swal_success' : 'swal_error'] = $cancelResult['message'];
    } elseif ($payment && $payment['payment_status'] === App\Models\Payment::STATUS_PENDING) {
        $controller = new App\Controllers\RentalController($mysqli);
        $cancelResult = $controller->cancelUnpaidPayMongoBooking(
            (int)$payment['rental_id'],
            $userId
        );
        $_SESSION[$cancelResult['success'] ? 'swal_success' : 'swal_error'] = $cancelResult['message'];
    } elseif ($payment && $payment['payment_status'] === App\Models\Payment::STATUS_COMPLETED) {
        $_SESSION['swal_success'] = 'Your e-wallet payment is confirmed.';
    } else {
        $_SESSION['swal_error'] = 'This checkout is no longer available.';
    }
} elseif ($result === 'success') {
    if ($payment && $payment['payment_status'] === App\Models\Payment::STATUS_COMPLETED) {
        $_SESSION['swal_success'] = 'Your e-wallet payment is confirmed.';
    } else {
        $sessionId = $draft['checkout_session_id'] ?? ($payment['transaction_id'] ?? '');
        $paymentConfirmed = false;
        $bookingRecorded = false;
        try {
            $payMongo = new App\Services\PayMongoService();
            $session = $payMongo->retrieveCheckoutSession($sessionId);
            $attributes = $session['attributes'] ?? [];
            $verified = ($attributes['reference_number'] ?? '') === $referenceNumber &&
                $payMongo->isExpectedMode($session) &&
                $payMongo->hasPaidPayment($session);

            $paymentConfirmed = $verified;
            if ($verified && $draft) {
                $controller = new App\Controllers\RentalController($mysqli);
                $bookingRecorded = $controller->completePayMongoBooking($session, $referenceNumber);
            } elseif ($verified && $payment &&
                $paymentModel->markPayMongoPaid($sessionId, $referenceNumber)) {
                $bookingRecorded = true;
            }
        } catch (RuntimeException $exception) {
            error_log('PayMongo return verification failed.');
        }

        $payment = $paymentModel->getPayMongoByReference($referenceNumber, $userId);
        if ($payment && $payment['payment_status'] === App\Models\Payment::STATUS_COMPLETED) {
            $_SESSION['swal_success'] = 'Your e-wallet payment is confirmed.';
        } elseif ($paymentConfirmed && !$bookingRecorded) {
            error_log('PayMongo confirmed payment, but the rental could not be recorded.');
            $_SESSION['swal_error'] = 'PayMongo confirmed the payment, but we could not record the rental. Please contact support.';
        } else {
            $_SESSION['swal_error'] = 'PayMongo has not confirmed this payment yet.';
        }
    }
} else {
    $_SESSION['swal_error'] = 'No PayMongo payment confirmation was received.';
}

header('Location: ' . $redirect);
exit;
