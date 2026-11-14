<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">
        <div>
            <h2 class="fw-bold mb-1">My Rentals &amp; Receipts</h2>
            <p class="text-muted mb-0">Review your bookings and payments. A printable rental receipt is available after admin approval.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>dashboard.php" class="btn btn-primary rounded-pill px-4 fw-bold">
            <i class="bi bi-car-front me-2"></i>Book a car
        </a>
    </div>

    <?php if(isset($_GET['msg']) && $_GET['msg'] == 'success'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Rental request submitted successfully! waiting for approval.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-2 align-items-center mb-3">
        <div class="col-md-7 col-lg-5">
            <label for="transactionSearch" class="visually-hidden">Search transaction history</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input type="search" class="form-control" id="transactionSearch"
                       placeholder="Search by car, model, brand, date, status, or reference" autocomplete="off">
            </div>
        </div>
        <div class="col-auto text-muted small" id="transactionCount" aria-live="polite"></div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="transactionTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Car</th>
                            <th>Model</th>
                            <th>Brand</th>
                            <th>Rental Period</th>
                            <th>Total Cost</th>
                            <th>Payment</th>
                            <th>Rental Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr class="transaction-row">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <?php $carImages = getCarImagesList($row['image'] ?? ''); ?>
                                            <?php if (!empty($carImages)): ?>
                                                <img src="<?php echo BASE_URL . 'uploads/' . rawurlencode($carImages[0]); ?>" width="60" height="40" class="rounded object-fit-cover me-3" alt="<?php echo htmlspecialchars($row['brand'] . ' ' . $row['model']); ?>">
                                            <?php else: ?>
                                                <span class="rounded bg-light d-inline-flex align-items-center justify-content-center me-3 text-muted" style="width: 60px; height: 40px;" aria-label="No vehicle image">
                                                    <i class="bi bi-car-front" aria-hidden="true"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($row['model']); ?></td>
                                    <td><?php echo htmlspecialchars($row['brand']); ?></td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($row['start_date'])); ?> 
                                        <i class="bi bi-arrow-right mx-1 text-muted small"></i> 
                                        <?php echo date('M d, Y', strtotime($row['end_date'])); ?>
                                    </td>
                                    <td class="fw-bold">₱<?php echo number_format($row['total_price'], 2); ?></td>
                                    <td>
                                        <span class="d-block fw-semibold"><?php
                                            $paymentLabels = [
                                                'cash' => 'Cash upon pickup',
                                                'gcash' => 'GCash',
                                                'bank_transfer' => 'Bank transfer',
                                                'credit_card' => 'Credit card',
                                                'debit_card' => 'Debit card',
                                                'online' => 'E-wallet via PayMongo'
                                            ];
                                            echo htmlspecialchars($paymentLabels[$row['payment_method'] ?? 'cash'] ?? ucfirst($row['payment_method'] ?? 'cash'));
                                        ?></span>
                                        <small class="text-muted">
                                            <?php
                                            if (($row['payment_status'] ?? '') === 'completed') {
                                                echo 'Paid';
                                            } elseif (($row['payment_status'] ?? '') === 'refunded') {
                                                echo 'Refunded';
                                            } elseif (($row['payment_status'] ?? '') === 'cancelled') {
                                                echo ($row['payment_method'] ?? '') === 'online'
                                                    ? 'Payment cancelled'
                                                    : 'Receipt rejected';
                                            } elseif (($row['payment_method'] ?? '') === 'cash') {
                                                echo 'Due on pickup';
                                            } else {
                                                echo htmlspecialchars(ucfirst($row['payment_status'] ?? 'pending'));
                                            }
                                            ?>
                                            <?php if (!empty($row['reference_number'])): ?>
                                                · Ref <?php echo htmlspecialchars($row['reference_number']); ?>
                                            <?php endif; ?>
                                        </small>
                                        <?php if (in_array($row['status'], ['approved', 'completed'], true)): ?>
                                            <a class="d-block small fw-semibold mt-1" href="<?php echo BASE_URL; ?>receipt.php?rental_id=<?php echo (int)$row['id']; ?>">
                                                <i class="bi bi-receipt me-1"></i>View / print rental receipt
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($row['receipt_image'])): ?>
                                            <a class="d-block small" href="<?php echo BASE_URL; ?>document.php?type=receipt&amp;rental_id=<?php echo (int)$row['id']; ?>" target="_blank" rel="noopener">View submitted payment proof</a>
                                        <?php endif; ?>
                                        <?php if (($row['payment_method'] ?? '') === 'online' &&
                                            ($row['payment_status'] ?? '') === 'pending' &&
                                            !empty($row['reference_number'])): ?>
                                            <a class="d-block small fw-semibold mt-1"
                                               href="<?php echo BASE_URL; ?>paymongo_checkout.php?reference=<?php echo rawurlencode($row['reference_number']); ?>">
                                                Continue e-wallet payment
                                            </a>
                                            <?php if (($row['status'] ?? '') === 'pending'): ?>
                                                <form method="POST" action="<?php echo BASE_URL; ?>rent_process.php" class="mt-2">
                                                    <input type="hidden" name="action" value="cancel_paymongo">
                                                    <input type="hidden" name="rental_id" value="<?php echo (int)$row['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">
                                                        Cancel unpaid booking
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (in_array($row['payment_method'] ?? '', ['gcash', 'bank_transfer'], true) && ($row['payment_status'] ?? '') === 'cancelled'): ?>
                                            <form method="POST" action="<?php echo BASE_URL; ?>rent_process.php" enctype="multipart/form-data" class="mt-2">
                                                <input type="hidden" name="action" value="resubmit_receipt">
                                                <input type="hidden" name="rental_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="text" name="reference_number" class="form-control form-control-sm mb-2" placeholder="Transfer reference number" maxlength="100" required>
                                                <input type="file" name="receipt_image" class="form-control form-control-sm mb-2" accept="image/jpeg,image/png,image/webp" required>
                                                <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill">Resubmit receipt</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!empty($row['driver_license_image'])): ?>
                                            <a class="d-block small" href="<?php echo BASE_URL; ?>document.php?type=license&amp;rental_id=<?php echo (int)$row['id']; ?>" target="_blank" rel="noopener">View my license</a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $statusClass = 'secondary';
                                        if($row['status'] == 'approved') $statusClass = 'success';
                                        if($row['status'] == 'completed') $statusClass = 'success';
                                        if($row['status'] == 'rejected') $statusClass = 'danger';
                                        if($row['status'] == 'pending') $statusClass = 'warning';
                                        $statusLabel = in_array($row['status'], ['approved', 'completed'], true)
                                            ? 'Completed'
                                            : ucfirst($row['status']);
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?> rounded-pill px-3"><?php echo htmlspecialchars($statusLabel); ?></span>
                                        <?php if (($_SESSION['role'] ?? '') === 'customer'): ?>
                                        <div class="mt-2" data-gps-share data-rental-id="<?php echo (int)$row['id']; ?>">
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill gps-share-toggle">
                                                <i class="bi bi-broadcast-pin me-1"></i>Share Live Location
                                            </button>
                                            <div class="small text-muted mt-1 gps-share-status" aria-live="polite">
                                                Admin/owner-only sharing. Works while this page stays open.
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <tr id="noTransactionsMatch" class="d-none">
                                <td colspan="7" class="text-center py-4 text-muted">No transactions match your search.</td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="bi bi-calendar2-plus fs-1 d-block text-primary mb-2"></i>
                                    <strong class="d-block mb-2">You don't have any transactions yet.</strong>
                                    <a href="<?php echo BASE_URL; ?>dashboard.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">Browse available cars</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var gpsCsrfToken = <?php echo json_encode($gpsCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var activeWatchers = new Map();

        function sendLocation(rentalId, action, latitude, longitude, keepalive) {
            var body = new URLSearchParams({
                rental_id: rentalId,
                action: action,
                csrf_token: gpsCsrfToken
            });
            if (latitude !== undefined && longitude !== undefined) {
                body.set('latitude', latitude);
                body.set('longitude', longitude);
            }
            return fetch('<?php echo BASE_URL; ?>location.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                keepalive: Boolean(keepalive)
            }).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok) {
                        throw new Error(payload.error || 'Could not update GPS sharing.');
                    }
                    return payload;
                });
            });
        }

        document.querySelectorAll('[data-gps-share]').forEach(function (container) {
            var rentalId = container.dataset.rentalId;
            var button = container.querySelector('.gps-share-toggle');
            var status = container.querySelector('.gps-share-status');

            function stopSharing(notifyServer) {
                var watchId = activeWatchers.get(rentalId);
                if (watchId !== undefined) {
                    navigator.geolocation.clearWatch(watchId);
                    activeWatchers.delete(rentalId);
                }
                button.disabled = true;
                if (notifyServer) {
                    sendLocation(rentalId, 'stop').then(function () {
                        status.textContent = 'GPS sharing is off.';
                        button.innerHTML = '<i class="bi bi-broadcast-pin me-1"></i>Share Live Location';
                    }).catch(function (error) {
                        status.textContent = error.message;
                    }).finally(function () {
                        button.disabled = false;
                    });
                } else {
                    button.innerHTML = '<i class="bi bi-broadcast-pin me-1"></i>Share Live Location';
                }
            }

            button.addEventListener('click', function () {
                if (activeWatchers.has(rentalId)) {
                    stopSharing(true);
                    return;
                }
                if (!navigator.geolocation) {
                    status.textContent = 'GPS location is not supported by this browser.';
                    return;
                }

                button.disabled = true;
                status.textContent = 'Requesting GPS permission...';
                navigator.geolocation.getCurrentPosition(function (position) {
                    var latitude = position.coords.latitude.toFixed(7);
                    var longitude = position.coords.longitude.toFixed(7);
                    sendLocation(rentalId, 'start', latitude, longitude).then(function () {
                        status.textContent = 'Live GPS is shared with the admin/owner only.';
                        button.innerHTML = '<i class="bi bi-broadcast me-1"></i>Stop sharing GPS';
                        var lastSentAt = 0;
                        var watchId = navigator.geolocation.watchPosition(function (update) {
                            var now = Date.now();
                            if (now - lastSentAt < 5000) {
                                return;
                            }
                            lastSentAt = now;
                            sendLocation(
                                rentalId,
                                'update',
                                update.coords.latitude.toFixed(7),
                                update.coords.longitude.toFixed(7)
                            ).catch(function (error) {
                                status.textContent = error.message;
                                stopSharing(true);
                            });
                        }, function (error) {
                            status.textContent = error.code === 1
                                ? 'GPS permission was revoked. Sharing stopped.'
                                : 'GPS signal lost. Check your device location settings.';
                            stopSharing(true);
                        }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 5000 });
                        activeWatchers.set(rentalId, watchId);
                    }).catch(function (error) {
                        status.textContent = error.message;
                    }).finally(function () {
                        button.disabled = false;
                    });
                }, function (error) {
                    var messages = {
                        1: 'Location permission was denied. Allow GPS access and try again.',
                        2: 'Your location could not be determined. Check your device GPS.',
                        3: 'Getting your location timed out. Please try again.'
                    };
                    status.textContent = messages[error.code] || 'Could not get your GPS location.';
                    button.disabled = false;
                }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
            });
        });

        window.addEventListener('pagehide', function () {
            activeWatchers.forEach(function (watchId, rentalId) {
                navigator.geolocation.clearWatch(watchId);
                var body = new URLSearchParams({
                    rental_id: rentalId,
                    action: 'stop',
                    csrf_token: gpsCsrfToken
                });
                navigator.sendBeacon('<?php echo BASE_URL; ?>location.php', body);
            });
            activeWatchers.clear();
        });
    })();

    (function () {
        var searchInput = document.getElementById('transactionSearch');
        var transactionTable = document.getElementById('transactionTable');
        if (!searchInput || !transactionTable) {
            return;
        }

        var rows = Array.prototype.slice.call(transactionTable.querySelectorAll('tbody tr'))
            .filter(function (row) { return row.classList.contains('transaction-row'); });
        var noMatchesRow = document.getElementById('noTransactionsMatch');
        var count = document.getElementById('transactionCount');

        function filterTransactions() {
            var query = searchInput.value.trim().toLocaleLowerCase();
            var visibleCount = 0;

            rows.forEach(function (row) {
                var matches = row.textContent.toLocaleLowerCase().indexOf(query) !== -1;
                row.classList.toggle('d-none', !matches);
                if (matches) {
                    visibleCount++;
                }
            });

            if (noMatchesRow) {
                noMatchesRow.classList.toggle('d-none', visibleCount > 0);
            }
            if (count) {
                count.textContent = visibleCount + (visibleCount === 1 ? ' transaction' : ' transactions');
            }
        }

        searchInput.addEventListener('input', filterTransactions);
        filterTransactions();
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
