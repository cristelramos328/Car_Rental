<?php
global $mysqli;
$mysqli = $mysqli ?? ($GLOBALS['mysqli'] ?? null);

if (!$mysqli) {
    http_response_code(500);
    echo '<div class="alert alert-danger mb-0 rounded-3">Database connection is unavailable.</div>';
    exit;
}

if (!empty($_GET['modal']) && $_GET['modal'] === '1') {
    $car_id = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
    $carModel = new \App\Models\Car($mysqli);
    $car = $carModel->getById($car_id);

    if (!$car) {
        http_response_code(404);
        echo '<div class="alert alert-danger mb-0 rounded-3">Vehicle not found.</div>';
        exit;
    }

    $car_imgs = getCarImagesList($car['image']);
    $car_id = (string)$car['id'];
    $today = date('Y-m-d');
    $active_count = $carModel->getActiveBookingsCount($car_id, $today);
    $avail_stock = ((int)$active_count === 0 && ($car['status'] ?? 'available') === 'available') ? 1 : 0;
    $is_fully_rented = ($avail_stock === 0 || ($car['status'] ?? 'available') === 'rented');

    echo '<div class="modal-content border-0 rounded-4 overflow-hidden shadow-lg detail-modal-shell">';
    echo '<div class="modal-header border-0 px-4 pt-4 pb-0 justify-content-between align-items-center">';
    echo '<div class="d-flex align-items-center gap-2">';
    echo '<span class="badge detail-pill">' . htmlspecialchars(ucfirst($car['type'] ?? 'vehicle')) . '</span>';
    echo '</div>';
    echo '<button type="button" class="btn btn-link text-dark p-0 modal-close-btn" data-bs-dismiss="modal" aria-label="Close"><i class="bi bi-x-lg"></i></button>';
    echo '</div>';
    echo '<div class="modal-body p-0">';

    echo '<div id="vehicleModalCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="3000" data-bs-wrap="true">';
    if (count($car_imgs) > 1) {
        echo '<div class="carousel-indicators">';
        foreach ($car_imgs as $idx => $img) {
            echo '<button type="button" data-bs-target="#vehicleModalCarousel" data-bs-slide-to="' . $idx . '" class="' . ($idx === 0 ? 'active' : '') . '" aria-label="Slide ' . ($idx + 1) . '"></button>';
        }
        echo '</div>';
    }
    echo '<div class="carousel-inner">';
    foreach ($car_imgs as $idx => $img) {
        echo '<div class="carousel-item ' . ($idx === 0 ? 'active' : '') . '">';
        echo '<img src="' . BASE_URL . 'uploads/' . htmlspecialchars($img) . '" class="d-block w-100" style="height: 270px; object-fit: cover;" alt="' . htmlspecialchars($car['model']) . '">';
        echo '</div>';
    }
    echo '</div>';
    if (count($car_imgs) > 1) {
        echo '<button class="carousel-control-prev" type="button" data-bs-target="#vehicleModalCarousel" data-bs-slide="prev"><span class="carousel-control-prev-icon" aria-hidden="true"></span></button>';
        echo '<button class="carousel-control-next" type="button" data-bs-target="#vehicleModalCarousel" data-bs-slide="next"><span class="carousel-control-next-icon" aria-hidden="true"></span></button>';
    }
    echo '</div>';

    echo '<div class="p-4 pt-3">';
    echo '<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">';
    echo '<div>';
    echo '<h3 class="fw-bolder mb-1 detail-title">' . htmlspecialchars($car['model']) . '</h3>';
    echo '<div class="text-muted small detail-subtitle">Year ' . htmlspecialchars($car['year'] ?? 'N/A') . ' • ' . htmlspecialchars($car['brand'] ?? 'Premium') . '</div>';
    echo '</div>';
    echo '<div class="text-end">';
    echo '<div class="small text-muted">Daily rate</div>';
    echo '<div class="fw-bold text-primary detail-price">₱' . number_format((float)($car['price_per_day'] ?? 0), 2) . '</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="d-flex align-items-center gap-2 flex-wrap mb-4">';
    if ($is_fully_rented) {
        echo '<span class="detail-badge unavailable">Unavailable</span>';
    } else {
        echo '<span class="detail-badge available">Available now</span>';
    }
    echo '<span class="detail-badge neutral">' . ($avail_stock > 0 ? $avail_stock . ' unit(s) available' : 'No units available') . '</span>';
    echo '</div>';

    echo '<div class="detail-description">';
    echo '<p class="text-muted mb-0 lh-lg">' . nl2br(htmlspecialchars($car['description'] ?? 'No description provided yet.')) . '</p>';
    echo '</div>';

    echo '<div class="row g-3 mt-1 mb-4">';
    echo '<div class="col-6"><div class="detail-info-card"><div class="detail-meta">Owner</div><div class="detail-value">' . htmlspecialchars($car['owner_name'] ?? 'System Administrator') . '</div></div></div>';
    echo '<div class="col-6"><div class="detail-info-card"><div class="detail-meta">Vehicle type</div><div class="detail-value text-capitalize">' . htmlspecialchars($car['type'] ?? 'Vehicle') . '</div></div></div>';
    echo '<div class="col-6"><div class="detail-info-card"><div class="detail-meta">Status</div><div class="detail-value ' . ($is_fully_rented ? 'text-danger' : 'text-success') . '">' . ($is_fully_rented ? 'Booked / Unavailable' : 'Ready to rent') . '</div></div></div>';
    echo '</div>';

    echo '<div class="d-grid">';
    $bookUrl = isset($_SESSION['user_id'])
        ? BASE_URL . 'car_details.php?id=' . rawurlencode((string)$car['id'])
        : BASE_URL . 'login.php';
    echo '<a href="' . htmlspecialchars($bookUrl, ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary btn-lg rounded-3 fw-bold detail-book-btn">Book now</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container py-5">
    <a href="<?php echo BASE_URL; ?>dashboard.php" class="text-decoration-none text-muted mb-4 d-inline-block"><i class="bi bi-arrow-left me-2"></i>Back to Fleet</a>
    
    <div class="row g-5">
        <div class="col-lg-7">
            <?php 
            $car_imgs = getCarImagesList($car['image']);
            ?>
            <?php if (!empty($car_imgs)): ?>
                <div id="detailsCarCarousel" class="carousel slide shadow rounded-4 overflow-hidden mb-3" data-bs-ride="carousel" data-bs-interval="2500">
                    <?php if(count($car_imgs) > 1): ?>
                        <div class="carousel-indicators">
                            <?php foreach($car_imgs as $idx => $img): ?>
                                <button type="button" data-bs-target="#detailsCarCarousel" data-bs-slide-to="<?php echo $idx; ?>" class="<?php echo ($idx === 0) ? 'active' : ''; ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="carousel-inner">
                        <?php foreach($car_imgs as $idx => $img): ?>
                            <div class="carousel-item <?php echo ($idx === 0) ? 'active' : ''; ?>">
                                <img src="<?php echo BASE_URL . 'uploads/' . htmlspecialchars($img); ?>" class="d-block w-100" style="height: 420px; object-fit: cover;" alt="<?php echo htmlspecialchars($car['model']); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if(count($car_imgs) > 1): ?>
                        <button class="carousel-control-prev" type="button" data-bs-target="#detailsCarCarousel" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#detailsCarCarousel" data-bs-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <img src="https://via.placeholder.com/800x420?text=Premium+Fleet" class="w-100 rounded-4 shadow" style="height: 420px; object-fit: cover;" alt="Car">
            <?php endif; ?>
            
            <?php 
                $car_id = (string)$car['id'];
                $today = date('Y-m-d');
                $carModelObj = new \App\Models\Car($mysqli);
                $active_count = $carModelObj->getActiveBookingsCount($car_id, $today);
                $avail_stock = ((int)$active_count === 0 && ($car['status'] ?? 'available') === 'available') ? 1 : 0;
                $is_fully_rented = ($avail_stock <= 0 || ($car['status'] ?? 'available') === 'rented');
            ?>

            <div class="mt-4">
                <h2 class="fw-bold"><?php echo htmlspecialchars($car['model']); ?></h2>
                <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-primary px-3 py-2"><?php echo $car['year']; ?> Model</span>
                    <?php if(!empty($car['type'])): ?>
                        <span class="badge bg-light text-dark border px-3 py-2 text-capitalize">
                            <i class="bi bi-gear-wide-connected me-1 text-primary"></i>
                            <?php echo htmlspecialchars(ucfirst($car['type'])); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($is_fully_rented): ?>
                        <span class="badge bg-danger px-3 py-2">
                            <i class="bi bi-x-circle-fill me-1"></i>Fully Rented
                        </span>
                    <?php else: ?>
                        <span class="badge bg-success px-3 py-2">
                            <i class="bi bi-check-circle-fill me-1"></i><?php echo $avail_stock; ?> Unit(s) Available
                        </span>
                    <?php endif; ?>
                </div>
                <p class="mt-3 text-muted lead"><?php echo nl2br(htmlspecialchars($car['description'])); ?></p>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow border-0 p-4 h-100 bg-white rounded-4">
                <div class="border-bottom pb-3 mb-4">
                    <h3 class="fw-bold mb-1">Rental Confirmation</h3>
                    <p class="text-muted small mb-0">Complete the details below to secure your ride.</p>
                </div>

                <?php if ($is_fully_rented): ?>
                    <div class="alert alert-danger border-0 shadow-sm rounded-4 p-3 mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <strong>Fully Rented:</strong> This vehicle is currently rented out. Please check back later.
                    </div>
                <?php endif; ?>

                <div class="d-flex align-items-end mb-4">
                    <h2 class="text-primary mb-0 fw-bold display-5 me-2">₱<?php echo number_format($car['price_per_day'], 2); ?></h2>
                    <span class="text-muted mb-2">/ day</span>
                </div>

                <form action="<?php echo BASE_URL; ?>rent_process.php" method="POST" enctype="multipart/form-data" id="rentalForm" autocomplete="off">
                    <input type="hidden" name="car_id" value="<?php echo htmlspecialchars((string)$car['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label text-uppercase small text-muted fw-bold">Pick-up Date</label>
                            <input type="date" name="start_date" id="startDate" class="form-control form-control-lg bg-light border-0" required min="<?php echo date('Y-m-d'); ?>" <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-uppercase small text-muted fw-bold">Return Date</label>
                            <input type="date" name="end_date" id="endDate" class="form-control form-control-lg bg-light border-0" required min="<?php echo date('Y-m-d'); ?>" <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                        </div>
                    </div>

                    <div class="border rounded-3 p-3 mb-4">
                        <h4 class="h6 fw-bold mb-3">Customer information</h4>
                        <div class="mb-3">
                            <label for="customerName" class="form-label text-uppercase small text-muted fw-bold">Full name</label>
                            <input type="text" name="customer_name" id="customerName" class="form-control bg-light" value="<?php echo htmlspecialchars($customer['name'] ?? ($_SESSION['username'] ?? '')); ?>" placeholder="Your Name" maxlength="100" autocomplete="new-password" required <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                        </div>
                        <div class="mb-3">
                            <label for="customerPhone" class="form-label text-uppercase small text-muted fw-bold">Contact number</label>
                            <input type="tel" name="customer_phone" id="customerPhone" class="form-control bg-light" value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>" placeholder="09123456789" pattern="[0-9]{1,11}" inputmode="numeric" title="Enter up to 11 digits, numbers only." autocomplete="tel" required <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                        </div>
                        <div>
                            <label for="customerAddress" class="form-label text-uppercase small text-muted fw-bold">Address</label>
                            <textarea name="customer_address" id="customerAddress" class="form-control bg-light" rows="2" maxlength="2000" placeholder="House number, street, barangay, city" autocomplete="new-password" required <?php echo $is_fully_rented ? 'disabled' : ''; ?>><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                            <input type="hidden" name="customer_latitude" id="customerLatitude">
                            <input type="hidden" name="customer_longitude" id="customerLongitude">
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill mt-2" id="getCustomerLocation" <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                                <i class="bi bi-crosshair me-1"></i> Pin Current location
                            </button>
                            <div id="customerLocationStatus" class="form-text" aria-live="polite">Optional: add a GPS pin so the owner can find your location.</div>
                            <div id="customerLocationPreview" class="mt-3" hidden>
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <p class="small text-success fw-semibold mb-0"><i class="bi bi-geo-alt-fill me-1"></i><span id="customerCoordinates"></span></p>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-danger text-nowrap" id="clearCustomerLocation">Remove pin</button>
                                </div>
                                <div class="ratio ratio-16x9 rounded-3 overflow-hidden">
                                    <iframe id="customerLocationMap" title="Booking GPS pin preview" loading="lazy"></iframe>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="driverLicenseNumber" class="form-label text-uppercase small text-muted fw-bold">Driver's License Number</label>
                        <input type="text" name="driver_license_number" id="driverLicenseNumber" class="form-control form-control-lg bg-light border-0" maxlength="100" required <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                    </div>
                    <div class="mb-4">
                        <label for="driverLicenseImage" class="form-label text-uppercase small text-muted fw-bold">Upload Driver's License</label>
                        <input type="file" name="driver_license_image" id="driverLicenseImage" class="form-control bg-light border-0" accept="image/jpeg,image/png,image/webp" required <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                        <div class="form-text">JPEG, PNG, or WebP; maximum 5 MB. Your document is private.</div>
                    </div>

                    <div class="mb-4">
                        <label for="paymentMethod" class="form-label text-uppercase small text-muted fw-bold">Payment Method</label>
                        <select name="payment_method" id="paymentMethod" class="form-select form-select-lg bg-light border-0" required <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                            <option value="cash">Cash upon pickup</option>
                            <option value="online">E-wallet (PayMongo)</option>
                        </select>
                        <div class="form-text">Pay securely with GCash, Maya, or GrabPay after submitting your request.</div>
                    </div>

                    <!-- Computed Total Display -->
                    <div class="d-flex justify-content-between align-items-center mb-4 p-4 bg-primary bg-opacity-10 rounded-3 text-primary" id="totalBox" style="display:none !important;">
                        <div>
                            <span class="d-block small text-uppercase fw-bold opacity-75">Total Estimate</span>
                            <span class="fs-2 fw-bold" id="totalPrice">₱0.00</span>
                        </div>
                        <i class="bi bi-wallet2 fs-1 opacity-50"></i>
                    </div>

                    <!-- Terms & Conditions -->
                    <div class="mb-4">
                        <div class="p-3 border border-2 rounded-3 bg-white shadow-sm">
                            <div class="form-check mb-0">
                                <input class="form-check-input border-secondary" type="checkbox" value="" id="termsCheck" required style="transform: scale(1.1); cursor: pointer;" <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                                <label class="form-check-label small text-dark lh-sm ms-2" for="termsCheck" style="cursor: pointer;">
                                    I verify that I possess a valid driver's license and agree to the 
                                    <a href="#" class="text-primary text-decoration-none fw-bold">Terms & Conditions</a> 
                                    and <a href="#" class="text-primary text-decoration-none fw-bold">Rental Agreement</a>.
                                </label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="rentalSubmitButton" class="btn <?php echo $is_fully_rented ? 'btn-secondary disabled' : 'btn-primary'; ?> btn-lg w-100 py-3 fw-bold shadow-hover hover-float" <?php echo $is_fully_rented ? 'disabled' : ''; ?>>
                        <?php echo $is_fully_rented ? 'Fully Rented — Unavailable' : 'Confirm Rental Request'; ?>
                    </button>
                    
                    <p class="text-center text-muted small mt-3 mb-0">
                        <i class="bi bi-shield-lock-fill me-1 text-success"></i> 
                        E-wallet payments are processed by PayMongo; cash is paid upon pickup.
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const startDate = document.getElementById('startDate');
const endDate = document.getElementById('endDate');
const customerPhone = document.getElementById('customerPhone');
const pricePerDay = <?php echo json_encode((float)$car['price_per_day']); ?>;
const totalBox = document.getElementById('totalBox');
const totalPrice = document.getElementById('totalPrice');
const paymentMethod = document.getElementById('paymentMethod');
const rentalSubmitButton = document.getElementById('rentalSubmitButton');
const getCustomerLocation = document.getElementById('getCustomerLocation');
const customerLatitude = document.getElementById('customerLatitude');
const customerLongitude = document.getElementById('customerLongitude');
const customerLocationStatus = document.getElementById('customerLocationStatus');
const customerLocationPreview = document.getElementById('customerLocationPreview');
const customerCoordinates = document.getElementById('customerCoordinates');
const customerLocationMap = document.getElementById('customerLocationMap');
const clearCustomerLocation = document.getElementById('clearCustomerLocation');

function keepCustomerPhoneDigits() {
    customerPhone.value = customerPhone.value.replace(/[^0-9]/g, '').slice(0, 11);
}

customerPhone.addEventListener('input', keepCustomerPhoneDigits);
keepCustomerPhoneDigits();

clearCustomerLocation.addEventListener('click', function () {
    customerLatitude.value = '';
    customerLongitude.value = '';
    customerLocationMap.removeAttribute('src');
    customerLocationPreview.hidden = true;
    customerLocationStatus.textContent = 'GPS pin removed. Your written address will be used.';
});

getCustomerLocation.addEventListener('click', function () {
    if (!navigator.geolocation) {
        customerLocationStatus.textContent = 'GPS location is not supported by this browser.';
        return;
    }

    customerLocationStatus.textContent = 'Getting your GPS location...';
    getCustomerLocation.disabled = true;
    navigator.geolocation.getCurrentPosition(function (position) {
        const latitude = position.coords.latitude.toFixed(7);
        const longitude = position.coords.longitude.toFixed(7);
        customerLatitude.value = latitude;
        customerLongitude.value = longitude;
        customerCoordinates.textContent = latitude + ', ' + longitude +
            ' (accurate within about ' + Math.round(position.coords.accuracy) + ' m)';

        const mapUrl = new URL('https://www.openstreetmap.org/export/embed.html');
        mapUrl.searchParams.set('bbox', [
            position.coords.longitude - 0.01,
            position.coords.latitude - 0.01,
            position.coords.longitude + 0.01,
            position.coords.latitude + 0.01
        ].join(','));
        mapUrl.searchParams.set('layer', 'mapnik');
        mapUrl.searchParams.set('marker', latitude + ',' + longitude);
        customerLocationMap.src = mapUrl.toString();
        customerLocationPreview.hidden = false;
        customerLocationStatus.textContent = 'GPS pin attached to this booking.';
        getCustomerLocation.disabled = false;
    }, function (error) {
        const messages = {
            1: 'Location permission was denied. Allow GPS access in your browser and try again.',
            2: 'Your location could not be determined. Check your device GPS and try again.',
            3: 'Getting your location timed out. Please try again.'
        };
        customerLocationStatus.textContent = messages[error.code] || 'Could not get your GPS location.';
        getCustomerLocation.disabled = false;
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
});

function updatePaymentButton() {
    rentalSubmitButton.textContent = paymentMethod.value === 'online'
        ? 'Continue to e-wallet payment'
        : 'Confirm Rental Request';
}

paymentMethod.addEventListener('change', updatePaymentButton);
updatePaymentButton();

function calculateTotal() {
    if(startDate.value && endDate.value) {
        const start = new Date(startDate.value);
        const end = new Date(endDate.value);
        const diffTime = end - start; 
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
        
        if (diffDays > 0) { 
            const total = diffDays * pricePerDay;
            totalPrice.innerText = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            totalBox.style.display = 'flex';
            totalBox.style.setProperty('display', 'flex', 'important');
        } else if (diffDays === 0) {
             const total = 1 * pricePerDay;
             totalPrice.innerText = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
             totalBox.style.display = 'flex';
             totalBox.style.setProperty('display', 'flex', 'important');
        } else {
            totalBox.style.display = 'none';
            totalBox.style.setProperty('display', 'none', 'important');
        }
    }
}

startDate.addEventListener('change', calculateTotal);
endDate.addEventListener('change', calculateTotal);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
