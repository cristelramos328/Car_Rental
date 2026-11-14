<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['received' => false]);
    exit;
}

$webhookSecret = getenv('PAYMONGO_WEBHOOK_SECRET');
$secretKey = getenv('PAYMONGO_SECRET_KEY');
if ($webhookSecret === false || $webhookSecret === '' || $secretKey === false || $secretKey === '') {
    $localEnvFile = __DIR__ . '/.env';
    if (is_file($localEnvFile)) {
        $localEnv = @parse_ini_file($localEnvFile, false, INI_SCANNER_RAW);
        if ($localEnv === false) {
            http_response_code(503);
            echo json_encode(['received' => false]);
            exit;
        }
        if ($webhookSecret === false || $webhookSecret === '') {
            $webhookSecret = $localEnv['PAYMONGO_WEBHOOK_SECRET'] ?? '';
        }
        if ($secretKey === false || $secretKey === '') {
            $secretKey = $localEnv['PAYMONGO_SECRET_KEY'] ?? '';
        }
    }
}

if ($secretKey === false || !preg_match('/\Ask_test_[A-Za-z0-9]+\z/D', $secretKey) ||
    !is_string($webhookSecret) || $webhookSecret === '') {
    http_response_code(503);
    echo json_encode(['received' => false]);
    exit;
}

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
if (!is_string($rawBody) || !is_string($signature) ||
    !hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), trim($signature))) {
    http_response_code(401);
    echo json_encode(['received' => false]);
    exit;
}

require_once __DIR__ . '/config.php';
$event = json_decode($rawBody, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['received' => false]);
    exit;
}

$eventData = $event['data'] ?? [];
if (!is_array($eventData)) {
    http_response_code(400);
    echo json_encode(['received' => false]);
    exit;
}

if (($eventData['type'] ?? '') !== 'checkout_session.payment.paid') {
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$session = $eventData['data'] ?? [];
$attributes = is_array($session) ? ($session['attributes'] ?? []) : [];
$sessionId = is_array($session) ? ($session['id'] ?? '') : '';
$referenceNumber = is_array($attributes) ? ($attributes['reference_number'] ?? '') : '';
$payments = is_array($attributes) ? ($attributes['payments'] ?? []) : [];
$hasPaidPayment = false;
if (is_array($payments)) {
    foreach ($payments as $payment) {
        $paymentAttributes = is_array($payment) ? ($payment['attributes'] ?? []) : [];
        if (is_array($paymentAttributes) && ($paymentAttributes['status'] ?? '') === 'paid') {
            $hasPaidPayment = true;
            break;
        }
    }
}

$isCheckoutSession = is_array($session) && ($session['type'] ?? '') === 'checkout_session';
$eventLiveMode = $eventData['livemode'] ?? null;
if (!$isCheckoutSession || !$hasPaidPayment || !is_string($sessionId) || !is_string($referenceNumber) ||
    !preg_match('/\ACR-[A-F0-9]{32}\z/D', $referenceNumber) ||
    !is_bool($eventLiveMode) || $eventLiveMode !== false) {
    if ($eventLiveMode === true) {
        error_log('PayMongo webhook mode did not match the configured secret key.');
    }
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$draftModel = new App\Models\PayMongoBookingDraft($mysqli);
$draft = $draftModel->getByReference($referenceNumber);
$paymentModel = new App\Models\Payment($mysqli);
$recorded = false;
if ($draft) {
    $controller = new App\Controllers\RentalController($mysqli);
    $recorded = $controller->completePayMongoBooking($session, $referenceNumber);
} else {
    $recorded = $paymentModel->markPayMongoPaid($sessionId, $referenceNumber);
}
if (!$recorded) {
    error_log('PayMongo webhook payment could not be recorded.');
    http_response_code(500);
    echo json_encode(['received' => false]);
    exit;
}
http_response_code(200);
echo json_encode(['received' => true]);
