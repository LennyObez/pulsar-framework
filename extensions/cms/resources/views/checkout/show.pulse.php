<?php
/**
 * Checkout page template.
 *
 * @var string $locale
 * @var string $siteName
 * @var list<array{name: string, quantity: int, unit_price: int, currency: string}> $items
 * @var int $subtotal
 * @var int $shippingAmount
 * @var int $taxAmount
 * @var int $total
 * @var string $currency
 * @var string $csrfToken
 * @var string $email
 * @var string $appliedCoupon
 * @var array{name: string, line1: string, line2: string, city: string, postalCode: string, country: string} $billingAddress
 * @var array{name: string, line1: string, line2: string, city: string, postalCode: string, country: string} $shippingAddress
 */
?>
<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | {{ $siteName ?? 'Store' }}</title>
</head>
<body>
<main class="cms-checkout">
    <div class="cms-checkout__container">
        <h1 class="cms-checkout__title">Checkout</h1>

        <div class="cms-checkout__layout">
            {{-- Order Summary --}}
            <aside class="cms-checkout__summary">
                <h2 class="cms-checkout__section-title">Order Summary</h2>

                <ul class="cms-checkout__items">
                    @foreach ($items ?? [] as $item)
                        <?php /** @var array{name: string, quantity: int, unit_price: int, currency: string} $item */ ?>
                        <li class="cms-checkout__item">
                            <div class="cms-checkout__item-details">
                                <span class="cms-checkout__item-name"><?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="cms-checkout__item-qty">Qty: <?php echo htmlspecialchars((string) $item['quantity'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <span class="cms-checkout__item-price">
                                <?php echo htmlspecialchars(number_format($item['unit_price'] / 100, 2), ENT_QUOTES, 'UTF-8'); ?>
                                <?php echo htmlspecialchars(strtoupper($item['currency']), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="cms-checkout__item-subtotal">
                                <?php echo htmlspecialchars(number_format(($item['unit_price'] * $item['quantity']) / 100, 2), ENT_QUOTES, 'UTF-8'); ?>
                                <?php echo htmlspecialchars(strtoupper($item['currency']), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <dl class="cms-checkout__totals">
                    <dt>Subtotal</dt>
                    <dd><?php echo htmlspecialchars(number_format(($subtotal ?? 0) / 100, 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(strtoupper($currency ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?></dd>

                    @if (($shippingAmount ?? 0) > 0)
                        <dt>Shipping</dt>
                        <dd><?php echo htmlspecialchars(number_format($shippingAmount / 100, 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(strtoupper($currency ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?></dd>
                    @endif

                    @if (($taxAmount ?? 0) > 0)
                        <dt>Tax</dt>
                        <dd><?php echo htmlspecialchars(number_format($taxAmount / 100, 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(strtoupper($currency ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?></dd>
                    @endif

                    <dt class="cms-checkout__total-label">Total</dt>
                    <dd class="cms-checkout__total-value">
                        <strong><?php echo htmlspecialchars(number_format(($total ?? 0) / 100, 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(strtoupper($currency ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </dd>
                </dl>

                {{-- Coupon Code --}}
                <form method="POST" action="/checkout/apply-coupon" class="cms-checkout__coupon-form">
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="cms-checkout__coupon-group">
                        <label for="coupon-code" class="cms-checkout__coupon-label">Coupon Code</label>
                        <div class="cms-checkout__coupon-input-group">
                            <input type="text"
                                   id="coupon-code"
                                   name="coupon_code"
                                   class="cms-checkout__coupon-input"
                                   placeholder="Enter coupon code"
                                   maxlength="50"
                                   value="<?php echo htmlspecialchars($appliedCoupon ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="cms-checkout__coupon-btn">Apply</button>
                        </div>
                    </div>
                </form>
            </aside>

            {{-- Checkout Form --}}
            <form method="POST" action="/checkout/process" class="cms-checkout__form">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                {{-- Email --}}
                <fieldset class="cms-checkout__fieldset">
                    <legend class="cms-checkout__fieldset-title">Contact</legend>
                    <div class="cms-checkout__field">
                        <label for="checkout-email" class="cms-checkout__label">Email <span aria-label="required">*</span></label>
                        <input type="email"
                               id="checkout-email"
                               name="email"
                               class="cms-checkout__input"
                               required
                               autocomplete="email"
                               value="<?php echo htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </fieldset>

                {{-- Billing Address --}}
                <fieldset class="cms-checkout__fieldset">
                    <legend class="cms-checkout__fieldset-title">Billing Address</legend>

                    <div class="cms-checkout__field">
                        <label for="billing-name" class="cms-checkout__label">Full Name <span aria-label="required">*</span></label>
                        <input type="text" id="billing-name" name="billing_address[name]" class="cms-checkout__input" required autocomplete="billing name" value="<?php echo htmlspecialchars($billingAddress['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="cms-checkout__field">
                        <label for="billing-line1" class="cms-checkout__label">Address Line 1 <span aria-label="required">*</span></label>
                        <input type="text" id="billing-line1" name="billing_address[line1]" class="cms-checkout__input" required autocomplete="billing address-line1" value="<?php echo htmlspecialchars($billingAddress['line1'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="cms-checkout__field">
                        <label for="billing-line2" class="cms-checkout__label">Address Line 2</label>
                        <input type="text" id="billing-line2" name="billing_address[line2]" class="cms-checkout__input" autocomplete="billing address-line2" value="<?php echo htmlspecialchars($billingAddress['line2'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="cms-checkout__field-row">
                        <div class="cms-checkout__field">
                            <label for="billing-city" class="cms-checkout__label">City <span aria-label="required">*</span></label>
                            <input type="text" id="billing-city" name="billing_address[city]" class="cms-checkout__input" required autocomplete="billing address-level2" value="<?php echo htmlspecialchars($billingAddress['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="cms-checkout__field">
                            <label for="billing-postal" class="cms-checkout__label">Postal Code <span aria-label="required">*</span></label>
                            <input type="text" id="billing-postal" name="billing_address[postalCode]" class="cms-checkout__input" required autocomplete="billing postal-code" value="<?php echo htmlspecialchars($billingAddress['postalCode'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                    <div class="cms-checkout__field">
                        <label for="billing-country" class="cms-checkout__label">Country <span aria-label="required">*</span></label>
                        <input type="text" id="billing-country" name="billing_address[country]" class="cms-checkout__input" required autocomplete="billing country" maxlength="2" placeholder="US" value="<?php echo htmlspecialchars($billingAddress['country'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </fieldset>

                {{-- Shipping Address --}}
                <fieldset class="cms-checkout__fieldset">
                    <legend class="cms-checkout__fieldset-title">Shipping Address</legend>

                    <div class="cms-checkout__field">
                        <label class="cms-checkout__label">
                            <input type="checkbox"
                                   name="shipping_same_as_billing"
                                   value="1"
                                   class="cms-checkout__checkbox"
                                   checked
                                   data-cms-same-as-billing>
                            Same as billing address
                        </label>
                    </div>

                    <div data-cms-shipping-fields @if (!isset($shippingAddress)) hidden @endif>
                        <div class="cms-checkout__field">
                            <label for="shipping-name" class="cms-checkout__label">Full Name</label>
                            <input type="text" id="shipping-name" name="shipping_address[name]" class="cms-checkout__input" autocomplete="shipping name" value="<?php echo htmlspecialchars($shippingAddress['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="cms-checkout__field">
                            <label for="shipping-line1" class="cms-checkout__label">Address Line 1</label>
                            <input type="text" id="shipping-line1" name="shipping_address[line1]" class="cms-checkout__input" autocomplete="shipping address-line1" value="<?php echo htmlspecialchars($shippingAddress['line1'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="cms-checkout__field">
                            <label for="shipping-line2" class="cms-checkout__label">Address Line 2</label>
                            <input type="text" id="shipping-line2" name="shipping_address[line2]" class="cms-checkout__input" autocomplete="shipping address-line2" value="<?php echo htmlspecialchars($shippingAddress['line2'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="cms-checkout__field-row">
                            <div class="cms-checkout__field">
                                <label for="shipping-city" class="cms-checkout__label">City</label>
                                <input type="text" id="shipping-city" name="shipping_address[city]" class="cms-checkout__input" autocomplete="shipping address-level2" value="<?php echo htmlspecialchars($shippingAddress['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="cms-checkout__field">
                                <label for="shipping-postal" class="cms-checkout__label">Postal Code</label>
                                <input type="text" id="shipping-postal" name="shipping_address[postalCode]" class="cms-checkout__input" autocomplete="shipping postal-code" value="<?php echo htmlspecialchars($shippingAddress['postalCode'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="cms-checkout__field">
                            <label for="shipping-country" class="cms-checkout__label">Country</label>
                            <input type="text" id="shipping-country" name="shipping_address[country]" class="cms-checkout__input" autocomplete="shipping country" maxlength="2" placeholder="US" value="<?php echo htmlspecialchars($shippingAddress['country'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </fieldset>

                {{-- Payment Section --}}
                <fieldset class="cms-checkout__fieldset">
                    <legend class="cms-checkout__fieldset-title">Payment</legend>
                    <div class="cms-checkout__payment-placeholder" data-cms-payment-section>
                        <p>Payment processing is handled by your configured payment provider.</p>
                    </div>
                </fieldset>

                <button type="submit" class="cms-checkout__submit">Place Order</button>
            </form>
        </div>
    </div>
</main>

<script>
(function () {
    var checkbox = document.querySelector('[data-cms-same-as-billing]');
    var shippingFields = document.querySelector('[data-cms-shipping-fields]');

    if (checkbox && shippingFields) {
        checkbox.addEventListener('change', function () {
            shippingFields.hidden = this.checked;
        });
    }
})();
</script>
</body>
</html>
