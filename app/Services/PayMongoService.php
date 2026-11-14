<?php
namespace App\Services;

use RuntimeException;

class PayMongoService {
    private $secretKey;
    private $apiUrl = 'https://api.paymongo.com';

    public function __construct() {
        $secretKey = defined('PAYMONGO_SECRET_KEY') ? (string)PAYMONGO_SECRET_KEY : '';
        if (!preg_match('/\Ask_test_[A-Za-z0-9]+\z/D', $secretKey)) {
            throw new RuntimeException('PayMongo test mode requires a test secret key in the local .env file.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PayMongo checkout is unavailable because the cURL extension is missing.');
        }

        $this->secretKey = $secretKey;
    }

    public function createCheckoutSession($amountPesos, $description, $referenceNumber, $successUrl, $cancelUrl) {
        $amountCentavos = (int)round((float)$amountPesos * 100);
        if ($amountCentavos < 100 || $amountCentavos > 10000000) {
            throw new RuntimeException('E-wallet payments must be between ₱1.00 and ₱100,000.00.');
        }

        $response = $this->request('POST', '/checkout_sessions', [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'name' => substr($description, 0, 100),
                        'amount' => $amountCentavos,
                        'currency' => 'PHP',
                        'quantity' => 1
                    ]],
                    'payment_method_types' => ['gcash', 'paymaya', 'grab_pay'],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'reference_number' => $referenceNumber
                ]
            ]
        ], bin2hex(random_bytes(16)));

        $session = $response['data'] ?? null;
        if (!is_array($session) || empty($session['id']) || empty($session['attributes']['checkout_url'])) {
            throw new RuntimeException('PayMongo could not start checkout. Please try again later.');
        }
        if (!$this->isExpectedMode($session)) {
            throw new RuntimeException('PayMongo returned a checkout session for the wrong environment.');
        }

        return [
            'id' => (string)$session['id'],
            'url' => $this->validateCheckoutUrl($session['attributes']['checkout_url'])
        ];
    }

    public function retrieveCheckoutSession($sessionId) {
        if (!is_string($sessionId) || !preg_match('/\Acs_[A-Za-z0-9_-]+\z/D', $sessionId)) {
            throw new RuntimeException('The PayMongo checkout session is invalid.');
        }

        $response = $this->request(
            'GET',
            '/checkout_sessions/' . rawurlencode($sessionId),
            null,
            '',
            'v1'
        );
        $session = $response['data'] ?? null;
        if (!is_array($session) || ($session['id'] ?? '') !== $sessionId) {
            throw new RuntimeException('PayMongo could not verify this checkout session.');
        }

        if (!empty($session['attributes']['checkout_url'])) {
            $session['attributes']['checkout_url'] = $this->validateCheckoutUrl($session['attributes']['checkout_url']);
        }

        return $session;
    }

    public function expireCheckoutSession($sessionId) {
        if (!is_string($sessionId) || !preg_match('/\Acs_[A-Za-z0-9_-]+\z/D', $sessionId)) {
            throw new RuntimeException('The PayMongo checkout session is invalid.');
        }

        $this->request(
            'POST',
            '/checkout_sessions/' . rawurlencode($sessionId) . '/expire',
            null,
            '',
            'v1'
        );
    }

    public function hasPaidPayment(array $session) {
        $payments = $session['attributes']['payments'] ?? [];
        if (!is_array($payments)) {
            return false;
        }

        foreach ($payments as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid') {
                return true;
            }
        }

        return false;
    }

    public function isExpectedMode(array $session) {
        $attributes = $session['attributes'] ?? [];
        if (!array_key_exists('livemode', $attributes)) {
            return false;
        }

        return is_bool($attributes['livemode']) && $attributes['livemode'] === false;
    }

    private function validateCheckoutUrl($url) {
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'checkout.paymongo.com') {
            throw new RuntimeException('PayMongo returned an invalid checkout URL.');
        }

        return $url;
    }

    private function request($method, $path, ?array $payload = null, $idempotencyKey = '', $apiVersion = 'v2') {
        $curl = curl_init($this->apiUrl . '/' . $apiVersion . $path);
        if ($curl === false) {
            throw new RuntimeException('Could not connect to PayMongo. Please try again later.');
        }

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ];
        if ($payload !== null) {
            $encodedPayload = json_encode($payload);
            if ($encodedPayload === false) {
                curl_close($curl);
                throw new RuntimeException('Could not prepare the PayMongo request.');
            }
            $options[CURLOPT_POSTFIELDS] = $encodedPayload;
        }

        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $transportFailed = $body === false;
        curl_close($curl);

        if ($transportFailed) {
            error_log('PayMongo API request failed during transport.');
            throw new RuntimeException('Could not connect to PayMongo. Please try again later.');
        }

        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            error_log('PayMongo API request returned HTTP ' . $status . '.');
            throw new RuntimeException('PayMongo could not process the payment request. Check the e-wallet settings and try again.');
        }

        return $decoded;
    }
}
