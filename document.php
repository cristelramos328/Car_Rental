<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Authentication required.');
}

$rentalId = filter_var($_GET['rental_id'] ?? null, FILTER_VALIDATE_INT);
$type = $_GET['type'] ?? '';
if (!$rentalId || !in_array($type, ['license', 'receipt'], true)) {
    http_response_code(400);
    exit('Invalid document request.');
}

if ($type === 'license') {
    $stmt = $mysqli->prepare("SELECT user_id, driver_license_image AS document_path FROM rentals WHERE id = ?");
} else {
    $stmt = $mysqli->prepare("SELECT r.user_id, p.receipt_image AS document_path FROM rentals r JOIN transactions p ON p.rental_id = r.id WHERE r.id = ?");
}
$stmt->bind_param('i', $rentalId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();

if (!$document || ((int)$document['user_id'] !== (int)$_SESSION['user_id'] && ($_SESSION['role'] ?? '') !== 'owner')) {
    http_response_code(404);
    exit('Document not found.');
}

$relativePath = $document['document_path'] ?? '';
if (!preg_match('/^private_documents\/[a-f0-9]{48}\.(jpg|png|webp)$/', $relativePath)) {
    http_response_code(404);
    exit('Document not found.');
}

$privateDirectory = realpath(__DIR__ . '/uploads/private_documents');
$documentPath = realpath(__DIR__ . '/uploads/' . $relativePath);
if (!$privateDirectory || !$documentPath || strpos($documentPath, $privateDirectory . DIRECTORY_SEPARATOR) !== 0 || !is_file($documentPath)) {
    http_response_code(404);
    exit('Document not found.');
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($documentPath);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Unsupported document type.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($documentPath));
header('Content-Disposition: inline; filename="' . basename($documentPath) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($documentPath);
