<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="flex-grow-1 py-5">
    <div class="container">
        <article class="bg-white rounded-4 shadow-sm p-4 p-md-5">
            <h1 class="fw-bold mb-2">Privacy Policy</h1>
            <p class="text-muted mb-4">Updated November 14, 2026</p>

            <p>
                Car Rental collects and uses personal information to create accounts, verify users,
                manage vehicle rentals, process payments, and protect the service.
            </p>

            <h2 class="h4 mt-4">Information we collect</h2>
            <ul>
                <li>Account details such as your name, email address, phone number, and address.</li>
                <li>Driver's-license number and the license image you provide for a rental request.</li>
                <li>Rental details such as vehicle, dates, contact information, and any location pin you choose to provide.</li>
                <li>Transaction details such as amount, payment method, payment status, and provider references.</li>
                <li>Security information needed to protect sign-in and verification-code requests.</li>
            </ul>

            <h2 class="h4 mt-4">How we use your information</h2>
            <p>
                We use this information to operate your account, verify your identity and rental request,
                contact you about a booking, record payments, provide customer support, and help prevent
                fraud or misuse.
            </p>

            <h2 class="h4 mt-4">Driver's-license images and access</h2>
            <p>
                License images are stored in a non-public documents folder and are served through an
                authenticated page. You can access your own rental documents; authorized administrators
                can access documents needed to manage rentals. Do not upload documents belonging to
                someone else without their permission.
            </p>

            <h2 class="h4 mt-4">Service providers</h2>
            <p>
                Email delivery providers receive the information needed to deliver account and
                verification emails. If you choose e-wallet checkout, PayMongo processes the hosted
                payment session. The application stores transaction references and payment status;
                payment credentials are handled by the payment provider.
            </p>
            <p>We do not sell personal information.</p>

            <h2 class="h4 mt-4">Retention and your choices</h2>
            <p>
                We retain account, rental, and transaction information while it is needed to operate
                the service, resolve disputes, maintain security, or meet recordkeeping obligations.
                License images are associated with rental records; the current application does not
                automatically delete them on a fixed schedule. To request access, correction, or
                deletion, contact <a href="mailto:support@carrental.com">support@carrental.com</a>.
                Some records may need to be retained for operational or legal reasons.
            </p>

            <h2 class="h4 mt-4">Security and policy updates</h2>
            <p>
                We use access controls to limit access to personal documents, but no online service can
                guarantee absolute security. We may update this policy as the service changes; the
                latest version will be posted on this page.
            </p>

            <h2 class="h4 mt-4">Contact</h2>
            <p class="mb-0">
                Questions or privacy requests can be sent to
                <a href="mailto:support@carrental.com">support@carrental.com</a>.
            </p>
        </article>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
