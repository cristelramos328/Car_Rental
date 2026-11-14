<?php
namespace App\Controllers;

use App\Models\Rental;
use App\Models\Car;
use App\Models\Payment;
use App\Models\PayMongoBookingDraft;
use App\Models\User;
use App\Services\PayMongoService;

class RentalController {
    private $db;
    private $rentalModel;
    private $carModel;
    private $paymentModel;
    private $payMongoDraftModel;
    private $userModel;

    public function __construct($mysqli) {
        $this->db = $mysqli;
        $this->rentalModel = new Rental($mysqli);
        $this->carModel = new Car($mysqli);
        $this->paymentModel = new Payment($mysqli);
        $this->payMongoDraftModel = new PayMongoBookingDraft($mysqli);
        $this->userModel = new User($mysqli);
    }

    public function processRental() {
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {
            if ($this->postString('action') === 'resubmit_receipt') {
                $this->resubmitReceipt();
            }
            if ($this->postString('action') === 'cancel_paymongo') {
                $rental_id = filter_var($_POST['rental_id'] ?? null, FILTER_VALIDATE_INT);
                $result = $rental_id
                    ? $this->cancelUnpaidPayMongoBooking((int)$rental_id, (int)$_SESSION['user_id'])
                    : ['success' => false, 'message' => 'The booking could not be found.'];
                $_SESSION[$result['success'] ? 'swal_success' : 'swal_error'] = $result['message'];
                header('Location: ' . BASE_URL . 'my_rentals.php');
                exit;
            }

            $user_id = (int)$_SESSION['user_id'];
            $car_id = $this->postString('car_id');
            $start_date = $this->postString('start_date');
            $end_date = $this->postString('end_date');
            $customer_name = $this->postString('customer_name');
            $customer_phone = $this->postString('customer_phone');
            $customer_address = $this->postString('customer_address');
            $latitudeInput = $this->postString('customer_latitude');
            $longitudeInput = $this->postString('customer_longitude');
            $license_number = $this->postString('driver_license_number');
            $payment_method = $this->postString('payment_method') ?: Payment::METHOD_CASH;
            $redirect = BASE_URL . 'car_details.php' . ($car_id !== '' ? '?id=' . rawurlencode($car_id) : '');

            $start = \DateTime::createFromFormat('!Y-m-d', $start_date);
            $end = \DateTime::createFromFormat('!Y-m-d', $end_date);
            $dateError = \DateTime::getLastErrors();
            $customer = $this->userModel->getById($user_id);
            if (!$customer) {
                header("Location: " . BASE_URL . "login.php");
                exit;
            }
            if ($car_id === '' || strlen($car_id) > 50 || !$start || !$end ||
                ($dateError !== false && ($dateError['warning_count'] || $dateError['error_count'])) ||
                $start->format('Y-m-d') !== $start_date || $end->format('Y-m-d') !== $end_date ||
                $start_date < date('Y-m-d') || $end_date < $start_date) {
                $_SESSION['swal_error'] = 'Choose a valid rental period. The return date cannot be before the pick-up date.';
                header("Location: " . $redirect);
                exit;
            }

            if ($customer_name === '' || strlen($customer_name) > 100 ||
                $customer_address === '' || strlen($customer_address) > 2000) {
                $_SESSION['swal_error'] = 'Enter a valid name, contact number, and address before booking.';
                header("Location: " . $redirect);
                exit;
            }

            if (!preg_match('/\A[0-9]{1,11}\z/', $customer_phone)) {
                $_SESSION['swal_error'] = 'Contact number must contain digits only and be at most 11 digits.';
                header("Location: " . $redirect);
                exit;
            }

            $customer_latitude = null;
            $customer_longitude = null;
            if ($latitudeInput !== '' || $longitudeInput !== '') {
                if ($latitudeInput === '' || $longitudeInput === '' ||
                    !is_numeric($latitudeInput) || !is_numeric($longitudeInput) ||
                    (float)$latitudeInput < -90 || (float)$latitudeInput > 90 ||
                    (float)$longitudeInput < -180 || (float)$longitudeInput > 180) {
                    $_SESSION['swal_error'] = 'Use the GPS button to provide a valid location, or clear the GPS location and enter your address.';
                    header("Location: " . $redirect);
                    exit;
                }

                $customer_latitude = number_format((float)$latitudeInput, 7, '.', '');
                $customer_longitude = number_format((float)$longitudeInput, 7, '.', '');
            }

            if ($license_number === '' || strlen($license_number) > 100) {
                $_SESSION['swal_error'] = 'Enter a valid driver license number.';
                header("Location: " . $redirect);
                exit;
            }

            if (!in_array($payment_method, [Payment::METHOD_CASH, Payment::METHOD_ONLINE], true)) {
                $_SESSION['swal_error'] = 'Select a supported payment method.';
                header("Location: " . $redirect);
                exit;
            }

            $car = $this->carModel->getById($car_id);
            if (!$car || $car['status'] === Car::STATUS_MAINTENANCE ||
                !$this->carModel->isAvailableForPeriod($car_id, $start_date, $end_date, false)) {
                $_SESSION['swal_error'] = 'This vehicle is not available for the selected dates.';
                header("Location: " . $redirect);
                exit;
            }

            $licenseImage = $this->storeImageUpload('driver_license_image', true, $uploadError);
            if ($uploadError !== '') {
                $_SESSION['swal_error'] = $uploadError;
                header("Location: " . $redirect);
                exit;
            }

            $days = (int)$start->diff($end)->days;
            if ($days === 0) {
                $days = 1;
            }
            $total_price = $days * (float)$car['price_per_day'];
            $receiptImage = null;
            $checkoutSession = null;
            $checkoutReference = '';
            if ($payment_method === Payment::METHOD_ONLINE) {
                $checkoutReference = 'CR-' . strtoupper(bin2hex(random_bytes(16)));
                try {
                    $payMongo = new PayMongoService();
                    $checkoutSession = $payMongo->createCheckoutSession(
                        $total_price,
                        'Car rental: ' . ($car['brand'] ?? '') . ' ' . $car['model'],
                        $checkoutReference,
                        BASE_URL . 'paymongo_return.php?reference=' . rawurlencode($checkoutReference) . '&result=success',
                        BASE_URL . 'paymongo_return.php?reference=' . rawurlencode($checkoutReference) . '&result=cancel'
                    );
                } catch (\RuntimeException $exception) {
                    $this->removePrivateUpload($licenseImage);
                    $_SESSION['swal_error'] = $exception->getMessage();
                    header("Location: " . $redirect);
                    exit;
                }
            }

            $this->db->begin_transaction();
            $lockCar = $this->db->prepare("SELECT status FROM cars WHERE plate_number = ? FOR UPDATE");
            $lockCar->bind_param('s', $car_id);
            $lockCar->execute();
            $lockedCar = $lockCar->get_result()->fetch_assoc();
            if (!$lockedCar || $lockedCar['status'] === Car::STATUS_MAINTENANCE ||
                !$this->carModel->isAvailableForPeriod($car_id, $start_date, $end_date, false)) {
                $this->db->rollback();
                $this->expireUnusedCheckoutSession($checkoutSession);
                $this->removePrivateUpload($licenseImage);
                $_SESSION['swal_error'] = 'This vehicle was just booked for the selected dates. Please choose another period.';
                header("Location: " . $redirect);
                exit;
            }

            if ($checkoutSession) {
                $success = $this->payMongoDraftModel->create(
                    $user_id,
                    $car_id,
                    $start_date,
                    $end_date,
                    $total_price,
                    $license_number,
                    $licenseImage,
                    $customer_name,
                    $customer_phone,
                    $customer_address,
                    $customer_latitude,
                    $customer_longitude,
                    $checkoutSession['id'],
                    $checkoutReference
                );
            } else {
                $success = $this->rentalModel->createRental(
                    $user_id,
                    $car_id,
                    $start_date,
                    $end_date,
                    $total_price,
                    $license_number,
                    $licenseImage,
                    $customer_name,
                    $customer_phone,
                    $customer_address,
                    $customer_latitude,
                    $customer_longitude
                );
            }
            if ($success && !$checkoutSession) {
                $success = $this->paymentModel->create(
                    $this->db->insert_id,
                    $user_id,
                    $total_price,
                    $payment_method,
                    $checkoutSession['id'] ?? '',
                    $checkoutReference,
                    $receiptImage
                );
            }

            if ($success && $this->db->commit()) {
                if ($checkoutSession) {
                    header("Location: " . $checkoutSession['url']);
                    exit;
                }
                header("Location: " . BASE_URL . "my_rentals.php?msg=success");
                exit;
            }

            $this->db->rollback();
            $this->expireUnusedCheckoutSession($checkoutSession);
            $this->removePrivateUpload($licenseImage);
            $_SESSION['swal_error'] = 'Could not save the booking. Please try again.';
            header("Location: " . $redirect);
            exit;
        }

        header("Location: " . BASE_URL . "login.php");
        exit;
    }

    public function completePayMongoBooking(array $session, $referenceNumber) {
        $sessionId = $session['id'] ?? '';
        $attributes = $session['attributes'] ?? [];
        $payMongo = new PayMongoService();
        if (($session['type'] ?? '') !== 'checkout_session' ||
            !is_string($sessionId) || !is_string($referenceNumber) ||
            ($attributes['reference_number'] ?? '') !== $referenceNumber ||
            !$payMongo->hasPaidPayment($session)) {
            return false;
        }

        if (!$this->db->begin_transaction()) {
            return false;
        }

        $draft = $this->payMongoDraftModel->getByReference($referenceNumber, null, true);
        if (!$draft) {
            $payment = $this->paymentModel->getPayMongoBySessionId($sessionId, $referenceNumber);
            $recorded = $payment && (
                $payment['payment_status'] === Payment::STATUS_COMPLETED ||
                ($payment['payment_status'] === Payment::STATUS_PENDING &&
                    $this->paymentModel->markPayMongoPaid($sessionId, $referenceNumber))
            );
            if ($recorded && $this->db->commit()) {
                return true;
            }
            $this->db->rollback();
            return false;
        }

        if ($draft['checkout_session_id'] !== $sessionId) {
            $this->db->rollback();
            return false;
        }

        $lockCar = $this->db->prepare("SELECT plate_number FROM cars WHERE plate_number = ? FOR UPDATE");
        $lockCar->bind_param('s', $draft['car_id']);
        $lockCar->execute();
        if (!$lockCar->get_result()->fetch_assoc()) {
            $this->db->rollback();
            return false;
        }

        $success = $this->rentalModel->createRental(
            (int)$draft['user_id'],
            $draft['car_id'],
            $draft['start_date'],
            $draft['end_date'],
            (float)$draft['total_price'],
            $draft['driver_license_number'],
            $draft['driver_license_image'],
            $draft['customer_name'],
            $draft['customer_phone'],
            $draft['customer_address'],
            $draft['customer_latitude'],
            $draft['customer_longitude']
        );
        if ($success) {
            $success = $this->paymentModel->create(
                $this->db->insert_id,
                (int)$draft['user_id'],
                (float)$draft['total_price'],
                Payment::METHOD_ONLINE,
                $sessionId,
                $referenceNumber
            );
        }
        if ($success) {
            $success = $this->paymentModel->markPayMongoPaid($sessionId, $referenceNumber) &&
                $this->payMongoDraftModel->delete($referenceNumber, $sessionId);
        }

        if ($success && $this->db->commit()) {
            return true;
        }

        $this->db->rollback();
        return false;
    }

    public function discardUnpaidPayMongoDraft($referenceNumber, $userId) {
        $draft = $this->payMongoDraftModel->getByReference($referenceNumber, $userId);
        if (!$draft) {
            return ['success' => false, 'message' => 'This checkout draft is no longer available.'];
        }

        try {
            $payMongo = new PayMongoService();
            $session = $payMongo->retrieveCheckoutSession($draft['checkout_session_id']);
            $attributes = $session['attributes'] ?? [];
            if (($attributes['reference_number'] ?? '') !== $referenceNumber ||
                !$payMongo->isExpectedMode($session)) {
                throw new \RuntimeException('The PayMongo checkout session did not match this draft.');
            }
            if ($payMongo->hasPaidPayment($session)) {
                if ($this->completePayMongoBooking($session, $referenceNumber)) {
                    return ['success' => true, 'message' => 'Payment is complete; the rental request was recorded.'];
                }
                return ['success' => false, 'message' => 'Payment is complete, but the rental request could not be recorded.'];
            }
            if (($attributes['status'] ?? '') === 'active') {
                $payMongo->expireCheckoutSession($draft['checkout_session_id']);
            } elseif (($attributes['status'] ?? '') !== 'expired') {
                throw new \RuntimeException('The PayMongo checkout session is not cancellable.');
            }
        } catch (\RuntimeException $exception) {
            error_log('Could not expire PayMongo checkout draft.');
            return ['success' => false, 'message' => 'Could not cancel this checkout. Refresh and try again.'];
        }

        if (!$this->payMongoDraftModel->delete($referenceNumber, $draft['checkout_session_id'])) {
            return ['success' => false, 'message' => 'Could not discard this checkout. Please try again.'];
        }
        $this->removePrivateUpload($draft['driver_license_image']);
        return ['success' => true, 'message' => 'Unpaid checkout closed. No rental or payment was recorded.'];
    }

    private function expireUnusedCheckoutSession($checkoutSession) {
        if (!$checkoutSession || empty($checkoutSession['id'])) {
            return;
        }

        try {
            (new PayMongoService())->expireCheckoutSession($checkoutSession['id']);
        } catch (\RuntimeException $exception) {
            error_log('Could not expire unused PayMongo checkout session.');
        }
    }

    public function cancelUnpaidPayMongoBooking($rentalId, $userId) {
        $payment = $this->paymentModel->getPendingPayMongoByRental($rentalId, $userId);
        if (!$payment || empty($payment['transaction_id']) || empty($payment['reference_number'])) {
            return ['success' => false, 'message' => 'This unpaid e-wallet booking is no longer available to cancel.'];
        }

        try {
            $payMongo = new PayMongoService();
            $session = $payMongo->retrieveCheckoutSession($payment['transaction_id']);
            $attributes = $session['attributes'] ?? [];
            if (($attributes['reference_number'] ?? '') !== $payment['reference_number'] ||
                !$payMongo->isExpectedMode($session)) {
                throw new \RuntimeException('The PayMongo checkout session did not match this booking.');
            }

            if ($payMongo->hasPaidPayment($session)) {
                if (!$this->paymentModel->markPayMongoPaid(
                    $payment['transaction_id'],
                    $payment['reference_number']
                )) {
                    return [
                        'success' => false,
                        'message' => 'Payment is complete, but its status could not be refreshed. Please reload My Rentals.'
                    ];
                }
                return ['success' => false, 'message' => 'Payment is already complete and cannot be cancelled.'];
            }

            if (($attributes['status'] ?? '') === 'active') {
                $payMongo->expireCheckoutSession($payment['transaction_id']);
            } elseif (($attributes['status'] ?? '') !== 'expired') {
                throw new \RuntimeException('The PayMongo checkout session is not cancellable.');
            }
        } catch (\RuntimeException $exception) {
            error_log('Could not expire PayMongo checkout for cancellation.');
            return [
                'success' => false,
                'message' => 'Could not cancel this booking because payment may be in progress. Refresh My Rentals and try again.'
            ];
        }

        if (!$this->paymentModel->cancelPendingPayMongo(
            $rentalId,
            $userId,
            $payment['transaction_id'],
            $payment['reference_number']
        )) {
            return ['success' => false, 'message' => 'Could not cancel this booking. Refresh My Rentals and try again.'];
        }

        return ['success' => true, 'message' => 'Unpaid e-wallet booking cancelled; the car is available again.'];
    }

    private function storeImageUpload($field, $required, &$error) {
        $error = '';
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                $error = 'Upload the required image before submitting your booking.';
            }
            return null;
        }

        $file = $_FILES[$field];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024 ||
            !is_uploaded_file($file['tmp_name'])) {
            $error = 'Each uploaded image must be a valid file no larger than 5 MB.';
            return null;
        }

        $imageInfo = @getimagesize($file['tmp_name']);
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        if (!$imageInfo || !isset($extensions[$mimeType]) || $imageInfo['mime'] !== $mimeType) {
            $error = 'Upload a JPEG, PNG, or WebP image.';
            return null;
        }

        $directory = __DIR__ . '/../../uploads/private_documents';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            $error = 'Private upload storage is unavailable. Please contact the administrator.';
            return null;
        }

        $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mimeType];
        if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
            $error = 'The image could not be saved. Please try again.';
            return null;
        }

        return 'private_documents/' . $filename;
    }

    private function postString($key) {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function resubmitReceipt() {
        $user_id = (int)$_SESSION['user_id'];
        $rental_id = filter_var($_POST['rental_id'] ?? null, FILTER_VALIDATE_INT);
        $reference_number = $this->postString('reference_number');
        $redirect = BASE_URL . 'my_rentals.php';

        if (!$rental_id || $reference_number === '' || strlen($reference_number) > 100) {
            $_SESSION['swal_error'] = 'Enter a valid transfer reference number.';
            header("Location: " . $redirect);
            exit;
        }

        $payment = $this->paymentModel->getByRentalId($rental_id);
        if (!$payment || (int)$payment['user_id'] !== $user_id ||
            !in_array($payment['payment_method'], [Payment::METHOD_GCASH, Payment::METHOD_BANK_TRANSFER], true) ||
            $payment['payment_status'] !== Payment::STATUS_CANCELLED) {
            $_SESSION['swal_error'] = 'This payment receipt cannot be changed.';
            header("Location: " . $redirect);
            exit;
        }

        $receiptImage = $this->storeImageUpload('receipt_image', true, $uploadError);
        if ($uploadError !== '') {
            $_SESSION['swal_error'] = $uploadError;
            header("Location: " . $redirect);
            exit;
        }

        if ($this->paymentModel->updateReceipt($rental_id, $user_id, $reference_number, $receiptImage)) {
            $this->removePrivateUpload($payment['receipt_image']);
            $_SESSION['swal_success'] = 'Your replacement receipt has been submitted for review.';
        } else {
            $this->removePrivateUpload($receiptImage);
            $_SESSION['swal_error'] = 'Could not update the receipt. Please try again.';
        }
        header("Location: " . $redirect);
        exit;
    }

    private function removePrivateUpload($relativePath) {
        $prefix = 'private_documents/';
        if (!$relativePath || strpos($relativePath, $prefix) !== 0) {
            return;
        }
        $filename = substr($relativePath, strlen($prefix));
        if (basename($filename) !== $filename) {
            return;
        }
        $path = __DIR__ . '/../../uploads/' . $prefix . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function myRentals() {
        if (!isset($_SESSION['user_id'])) {
            header("Location: " . BASE_URL . "login.php");
            exit;
        }

        $user_id = $_SESSION['user_id'];
        $result = $this->rentalModel->getUserRentals($user_id);
        if (empty($_SESSION['gps_csrf_token'])) {
            $_SESSION['gps_csrf_token'] = bin2hex(random_bytes(32));
        }
        $gpsCsrfToken = $_SESSION['gps_csrf_token'];

        require_once __DIR__ . '/../Views/rentals/my_rentals.php';
    }

    public function handleLocationApi() {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        if (!isset($_SESSION['user_id'])) {
            $this->sendLocationResponse(401, ['error' => 'Sign in to use GPS sharing.']);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if (($_SESSION['role'] ?? '') !== 'owner') {
                $this->sendLocationResponse(403, ['error' => 'Only the admin or owner can view shared GPS locations.']);
            }

            $rentalId = filter_var($_GET['rental_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$rentalId) {
                $this->sendLocationResponse(400, ['error' => 'A valid rental ID is required.']);
            }

            $stmt = $this->db->prepare(
                'SELECT tracking_enabled, tracking_latitude, tracking_longitude, tracking_updated_at
                 FROM rentals WHERE id = ? LIMIT 1'
            );
            $stmt->bind_param('i', $rentalId);
            if (!$stmt->execute()) {
                $this->sendLocationResponse(500, ['error' => 'Could not load GPS location.']);
            }
            $location = $stmt->get_result()->fetch_assoc();
            if (!$location) {
                $this->sendLocationResponse(404, ['error' => 'Rental not found.']);
            }
            $this->sendLocationResponse(200, $location);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: GET, POST');
            $this->sendLocationResponse(405, ['error' => 'Method not allowed.']);
        }

        $csrfToken = $this->postString('csrf_token');
        if (empty($_SESSION['gps_csrf_token']) ||
            !hash_equals($_SESSION['gps_csrf_token'], $csrfToken)) {
            $this->sendLocationResponse(403, ['error' => 'Location sharing request could not be verified. Reload the page and try again.']);
        }

        if (($_SESSION['role'] ?? '') !== 'customer') {
            $this->sendLocationResponse(403, ['error' => 'Only the renter can share this booking location.']);
        }

        $rentalId = filter_var($_POST['rental_id'] ?? null, FILTER_VALIDATE_INT);
        $action = $this->postString('action');
        if (!$rentalId || !in_array($action, ['start', 'update', 'stop'], true)) {
            $this->sendLocationResponse(400, ['error' => 'Invalid GPS sharing request.']);
        }

        $userId = (int)$_SESSION['user_id'];
        if ($action === 'stop') {
            $stmt = $this->db->prepare(
                'UPDATE rentals
                 SET tracking_enabled = 0, tracking_latitude = NULL, tracking_longitude = NULL, tracking_updated_at = NULL
                 WHERE id = ? AND user_id = ?'
            );
            $stmt->bind_param('ii', $rentalId, $userId);
        } else {
            $latitude = $this->postString('latitude');
            $longitude = $this->postString('longitude');
            if (!is_numeric($latitude) || !is_numeric($longitude) ||
                (float)$latitude < -90 || (float)$latitude > 90 ||
                (float)$longitude < -180 || (float)$longitude > 180) {
                $this->sendLocationResponse(400, ['error' => 'Valid GPS coordinates are required.']);
            }
            $latitude = number_format((float)$latitude, 7, '.', '');
            $longitude = number_format((float)$longitude, 7, '.', '');
            if ($action === 'start') {
                $stmt = $this->db->prepare(
                    'UPDATE rentals
                     SET tracking_enabled = 1, tracking_latitude = ?, tracking_longitude = ?, tracking_updated_at = NOW()
                     WHERE id = ? AND user_id = ?'
                );
                $stmt->bind_param('ssii', $latitude, $longitude, $rentalId, $userId);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE rentals
                     SET tracking_latitude = ?, tracking_longitude = ?, tracking_updated_at = NOW()
                     WHERE id = ? AND user_id = ? AND tracking_enabled = 1'
                );
                $stmt->bind_param('ssii', $latitude, $longitude, $rentalId, $userId);
            }
        }

        if (!$stmt->execute()) {
            $this->sendLocationResponse(500, ['error' => 'Could not update GPS sharing.']);
        }
        if ($stmt->affected_rows === 0) {
            $check = $this->db->prepare('SELECT tracking_enabled FROM rentals WHERE id = ? AND user_id = ? LIMIT 1');
            $check->bind_param('ii', $rentalId, $userId);
            if (!$check->execute()) {
                $this->sendLocationResponse(500, ['error' => 'Could not verify this booking.']);
            }
            $ownedRental = $check->get_result()->fetch_assoc();
            if (!$ownedRental) {
                $this->sendLocationResponse(404, ['error' => 'Booking not found.']);
            }
            if ($action === 'update' && (int)$ownedRental['tracking_enabled'] !== 1) {
                $this->sendLocationResponse(409, ['error' => 'GPS sharing is off for this booking.']);
            }
        }

        $this->sendLocationResponse(200, ['ok' => true, 'tracking_enabled' => $action !== 'stop']);
    }

    private function sendLocationResponse($status, array $payload) {
        http_response_code($status);
        echo json_encode($payload);
        exit;
    }

    public function receipt() {
        if (!isset($_SESSION['user_id'])) {
            header("Location: " . BASE_URL . "login.php");
            exit;
        }

        $rental_id = filter_var($_GET['rental_id'] ?? null, FILTER_VALIDATE_INT);
        $receipt = $rental_id
            ? $this->rentalModel->getUserReceipt((int)$rental_id, (int)$_SESSION['user_id'])
            : null;

        if (!$receipt) {
            http_response_code(404);
            exit('Receipt not found.');
        }

        require_once __DIR__ . '/../Views/rentals/receipt.php';
    }

    public function adminManageRentals() {
        if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
            header("Location: " . BASE_URL . "login.php");
            exit;
        }

        if (isset($_POST['payment_action'], $_POST['rental_id'])) {
            $rental_id = (int)$_POST['rental_id'];
            $payment = $this->paymentModel->getByRentalId($rental_id);
            $paymentAction = $_POST['payment_action'];
            $updated = false;
            $successMessage = '';

            if ($payment && $paymentAction === 'cash_received' && $payment['payment_method'] === Payment::METHOD_CASH) {
                $updated = $this->paymentModel->markCashReceived($payment['id']);
                $successMessage = 'Cash payment recorded.';
            } elseif ($payment &&
                in_array($payment['payment_method'], [Payment::METHOD_GCASH, Payment::METHOD_BANK_TRANSFER], true) &&
                in_array($paymentAction, ['confirm', 'reject'], true)) {
                $paymentStatus = $paymentAction === 'confirm'
                    ? Payment::STATUS_COMPLETED
                    : Payment::STATUS_CANCELLED;
                $updated = $this->paymentModel->updateStatus($payment['id'], $paymentStatus);
                $successMessage = $paymentStatus === Payment::STATUS_COMPLETED
                    ? 'Transfer receipt confirmed.'
                    : 'Transfer receipt rejected.';
            }

            if ($updated) {
                $_SESSION['swal_success'] = $successMessage;
            } else {
                $_SESSION['swal_error'] = 'Could not update this payment receipt.';
            }
            header("Location: " . BASE_URL . "admin/rentals.php");
            exit;
        }

        // Handle Status Change
        if (isset($_POST['action']) && isset($_POST['rental_id'])) {
            $rental_id = $_POST['rental_id'];
            $new_status = $_POST['action'];

            $rental = $this->rentalModel->getRentalDetails($rental_id);
            if ($rental && $rental['status'] === 'pending' &&
                in_array($new_status, ['approved', 'rejected', 'cancelled'], true)) {
                $canUpdateStatus = true;
                if ($new_status === 'approved') {
                    $this->db->begin_transaction();
                    $lockCar = $this->db->prepare("SELECT status FROM cars WHERE plate_number = ? FOR UPDATE");
                    $lockCar->bind_param('s', $rental['car_id']);
                    $lockCar->execute();
                    $lockedCar = $lockCar->get_result()->fetch_assoc();
                    if (!$lockedCar || $lockedCar['status'] === Car::STATUS_MAINTENANCE ||
                        !$this->carModel->isAvailableForPeriod($rental['car_id'], $rental['start_date'], $rental['end_date'], false)) {
                        $this->db->rollback();
                        $canUpdateStatus = false;
                        $_SESSION['swal_error'] = 'This rental cannot be approved because the vehicle is unavailable for those dates.';
                    } elseif ($this->rentalModel->updateStatus($rental_id, $new_status)) {
                        $this->db->commit();
                    } else {
                        $this->db->rollback();
                        $canUpdateStatus = false;
                        $_SESSION['swal_error'] = 'Could not update the rental request.';
                    }
                } elseif (!$this->rentalModel->updateStatus($rental_id, $new_status)) {
                    $canUpdateStatus = false;
                    $_SESSION['swal_error'] = 'Could not update the rental request.';
                }

                if ($canUpdateStatus) {
                    $dates = date('M d', strtotime($rental['start_date'])) . ' - ' . date('M d', strtotime($rental['end_date']));
                    $totalPrice = '₱' . number_format($rental['total_price'], 2);

                    // Send Email using PHPMailer & config.php constants
                    $this->rentalModel->sendStatusEmail($rental['email'], $rental['user_name'], $rental['car_model'], $new_status, $dates, $totalPrice);

                    $_SESSION['swal_success'] = "Rental request has been " . $new_status . " successfully.";
                }
            }
            header("Location: " . BASE_URL . "admin/rentals.php");
            exit;
        }

        $rentals = $this->rentalModel->getAllRentals();
        require_once __DIR__ . '/../Views/rentals/admin_rentals.php';
    }

    public function checkPending() {
        if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'not authorized']);
            exit;
        }

        $count = $this->rentalModel->getPendingCount();
        header('Content-Type: application/json');
        echo json_encode(['pending_count' => (int)$count]);
        exit;
    }
}
