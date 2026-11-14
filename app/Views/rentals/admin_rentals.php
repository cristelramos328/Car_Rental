<?php
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-shell">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="admin-main">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h2 class="fw-bold mb-1">Bookings &amp; Rentals</h2>
            <p class="text-muted mb-0">Review incoming requests and manage rental approvals.</p>
        </div>
    </div>

    <div class="card shadow border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 text-uppercase text-muted small fw-bold tracking-wide">Customer</th>
                            <th class="py-3 text-uppercase text-muted small fw-bold tracking-wide">Vehicle</th>
                            <th class="py-3 text-uppercase text-muted small fw-bold tracking-wide">Duration</th>
                            <th class="py-3 text-uppercase text-muted small fw-bold tracking-wide">Total</th>
                            <th class="py-3 text-uppercase text-muted small fw-bold tracking-wide">Requested</th>
                            <th class="py-3 text-uppercase text-muted small fw-bold tracking-wide">Status</th>
                            <th class="text-end pe-4 py-3 text-uppercase text-muted small fw-bold tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($rentals && $rentals->num_rows > 0): ?>
                            <?php while($row = $rentals->fetch_assoc()): ?>
                                <?php
                                $time_ago = strtotime($row['created_at']);
                                $current_time = time();
                                $time_difference = $current_time - $time_ago;
                                $seconds = $time_difference;
                                $minutes      = round($seconds / 60);           
                                $hours        = round($seconds / 3600);         
                                $days         = round($seconds / 86400);        
                                $weeks        = round($seconds / 604800);       
                                $months       = round($seconds / 2629440);      
                                $years        = round($seconds / 31553280);     

                                if($seconds <= 60) {
                                    $time_string = "Just now";
                                } else if($minutes <=60) {
                                    $time_string = ($minutes==1) ? "one minute ago" : "$minutes mins ago";
                                } else if($hours <=24) {
                                    $time_string = ($hours==1) ? "an hour ago" : "$hours hours ago";
                                } else if($days <= 7) {
                                    $time_string = ($days==1) ? "yesterday" : "$days days ago";
                                } else if($weeks <= 4.3) {
                                    $time_string = ($weeks==1) ? "a week ago" : "$weeks weeks ago";
                                } else if($months <=12) {
                                    $time_string = ($months==1) ? "a month ago" : "$months months ago";
                                } else {
                                    $time_string = ($years==1) ? "one year ago" : "$years years ago";
                                }
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle bg-primary bg-opacity-10 text-primary me-3 fw-bold rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                <?php echo strtoupper(substr($row['user_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($row['user_name']); ?></h6>
                                                <small class="text-muted">Customer</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars($row['car_model']); ?></span>
                                    </td>
                                    <td>
                                        <span class="d-block text-dark small fw-bold">
                                            <?php echo date('M d, Y', strtotime($row['start_date'])); ?> 
                                            <i class="bi bi-arrow-right mx-1 text-muted"></i> 
                                            <?php echo date('M d, Y', strtotime($row['end_date'])); ?>
                                        </span>
                                        <small class="text-muted"><?php 
                                            $diff = strtotime($row['end_date']) - strtotime($row['start_date']);
                                            echo ceil($diff / (60 * 60 * 24)) . ' Days'; 
                                        ?></small>
                                    </td>
                                    <td class="fw-bold text-primary">₱<?php echo number_format($row['total_price'], 2); ?></td>
                                    <td class="text-muted small"><?php echo $time_string; ?></td>
                                    <td>
                                        <?php 
                                        $statusClass = 'bg-secondary text-secondary';
                                        $statusIcon = 'bi-circle';
                                        
                                        if($row['status'] == 'approved') {
                                            $statusClass = 'bg-success text-success';
                                            $statusIcon = 'bi-check-circle-fill';
                                        }
                                        if($row['status'] == 'rejected') {
                                            $statusClass = 'bg-danger text-danger';
                                            $statusIcon = 'bi-x-circle-fill';
                                        }
                                        if($row['status'] == 'pending') {
                                            $statusClass = 'bg-warning text-warning';
                                            $statusIcon = 'bi-clock-fill';
                                        }
                                        ?>
                                        <span class="badge <?php echo $statusClass; ?> bg-opacity-10 py-2 px-3 rounded-pill">
                                            <i class="bi <?php echo $statusIcon; ?> me-1"></i>
                                            <?php echo ucfirst($row['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#rentalDetailModal<?php echo $row['id']; ?>">
                                                View
                                            </button>
                                            <?php if($row['status'] == 'pending'): ?>
                                                <form method="POST" action="<?php echo BASE_URL; ?>admin/rentals.php" class="d-inline">
                                                    <input type="hidden" name="rental_id" value="<?php echo $row['id']; ?>">
                                                    <button type="button" data-rental-confirm="true" data-action="approved" data-customer="<?php echo htmlspecialchars($row['user_name']); ?>" class="btn btn-sm btn-success rounded-pill px-3 fw-bold me-1 hover-float shadow-sm">
                                                        Approve
                                                    </button>
                                                    <button type="button" data-rental-confirm="true" data-action="rejected" data-customer="<?php echo htmlspecialchars($row['user_name']); ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold hover-float shadow-sm">
                                                        Reject
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-light border rounded-pill px-3 disabled text-muted">
                                                    Processed
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <div class="modal fade" id="rentalDetailModal<?php echo $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                                        <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
                                            <div class="modal-header border-0 bg-light px-4 py-3">
                                                <div>
                                                    <p class="small text-uppercase text-muted fw-bold mb-1">Rental details</p>
                                                    <h5 class="modal-title fw-bold mb-0"><?php echo htmlspecialchars($row['car_model']); ?></h5>
                                                </div>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <div class="row g-4">
                                                    <div class="col-md-6">
                                                        <div class="rounded-4 bg-light p-3 h-100">
                                                            <small class="text-uppercase text-muted fw-bold">Customer</small>
                                                            <h6 class="fw-bold mt-2 mb-1"><?php echo htmlspecialchars($row['user_name']); ?></h6>
                                                            <p class="mb-1 text-muted"><?php echo htmlspecialchars($row['email'] ?? 'No email'); ?></p>
                                                            <p class="mb-1 text-muted"><?php echo htmlspecialchars($row['phone'] ?? 'No phone'); ?></p>
                                                            <p class="mb-1 text-muted"><?php echo htmlspecialchars($row['address'] ?? 'No address'); ?></p>
                                                            <p class="mb-0 text-muted">Driver's License No.: <?php echo htmlspecialchars($row['driver_license_number'] ?: 'Not provided'); ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="rounded-4 bg-light p-3 h-100">
                                                            <small class="text-uppercase text-muted fw-bold">Vehicle</small>
                                                            <h6 class="fw-bold mt-2 mb-1"><?php echo htmlspecialchars($row['car_model']); ?></h6>
                                                            <p class="mb-1 text-muted"><?php echo htmlspecialchars($row['brand'] ?? 'N/A'); ?></p>
                                                            <p class="mb-0 text-muted"><?php echo htmlspecialchars($row['plate_number'] ?? 'N/A'); ?></p>
                                                        </div>
                                                    </div>
                                                    <?php
                                                    $hasBookingGps = isset($row['customer_latitude'], $row['customer_longitude']) &&
                                                        is_numeric($row['customer_latitude']) && is_numeric($row['customer_longitude']) &&
                                                        (float)$row['customer_latitude'] >= -90 && (float)$row['customer_latitude'] <= 90 &&
                                                        (float)$row['customer_longitude'] >= -180 && (float)$row['customer_longitude'] <= 180;
                                                    $hasLiveGps = isset($row['tracking_latitude'], $row['tracking_longitude']) &&
                                                        is_numeric($row['tracking_latitude']) && is_numeric($row['tracking_longitude']) &&
                                                        (float)$row['tracking_latitude'] >= -90 && (float)$row['tracking_latitude'] <= 90 &&
                                                        (float)$row['tracking_longitude'] >= -180 && (float)$row['tracking_longitude'] <= 180;
                                                    $initialGpsLatitude = $hasLiveGps
                                                        ? (float)$row['tracking_latitude']
                                                        : ($hasBookingGps ? (float)$row['customer_latitude'] : null);
                                                    $initialGpsLongitude = $hasLiveGps
                                                        ? (float)$row['tracking_longitude']
                                                        : ($hasBookingGps ? (float)$row['customer_longitude'] : null);
                                                    ?>
                                                    <div class="col-12">
                                                        <div class="rounded-4 border p-3">
                                                            <small class="text-uppercase text-muted fw-bold">Customer / vehicle GPS</small>
                                                            <?php if (!empty($row['address'])): ?>
                                                                <p class="mb-2 mt-2"><?php echo nl2br(htmlspecialchars($row['address'])); ?></p>
                                                            <?php endif; ?>
                                                            <p class="small mb-2" data-gps-status data-booking-gps="<?php echo $hasBookingGps ? 'true' : 'false'; ?>">
                                                                <?php if ((int)($row['tracking_enabled'] ?? 0) === 1): ?>
                                                                    <span class="text-success fw-semibold"><i class="bi bi-broadcast-pin me-1"></i>Live GPS sharing is on</span>
                                                                <?php elseif ($hasLiveGps): ?>
                                                                    <span class="text-muted">GPS sharing is off; showing last shared location</span>
                                                                <?php elseif ($hasBookingGps): ?>
                                                                    <span class="text-muted">Booking GPS pin</span>
                                                                <?php else: ?>
                                                                    <span class="text-muted">No GPS location received yet. Ask the customer to open My Rentals, tap “Share live GPS”, and keep that page open.</span>
                                                                <?php endif; ?>
                                                            </p>
                                                            <?php
                                                            $initialGpsMapUrl = '';
                                                            if ($initialGpsLatitude !== null && $initialGpsLongitude !== null) {
                                                                $initialGpsBounds = implode(',', [
                                                                    $initialGpsLongitude - 0.01,
                                                                    $initialGpsLatitude - 0.01,
                                                                    $initialGpsLongitude + 0.01,
                                                                    $initialGpsLatitude + 0.01
                                                                ]);
                                                                $initialGpsMapUrl = 'https://www.openstreetmap.org/export/embed.html?bbox=' .
                                                                    rawurlencode($initialGpsBounds) . '&layer=mapnik&marker=' .
                                                                    rawurlencode($initialGpsLatitude . ',' . $initialGpsLongitude);
                                                            }
                                                            $directionsLatitude = $hasBookingGps
                                                                ? (float)$row['customer_latitude']
                                                                : $initialGpsLatitude;
                                                            $directionsLongitude = $hasBookingGps
                                                                ? (float)$row['customer_longitude']
                                                                : $initialGpsLongitude;
                                                            $directionsUrl = $directionsLatitude === null
                                                                ? '#'
                                                                : 'https://www.google.com/maps/dir/?api=1&destination=' .
                                                                    rawurlencode($directionsLatitude . ',' . $directionsLongitude);
                                                            ?>
                                                            <div class="ratio ratio-16x9" data-gps-map-wrap<?php echo $initialGpsMapUrl === '' ? ' hidden' : ''; ?>>
                                                                <iframe
                                                                    id="rentalGpsMap<?php echo (int)$row['id']; ?>"
                                                                    title="Customer GPS location"
                                                                    data-gps-map
                                                                    data-latitude="<?php echo $initialGpsLatitude === null ? '' : htmlspecialchars((string)$initialGpsLatitude, ENT_QUOTES, 'UTF-8'); ?>"
                                                                    data-longitude="<?php echo $initialGpsLongitude === null ? '' : htmlspecialchars((string)$initialGpsLongitude, ENT_QUOTES, 'UTF-8'); ?>"
                                                                    <?php if ($initialGpsMapUrl !== ''): ?>src="<?php echo htmlspecialchars($initialGpsMapUrl, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                                                                    loading="lazy"
                                                                    referrerpolicy="no-referrer-when-downgrade"
                                                                    allowfullscreen></iframe>
                                                            </div>
                                                            <a class="btn btn-sm btn-outline-primary rounded-pill mt-2<?php echo $directionsLatitude === null ? ' d-none' : ''; ?>"
                                                               data-gps-directions
                                                               href="<?php echo htmlspecialchars($directionsUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                               target="_blank" rel="noopener">
                                                                <i class="bi bi-sign-turn-right me-1"></i>Get directions
                                                            </a>
                                                            <div class="small text-muted mt-2" data-gps-updated>
                                                                <?php if (!empty($row['tracking_updated_at'])): ?>
                                                                    Last GPS update: <?php echo htmlspecialchars($row['tracking_updated_at']); ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="rounded-4 border p-3 h-100">
                                                            <small class="text-uppercase text-muted fw-bold">Rental period</small>
                                                            <p class="fw-bold mb-1 mt-2"><?php echo date('M d, Y', strtotime($row['start_date'])); ?> - <?php echo date('M d, Y', strtotime($row['end_date'])); ?></p>
                                                            <p class="mb-0 text-muted"><?php echo ceil((strtotime($row['end_date']) - strtotime($row['start_date'])) / (60 * 60 * 24)) . ' day(s)'; ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="rounded-4 border p-3 h-100">
                                                            <small class="text-uppercase text-muted fw-bold">Payment</small>
                                                            <p class="fw-bold mb-1 mt-2 text-primary">₱<?php echo number_format($row['total_price'], 2); ?></p>
                                                            <p class="mb-1 text-muted">Booking: <?php echo ucfirst($row['status']); ?></p>
                                                            <?php
                                                            $paymentLabels = [
                                                                'cash' => 'Cash upon pickup',
                                                                'gcash' => 'GCash (legacy)',
                                                                'bank_transfer' => 'Bank transfer (legacy)',
                                                                'online' => 'E-wallet via PayMongo'
                                                            ];
                                                            $paymentMethod = $row['payment_method'] ?? 'cash';
                                                            ?>
                                                            <p class="mb-1 text-muted">Method: <?php echo htmlspecialchars($paymentLabels[$paymentMethod] ?? ucfirst($paymentMethod)); ?></p>
                                                            <p class="mb-0 text-muted">Payment: <?php
                                                                if (($row['payment_status'] ?? '') === 'cancelled') {
                                                                    echo $paymentMethod === 'online'
                                                                        ? 'Payment cancelled'
                                                                        : 'Receipt rejected';
                                                                } elseif (($row['payment_status'] ?? '') === 'completed') {
                                                                    echo 'Paid';
                                                                } elseif ($paymentMethod === 'cash') {
                                                                    echo 'Due on pickup';
                                                                } else {
                                                                    echo htmlspecialchars(ucfirst($row['payment_status'] ?? 'pending'));
                                                                }
                                                            ?></p>
                                                            <?php if ($row['status'] === 'approved' && $paymentMethod === 'cash' && ($row['payment_status'] ?? '') === 'pending'): ?>
                                                                <form method="POST" action="<?php echo BASE_URL; ?>admin/rentals.php" class="mt-3">
                                                                    <input type="hidden" name="rental_id" value="<?php echo (int)$row['id']; ?>">
                                                                    <input type="hidden" name="payment_action" value="cash_received">
                                                                    <button class="btn btn-sm btn-success rounded-pill" type="submit">Mark cash received</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php if (!empty($row['reference_number'])): ?>
                                                                <p class="mb-0 mt-1 text-muted">Reference: <?php echo htmlspecialchars($row['reference_number']); ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="rounded-4 border p-3 h-100">
                                                            <small class="text-uppercase text-muted fw-bold">Driver's license</small>
                                                            <p class="mb-2 mt-2">Number: <?php echo htmlspecialchars($row['driver_license_number'] ?: 'Not provided'); ?></p>
                                                            <?php if (!empty($row['driver_license_image'])): ?>
                                                                <a href="<?php echo BASE_URL; ?>document.php?type=license&amp;rental_id=<?php echo (int)$row['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary rounded-pill">View license</a>
                                                            <?php else: ?>
                                                                <span class="text-muted small">No image uploaded.</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($row['receipt_image'])): ?>
                                                        <div class="col-md-6">
                                                            <div class="rounded-4 border p-3 h-100">
                                                                <small class="text-uppercase text-muted fw-bold">Transfer receipt</small>
                                                                <p class="mb-2 mt-2">Customer uploaded a payment receipt.</p>
                                                                <a href="<?php echo BASE_URL; ?>document.php?type=receipt&amp;rental_id=<?php echo (int)$row['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary rounded-pill">View receipt</a>
                                                                <?php if (($row['payment_status'] ?? '') === 'pending'): ?>
                                                                    <div class="d-flex gap-2 mt-3">
                                                                        <form method="POST" action="<?php echo BASE_URL; ?>admin/rentals.php">
                                                                            <input type="hidden" name="rental_id" value="<?php echo (int)$row['id']; ?>">
                                                                            <input type="hidden" name="payment_action" value="confirm">
                                                                            <button class="btn btn-sm btn-success rounded-pill" type="submit">Confirm payment</button>
                                                                        </form>
                                                                        <form method="POST" action="<?php echo BASE_URL; ?>admin/rentals.php">
                                                                            <input type="hidden" name="rental_id" value="<?php echo (int)$row['id']; ?>">
                                                                            <input type="hidden" name="payment_action" value="reject">
                                                                            <button class="btn btn-sm btn-outline-danger rounded-pill" type="submit">Reject receipt</button>
                                                                        </form>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="col-12">
                                                        <div class="rounded-4 bg-light p-3">
                                                            <small class="text-uppercase text-muted fw-bold">Notes</small>
                                                            <p class="mb-0 mt-2 text-muted"><?php echo !empty($row['notes']) ? nl2br(htmlspecialchars($row['notes'])) : 'No notes provided.'; ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0 px-4 pb-4 pt-0">
                                                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No rental requests found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var gpsPollers = new Map();
        document.querySelectorAll('[id^="rentalDetailModal"]').forEach(function (modal) {
            modal.addEventListener('shown.bs.modal', function () {
                var map = modal.querySelector('[data-gps-map]');
                var mapWrap = modal.querySelector('[data-gps-map-wrap]');
                var rentalId = modal.id.replace('rentalDetailModal', '');
                var status = modal.querySelector('[data-gps-status]');
                var updated = modal.querySelector('[data-gps-updated]');
                var directions = modal.querySelector('[data-gps-directions]');
                if (!map || !/^\d+$/.test(rentalId) || gpsPollers.has(rentalId)) {
                    return;
                }

                function refreshGps() {
                    fetch('<?php echo BASE_URL; ?>location.php?rental_id=' + encodeURIComponent(rentalId), {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    }).then(function (response) {
                        return response.json().then(function (payload) {
                            if (!response.ok) {
                                throw new Error(payload.error || 'Could not load GPS location.');
                            }
                            return payload;
                        });
                    }).then(function (location) {
                        var isValid = location.tracking_latitude !== null &&
                            location.tracking_longitude !== null &&
                            Number.isFinite(Number(location.tracking_latitude)) &&
                            Number.isFinite(Number(location.tracking_longitude)) &&
                            Math.abs(Number(location.tracking_latitude)) <= 90 &&
                            Math.abs(Number(location.tracking_longitude)) <= 180;
                        if (isValid) {
                            var latitude = Number(location.tracking_latitude);
                            var longitude = Number(location.tracking_longitude);
                            if (map.dataset.latitude !== String(latitude) || map.dataset.longitude !== String(longitude)) {
                                map.dataset.latitude = String(latitude);
                                map.dataset.longitude = String(longitude);
                                var bounds = [
                                    longitude - 0.01,
                                    latitude - 0.01,
                                    longitude + 0.01,
                                    latitude + 0.01
                                ].join(',');
                                var mapUrl = new URL('https://www.openstreetmap.org/export/embed.html');
                                mapUrl.searchParams.set('bbox', bounds);
                                mapUrl.searchParams.set('layer', 'mapnik');
                                mapUrl.searchParams.set('marker', latitude + ',' + longitude);
                                map.src = mapUrl.toString();
                            }
                            if (mapWrap) {
                                mapWrap.hidden = false;
                            }
                            if (directions) {
                                directions.href = 'https://www.google.com/maps/dir/?api=1&destination=' +
                                    encodeURIComponent(latitude + ',' + longitude);
                                directions.classList.remove('d-none');
                            }
                        } else if (status && status.dataset.bookingGps !== 'true') {
                            if (mapWrap) {
                                mapWrap.hidden = true;
                            }
                            if (directions) {
                                directions.classList.add('d-none');
                            }
                        }
                        if (status) {
                            status.innerHTML = Number(location.tracking_enabled) === 1
                                ? '<span class="text-success fw-semibold"><i class="bi bi-broadcast-pin me-1"></i>Live GPS sharing is on</span>'
                                : (isValid
                                    ? '<span class="text-muted">GPS sharing is off; showing last shared location</span>'
                                    : (status.dataset.bookingGps === 'true'
                                        ? '<span class="text-muted">Booking GPS pin</span>'
                                        : '<span class="text-muted">No GPS location received yet. Ask the customer to open My Rentals, tap “Share live GPS”, and keep that page open.</span>'));
                        }
                        if (updated) {
                            updated.textContent = location.tracking_updated_at
                                ? 'Last GPS update: ' + location.tracking_updated_at
                                : '';
                        }
                    }).catch(function (error) {
                        if (status) {
                            status.textContent = error.message;
                        }
                    });
                }

                refreshGps();
                gpsPollers.set(rentalId, window.setInterval(refreshGps, 10000));
            });
            modal.addEventListener('hidden.bs.modal', function () {
                var rentalId = modal.id.replace('rentalDetailModal', '');
                if (gpsPollers.has(rentalId)) {
                    window.clearInterval(gpsPollers.get(rentalId));
                    gpsPollers.delete(rentalId);
                }
            });
        });

        document.querySelectorAll('[data-rental-confirm="true"]').forEach(function (button) {
            button.addEventListener('click', function () {
                const form = this.closest('form');
                const action = this.dataset.action;
                const customer = this.dataset.customer || 'this customer';
                const actionText = action === 'approved' ? 'approve' : 'reject';
                const actionLabel = action === 'approved' ? 'Approve' : 'Reject';

                Swal.fire({
                    title: 'Are you sure?',
                    text: 'You are about to ' + actionText + ' the rental request for ' + customer + '.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, ' + actionLabel,
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: action === 'approved' ? '#198754' : '#dc3545',
                    reverseButtons: true
                }).then(function (result) {
                    if (result.isConfirmed && form) {
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'action';
                        hiddenInput.value = action;
                        form.appendChild(hiddenInput);
                        form.submit();
                    }
                });
            });
        });
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
