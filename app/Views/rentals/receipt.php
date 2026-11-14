<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$paymentLabels = [
    'cash' => 'Cash',
    'gcash' => 'GCash',
    'bank_transfer' => 'Bank transfer',
    'credit_card' => 'Credit card',
    'debit_card' => 'Debit card',
    'online' => 'E-wallet via PayMongo'
];
$paymentStatus = $receipt['payment_status'] ?? 'pending';
$paymentMethod = $receipt['payment_method'] ?? 'cash';
$receiptNumber = 'CR-' . str_pad((string)$receipt['rental_id'], 5, '0', STR_PAD_LEFT);
$referenceNumber = $receipt['reference_number'] ?: ($receipt['transaction_id'] ?: $receiptNumber);
$receiptDate = $receipt['payment_date'] ?: $receipt['rental_updated_at'];
$receiptDate = $receiptDate ?: $receipt['rental_created_at'];
$rentalDays = max(1, (int)((strtotime($receipt['end_date']) - strtotime($receipt['start_date'])) / 86400));
$rentalTotal = (float)$receipt['total_price'];
$amountPaid = $paymentStatus === 'completed' ? (float)($receipt['amount'] ?? 0) : 0;
$balanceDue = in_array($paymentStatus, ['completed', 'refunded'], true) ? 0 : $rentalTotal;
$statusLabel = $paymentStatus === 'completed'
    ? 'PAID'
    : ($paymentStatus === 'refunded' ? 'REFUNDED' : 'PAYMENT DUE');
$statusClass = $paymentStatus === 'completed'
    ? 'paid'
    : ($paymentStatus === 'refunded' ? 'refunded' : 'due');
?>

<style>
    .rental-receipt { max-width: 860px; margin: 0 auto; color: #172033; }
    .receipt-brand-mark { width: 52px; height: 52px; display: grid; place-items: center; border: 2px solid #14243a; border-radius: 50%; font-size: 1.65rem; }
    .receipt-section-title { background: #14243a; color: #fff; padding: .55rem .8rem; border-radius: .4rem; font-size: .82rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .receipt-detail { border-bottom: 1px solid #e8ebf0; padding: .55rem 0; }
    .receipt-detail:last-child { border-bottom: 0; }
    .receipt-detail-label { color: #677386; font-size: .86rem; }
    .receipt-table th { background: #f0f3f7; color: #39465a; }
    .receipt-vehicle-image { display: block; width: 100%; max-width: 280px; height: 170px; object-fit: cover; border-radius: .5rem; }
    .receipt-total { border-top: 2px solid #14243a; }
    .receipt-status { display: inline-flex; align-items: center; gap: .5rem; border-radius: 999px; padding: .7rem 1.25rem; font-weight: 800; letter-spacing: .08em; }
    .receipt-status.paid { background: #dff4e7; color: #14763b; }
    .receipt-status.due { background: #fff0cf; color: #8a5a00; }
    .receipt-status.refunded { background: #e8eaf0; color: #4b5565; }
    .receipt-signature { min-width: 220px; border-top: 1px solid #596579; padding-top: .5rem; }
    @media print {
        body { background: #fff !important; }
        .navbar, .site-footer, .receipt-actions { display: none !important; }
        .container { max-width: 100% !important; padding: 0 !important; }
        .rental-receipt { max-width: 100% !important; border: 0 !important; box-shadow: none !important; }
        .rental-receipt .card-body { padding: 0 !important; }
        .receipt-section-title { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .receipt-status { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    }
</style>

<main class="container py-5">
    <article class="rental-receipt card border-0 shadow-sm">
        <div class="card-body p-4 p-md-5">
            <header class="d-flex justify-content-between align-items-start gap-3 flex-wrap border-bottom pb-4 mb-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="receipt-brand-mark"><i class="bi bi-car-front-fill"></i></div>
                    <div>
                        <div class="fw-bold text-uppercase">VEHICLE RENTAL</div>
                        <div class="small text-muted">Official Rental Receipt</div>
                    </div>
                </div>
                <div class="text-sm-end">
                    <div class="text-muted small">Receipt No.</div>
                    <div class="fw-bold"><?php echo htmlspecialchars($receiptNumber); ?></div>
                    <div class="text-muted small mt-1">Date: <?php echo htmlspecialchars(date('M d, Y', strtotime($receiptDate))); ?></div>
                </div>
            </header>

            <section class="mb-4">
                <h2 class="receipt-section-title"><i class="bi bi-person-vcard me-2"></i>Customer Information</h2>
                <div class="row g-3 pt-2">
                    <div class="col-md-6 receipt-detail">
                        <div class="receipt-detail-label">Customer name</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($receipt['customer_name']); ?></div>
                    </div>
                    <div class="col-md-6 receipt-detail">
                        <div class="receipt-detail-label">Contact number</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($receipt['customer_phone'] ?: 'Not provided'); ?></div>
                    </div>
                    <div class="col-md-6 receipt-detail">
                        <div class="receipt-detail-label">Address</div>
                        <div class="fw-semibold"><?php echo nl2br(htmlspecialchars($receipt['customer_address'] ?: 'Not provided')); ?></div>
                    </div>
                    <div class="col-md-6 receipt-detail">
                        <div class="receipt-detail-label">Driver's License No.</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($receipt['driver_license_number'] ?: 'Not provided'); ?></div>
                    </div>
                </div>
            </section>

            <section class="mb-4">
                <h2 class="receipt-section-title"><i class="bi bi-car-front me-2"></i>Vehicle Information</h2>
                <div class="row g-3 pt-2">
                    <?php $carImages = getCarImagesList($receipt['image'] ?? ''); ?>
                    <?php if (!empty($carImages)): ?>
                        <div class="col-md-5">
                            <img src="<?php echo BASE_URL . 'uploads/' . rawurlencode($carImages[0]); ?>"
                                 class="receipt-vehicle-image"
                                 alt="<?php echo htmlspecialchars(trim($receipt['brand'] . ' ' . $receipt['model']), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="<?php echo !empty($carImages) ? 'col-md-7' : 'col-md-6'; ?> receipt-detail">
                        <div class="receipt-detail-label">Vehicle</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars(trim($receipt['brand'] . ' ' . $receipt['model'])); ?></div>
                    </div>
                    <div class="col-md-3 receipt-detail">
                        <div class="receipt-detail-label">Plate number</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($receipt['plate_number']); ?></div>
                    </div>
                    <div class="col-md-3 receipt-detail">
                        <div class="receipt-detail-label">Vehicle type</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars(ucfirst($receipt['type'] ?: 'Vehicle')); ?></div>
                    </div>
                </div>
            </section>

            <section class="mb-4">
                <h2 class="receipt-section-title"><i class="bi bi-calendar-range me-2"></i>Rental Information</h2>
                <div class="row g-3 pt-2">
                    <div class="col-md-4 receipt-detail">
                        <div class="receipt-detail-label">Pick-up date</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars(date('M d, Y', strtotime($receipt['start_date']))); ?></div>
                    </div>
                    <div class="col-md-4 receipt-detail">
                        <div class="receipt-detail-label">Return date</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars(date('M d, Y', strtotime($receipt['end_date']))); ?></div>
                    </div>
                    <div class="col-md-4 receipt-detail">
                        <div class="receipt-detail-label">Rental duration</div>
                        <div class="fw-semibold"><?php echo $rentalDays; ?> day(s)</div>
                    </div>
                </div>
            </section>

            <section class="mb-4">
                <h2 class="receipt-section-title"><i class="bi bi-receipt me-2"></i>Charges</h2>
                <div class="table-responsive mt-2">
                    <table class="table table-bordered align-middle receipt-table mb-0">
                        <thead>
                            <tr><th>Description</th><th class="text-end">Amount</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Vehicle rental (<?php echo $rentalDays; ?> day(s) × ₱<?php echo number_format((float)$receipt['price_per_day'], 2); ?> per day)</td>
                                <td class="text-end">₱<?php echo number_format($rentalTotal, 2); ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="receipt-total">
                                <th class="text-end">Total amount</th>
                                <th class="text-end">₱<?php echo number_format($rentalTotal, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap border-bottom pb-4 mb-4">
                <div>
                    <div class="receipt-detail-label">Payment method</div>
                    <div class="fw-semibold"><?php echo htmlspecialchars($paymentLabels[$paymentMethod] ?? ucfirst($paymentMethod)); ?></div>
                    <div class="receipt-detail-label mt-2">Payment reference</div>
                    <div class="fw-semibold"><?php echo htmlspecialchars($referenceNumber); ?></div>
                </div>
            </div>

            <footer class="d-flex justify-content-between align-items-end gap-4 flex-wrap">
                <div class="receipt-signature text-center">
                    <div class="small">Customer signature</div>
                </div>
                <p class="small text-muted mb-0">Thank you for choosing Vega's Car Rental. Please keep this receipt for your records.</p>
            </footer>

            <div class="receipt-actions d-flex justify-content-between align-items-center gap-3 flex-wrap mt-5">
                <a href="<?php echo BASE_URL; ?>my_rentals.php" class="btn btn-outline-secondary rounded-pill px-4">
                    <i class="bi bi-arrow-left me-1"></i>Transaction history
                </a>
                <button type="button" class="btn btn-primary rounded-pill px-4" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print receipt
                </button>
            </div>
        </div>
    </article>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
