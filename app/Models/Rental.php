<?php
namespace App\Models;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Rental {
    private $db;

    public function __construct($mysqli) {
        $this->db = $mysqli;
    }

    public function createRental($user_id, $car_id, $start_date, $end_date, $total_price, $license_number = '', $license_image = null, $customer_name = null, $customer_phone = null, $customer_address = null, $customer_latitude = null, $customer_longitude = null) {
        $stmt = $this->db->prepare("INSERT INTO rentals (user_id, car_id, start_date, end_date, total_price, status, driver_license_number, driver_license_image, customer_name, customer_phone, customer_address, customer_latitude, customer_longitude) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssdsssssss", $user_id, $car_id, $start_date, $end_date, $total_price, $license_number, $license_image, $customer_name, $customer_phone, $customer_address, $customer_latitude, $customer_longitude);
        return $stmt->execute();
    }

    public function getUserRentals($user_id) {
        $stmt = $this->db->prepare("SELECT r.*, c.model, c.brand, c.plate_number, c.image,
                                           p.id AS payment_id, p.amount, p.payment_method, p.payment_status,
                                           p.reference_number, p.transaction_id, p.payment_date,
                                           p.created_at AS payment_created_at, p.receipt_image
                                    FROM rentals r
                                    JOIN cars c ON r.car_id = c.plate_number
                                    LEFT JOIN transactions p ON p.rental_id = r.id
                                    WHERE r.user_id = ?
                                      AND (p.id IS NULL OR NOT (
                                           p.payment_method = 'online'
                                           AND p.payment_status IN ('pending', 'cancelled')
                                      ))
                                    ORDER BY r.created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getUserReceipt($rental_id, $user_id) {
        $stmt = $this->db->prepare("SELECT r.id AS rental_id, r.start_date, r.end_date, r.total_price, r.status AS rental_status,
                                           r.driver_license_number, r.created_at AS rental_created_at, r.updated_at AS rental_updated_at,
                                           c.brand, c.model, c.type, c.price_per_day, c.plate_number, c.image,
                                           COALESCE(NULLIF(r.customer_name, ''), u.name) AS customer_name,
                                           u.email AS customer_email,
                                           COALESCE(NULLIF(r.customer_phone, ''), u.phone) AS customer_phone,
                                           COALESCE(NULLIF(r.customer_address, ''), u.address) AS customer_address,
                                           p.amount, p.payment_method, p.payment_status, p.transaction_id,
                                           p.reference_number, p.payment_date, p.created_at AS payment_created_at
                                    FROM rentals r
                                    JOIN cars c ON r.car_id = c.plate_number
                                    JOIN users u ON r.user_id = u.id
                                    LEFT JOIN transactions p ON p.rental_id = r.id AND p.user_id = r.user_id
                                    WHERE r.id = ? AND r.user_id = ?
                                      AND r.status IN ('approved', 'completed')
                                    LIMIT 1");
        $stmt->bind_param("ii", $rental_id, $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getAllRentals() {
        $sql = "SELECT r.*, COALESCE(NULLIF(r.customer_name, ''), u.name) as user_name, u.email,
                       COALESCE(NULLIF(r.customer_phone, ''), u.phone) as phone,
                       COALESCE(NULLIF(r.customer_address, ''), u.address) as address,
                       c.model as car_model, c.brand, c.plate_number,
                       p.id as payment_id, p.payment_method, p.payment_status,
                       p.reference_number, p.receipt_image
                FROM rentals r 
                JOIN users u ON r.user_id = u.id 
                JOIN cars c ON r.car_id = c.plate_number
                LEFT JOIN transactions p ON p.rental_id = r.id
                WHERE p.id IS NULL OR NOT (
                    p.payment_method = 'online'
                    AND p.payment_status IN ('pending', 'cancelled')
                )
                ORDER BY r.created_at DESC";
        return $this->db->query($sql);
    }

    public function getRecentRentals($limit = 5) {
        $limit = (int)$limit;
        $sql = "SELECT r.*, u.name as user_name, c.model, c.year, c.image 
                FROM rentals r 
                JOIN users u ON r.user_id = u.id 
                JOIN cars c ON r.car_id = c.plate_number
                WHERE r.status IN ('pending', 'approved', 'rejected', 'completed')
                  AND NOT EXISTS (
                      SELECT 1 FROM transactions p
                      WHERE p.rental_id = r.id AND p.payment_method = 'online'
                        AND p.payment_status IN ('pending', 'cancelled')
                  )
                ORDER BY r.created_at DESC LIMIT $limit";
        return $this->db->query($sql);
    }

    public function getRentalDetails($rental_id) {
        $query = "SELECT r.*, u.email, u.name as user_name, c.model as car_model 
                  FROM rentals r 
                  JOIN users u ON r.user_id = u.id 
                  JOIN cars c ON r.car_id = c.plate_number
                  WHERE r.id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $rental_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function updateStatus($rental_id, $status) {
        $stmt = $this->db->prepare("UPDATE rentals SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $rental_id);
        return $stmt->execute();
    }

    public function getPendingCount() {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM rentals r
             WHERE r.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM transactions p
                   WHERE p.rental_id = r.id AND p.payment_method = 'online'
                     AND p.payment_status IN ('pending', 'cancelled')
               )"
        );
        return $result ? $result->fetch_assoc()['count'] : 0;
    }

    public function getActiveCount() {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM rentals r
             WHERE r.status IN ('pending', 'approved')
               AND NOT EXISTS (
                   SELECT 1 FROM transactions p
                   WHERE p.rental_id = r.id AND p.payment_method = 'online'
                     AND p.payment_status IN ('pending', 'cancelled')
               )"
        );
        return $result ? $result->fetch_assoc()['count'] : 0;
    }

    public function getTotalRevenue() {
        $result = $this->db->query("SELECT SUM(total_price) as total FROM rentals WHERE status = 'approved'");
        $row = $result ? $result->fetch_assoc() : null;
        return ($row && $row['total']) ? $row['total'] : 0;
    }

    public function sendStatusEmail($toEmail, $userName, $carModel, $status, $dates, $totalPrice) {
        require_once __DIR__ . '/../../admin/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/../../admin/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/../../admin/PHPMailer/src/SMTP.php';

        $mail = new PHPMailer(true);
        try {
            // Configured Server settings from config.php
            $mail->isSMTP();
            $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com'; 
            $mail->SMTPAuth   = true;
            $mail->Username   = defined('SMTP_USERNAME') ? SMTP_USERNAME : ''; 
            $mail->Password   = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : ''; 
            $mail->SMTPSecure = (defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;

            // Bypassing SSL for local servers
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@carrental.com';
            $fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Car Rental';

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $userName);

            $mail->isHTML(true);
            $subjectStatus = strtoupper($status);
            $mail->Subject = "Rental Update: Your Request is $subjectStatus";
            
            $color = ($status == 'approved') ? '#198754' : '#dc3545';
            $statusText = ucfirst($status);

            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                <div style='text-align: center; padding-bottom: 20px; border-bottom: 1px solid #eee;'>
                    <h1 style='color: #0d6efd; margin: 0;'>" . htmlspecialchars($fromName) . "</h1>
                    <p style='color: #6c757d; margin: 5px 0 0;'>PREMIUM CAR RENTAL</p>
                </div>
                
                <div style='padding: 30px 0;'>
                    <h2 style='color: #333;'>Hello, " . htmlspecialchars($userName) . "</h2>
                    <p style='font-size: 16px; color: #555;'>Your rental request for the <strong>" . htmlspecialchars($carModel) . "</strong> has been <strong style='color: $color;'>$subjectStatus</strong>.</p>
                    
                    <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px;'>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px 0; color: #6c757d;'>Vehicle</td>
                                <td style='padding: 8px 0; font-weight: bold; text-align: right;'>" . htmlspecialchars($carModel) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #6c757d;'>Rental Dates</td>
                                <td style='padding: 8px 0; font-weight: bold; text-align: right;'>$dates</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #6c757d;'>Total Price</td>
                                <td style='padding: 8px 0; font-weight: bold; text-align: right; color: #0d6efd;'>$totalPrice</td>
                            </tr>
                            <tr style='border-top: 1px solid #ddd;'>
                                <td style='padding: 15px 0 0; color: #6c757d;'>Status</td>
                                <td style='padding: 15px 0 0; font-weight: bold; text-align: right; color: $color;'>$statusText</td>
                            </tr>
                        </table>
                    </div>

                    <p style='margin-top: 30px; font-size: 14px; color: #888;'>
                        " . ($status == 'approved' ? "Please visit our office to complete the payment and pick up your vehicle." : "If you have any questions, please contact our support.") . "
                    </p>
                </div>

                <div style='text-align: center; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 12px;'>
                    &copy; " . date('Y') . " " . htmlspecialchars($fromName) . ". All rights reserved.
                </div>
            </div>";

            $mail->send();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
