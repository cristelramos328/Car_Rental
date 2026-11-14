<?php
namespace App\Models;

class PayMongoBookingDraft {
    private $db;

    public function __construct($mysqli) {
        $this->db = $mysqli;
    }

    public function create(
        $userId,
        $carId,
        $startDate,
        $endDate,
        $totalPrice,
        $licenseNumber,
        $licenseImage,
        $customerName,
        $customerPhone,
        $customerAddress,
        $customerLatitude,
        $customerLongitude,
        $sessionId,
        $referenceNumber
    ) {
        $stmt = $this->db->prepare(
            "INSERT INTO paymongo_booking_drafts (
                user_id, car_id, start_date, end_date, total_price,
                driver_license_number, driver_license_image, customer_name,
                customer_phone, customer_address, customer_latitude,
                customer_longitude, checkout_session_id, reference_number
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'isssdsssssssss',
            $userId,
            $carId,
            $startDate,
            $endDate,
            $totalPrice,
            $licenseNumber,
            $licenseImage,
            $customerName,
            $customerPhone,
            $customerAddress,
            $customerLatitude,
            $customerLongitude,
            $sessionId,
            $referenceNumber
        );
        return $stmt->execute();
    }

    public function getByReference($referenceNumber, $userId = null, $forUpdate = false) {
        $query = 'SELECT * FROM paymongo_booking_drafts WHERE reference_number = ?';
        if ($userId !== null) {
            $query .= ' AND user_id = ?';
        }
        if ($forUpdate) {
            $query .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($query);
        if ($userId !== null) {
            $stmt->bind_param('si', $referenceNumber, $userId);
        } else {
            $stmt->bind_param('s', $referenceNumber);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function delete($referenceNumber, $sessionId) {
        $stmt = $this->db->prepare(
            'DELETE FROM paymongo_booking_drafts
             WHERE reference_number = ? AND checkout_session_id = ?'
        );
        $stmt->bind_param('ss', $referenceNumber, $sessionId);
        return $stmt->execute() && $stmt->affected_rows === 1;
    }
}
