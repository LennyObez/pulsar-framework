<?php
/**
 * Checkout success page template.
 *
 * @var string $locale
 * @var string $siteName
 * @var string $orderNumber
 * @var string $orderEmail
 * @var int $orderTotal
 * @var string $currency
 * @var string $invoiceUrl
 * @var list<array{url: string, file_name: string}> $digitalDownloads
 * @var string $shopUrl
 */
?>
<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed | {{ $siteName ?? 'Store' }}</title>
</head>
<body>
<main class="cms-checkout-success">
    <div class="cms-checkout-success__container">
        <div class="cms-checkout-success__icon" aria-hidden="true">&#10003;</div>
        <h1 class="cms-checkout-success__title">Order Confirmed</h1>

        <div class="cms-checkout-success__details">
            <dl class="cms-checkout-success__summary">
                <dt>Order Number</dt>
                <dd><strong><?php echo htmlspecialchars($orderNumber ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></dd>

                @if (!empty($orderEmail))
                    <dt>Confirmation Email</dt>
                    <dd><?php echo htmlspecialchars($orderEmail, ENT_QUOTES, 'UTF-8'); ?></dd>
                @endif

                @if (($orderTotal ?? 0) > 0)
                    <dt>Total</dt>
                    <dd><?php echo htmlspecialchars(number_format($orderTotal / 100, 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(strtoupper($currency ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?></dd>
                @endif
            </dl>
        </div>

        {{-- Invoice Download --}}
        @if (!empty($invoiceUrl))
            <section class="cms-checkout-success__section">
                <h2 class="cms-checkout-success__section-title">Invoice</h2>
                <a href="<?php echo htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8'); ?>" class="cms-checkout-success__btn cms-checkout-success__btn--outline">
                    Download Invoice
                </a>
            </section>
        @endif

        {{-- Digital Downloads --}}
        @if (!empty($digitalDownloads))
            <section class="cms-checkout-success__section">
                <h2 class="cms-checkout-success__section-title">Your Downloads</h2>
                <ul class="cms-checkout-success__downloads">
                    @foreach ($digitalDownloads as $download)
                        <?php /** @var array{url: string, file_name: string} $download */ ?>
                        <li class="cms-checkout-success__download-item">
                            <a href="<?php echo htmlspecialchars($download['url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="cms-checkout-success__download-link">
                                <?php echo htmlspecialchars($download['file_name'] ?? 'Download', ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <p class="cms-checkout-success__hint">Download links are valid for a limited time and number of uses.</p>
            </section>
        @endif

        <div class="cms-checkout-success__actions">
            <a href="<?php echo htmlspecialchars($shopUrl ?? '/', ENT_QUOTES, 'UTF-8'); ?>" class="cms-checkout-success__btn">Continue Shopping</a>
        </div>
    </div>
</main>
</body>
</html>
