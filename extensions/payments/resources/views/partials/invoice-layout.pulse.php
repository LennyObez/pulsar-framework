<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('doc-title')</title>
    <link rel="stylesheet" href="/ui/css/tokens.css">
    <link rel="stylesheet" href="/ui/css/base.css">
    <link rel="stylesheet" href="/ui/css/components/badge.css">
    <link rel="stylesheet" href="/ui/css/components/button.css">
    <link rel="stylesheet" href="/ui/css/components/table.css">
    <link rel="stylesheet" href="/payments/css/invoice.css">
    <style>
        /* Embedded fallback for headless PDF renderers without access to external CSS */
        @yield('embedded-styles')
    </style>
</head>
<body>
    <a href="#main-content" class="pui-skip-link">@t('a11y.skip_to_content')</a>

    <article class="inv-document" id="main-content" role="document" aria-label="@yield('doc-aria-label')">

        {{-- ============================================================
             HEADER: Logo / Company Name + Document Type Badge
             ============================================================ --}}
        @section('header')
        <header class="inv-header">
            <div class="inv-header__brand">
                @if ($seller->logoPath)
                    <img class="inv-header__logo" src="{{ htmlspecialchars($seller->logoPath, ENT_QUOTES, 'UTF-8') }}" alt="{{ htmlspecialchars($seller->companyName, ENT_QUOTES, 'UTF-8') }}">
                @endif
                <h2 class="inv-header__company-name">{{ htmlspecialchars($seller->companyName, ENT_QUOTES, 'UTF-8') }}</h2>
            </div>
            <div class="inv-header__meta">
                <h1 class="inv-header__doc-type">@yield('doc-type-label')</h1>
                <p class="inv-header__doc-number">@yield('doc-number')</p>
                @yield('doc-status-badge')
            </div>
        </header>
        @show

        {{-- ============================================================
             DOCUMENT INFO: Dates, references
             ============================================================ --}}
        @section('doc-info')
        <div class="inv-info" role="group" aria-label="@yield('doc-type-label')">
            @yield('doc-info-items')
        </div>
        @show

        {{-- ============================================================
             PARTIES: Seller (From) / Buyer (Bill To)
             ============================================================ --}}
        @section('parties')
        <div class="inv-parties">
            {{-- Seller --}}
            <section class="inv-party" aria-label="@t('invoice.seller')">
                <h3 class="inv-party__heading">@t('invoice.seller')</h3>
                <p class="inv-party__name">{{ htmlspecialchars($seller->companyName, ENT_QUOTES, 'UTF-8') }}</p>
                @if ($seller->legalForm !== '')
                    <p class="inv-party__detail">{{ htmlspecialchars($seller->legalForm, ENT_QUOTES, 'UTF-8') }}</p>
                @endif
                @foreach (explode("\n", $seller->formattedAddress()) as $line)
                    @if ($line !== '')
                        <p class="inv-party__detail">{{ htmlspecialchars($line, ENT_QUOTES, 'UTF-8') }}</p>
                    @endif
                @endforeach
                @if ($seller->vatNumber !== '')
                    <p class="inv-party__detail inv-party__detail--mono">@t('invoice.vat_number'): {{ htmlspecialchars($seller->vatNumber, ENT_QUOTES, 'UTF-8') }}</p>
                @endif
                @if ($seller->registrationNumber !== '')
                    <p class="inv-party__detail inv-party__detail--mono">@t('invoice.reg_number'): {{ htmlspecialchars($seller->registrationNumber, ENT_QUOTES, 'UTF-8') }}</p>
                @endif
                @if ($seller->phone !== '')
                    <p class="inv-party__detail">{{ htmlspecialchars($seller->phone, ENT_QUOTES, 'UTF-8') }}</p>
                @endif
                @if ($seller->email !== '')
                    <p class="inv-party__detail">{{ htmlspecialchars($seller->email, ENT_QUOTES, 'UTF-8') }}</p>
                @endif
            </section>

            {{-- Buyer --}}
            <section class="inv-party" aria-label="@t('invoice.buyer')">
                <h3 class="inv-party__heading">@t('invoice.buyer')</h3>
                @yield('buyer-details')
            </section>
        </div>
        @show

        {{-- ============================================================
             LINE ITEMS TABLE
             ============================================================ --}}
        @section('line-items')
        <div class="pui-table-responsive">
            <table class="inv-items" aria-label="@t('invoice.line_items')">
                <thead>
                    <tr>
                        <th scope="col" class="inv-row-num">#</th>
                        <th scope="col">@t('invoice.col_description')</th>
                        <th scope="col" class="inv-num">@t('invoice.col_qty')</th>
                        <th scope="col" class="inv-num">@t('invoice.col_unit_price')</th>
                        <th scope="col" class="inv-num">@t('invoice.col_tax_rate')</th>
                        <th scope="col" class="inv-num">@t('invoice.col_tax_amount')</th>
                        <th scope="col" class="inv-num">@t('invoice.col_line_total')</th>
                    </tr>
                </thead>
                <tbody>
                    @yield('line-item-rows')
                </tbody>
            </table>
        </div>
        @show

        {{-- ============================================================
             TAX SUMMARY TABLE (grouped by rate)
             ============================================================ --}}
        @section('tax-summary')
            @yield('tax-summary-content')
        @show

        {{-- ============================================================
             TOTALS BLOCK
             ============================================================ --}}
        @section('totals')
        <table class="inv-totals" aria-label="@t('invoice.totals')">
            @yield('totals-rows')
        </table>
        @show

        {{-- ============================================================
             PAYMENT DETAILS (invoice only)
             ============================================================ --}}
        @yield('payment-section')

        {{-- ============================================================
             QR CODE AREA (invoice only)
             ============================================================ --}}
        @yield('qr-section')

        {{-- ============================================================
             CREDIT NOTE REFERENCE (credit note only)
             ============================================================ --}}
        @yield('reference-section')

        {{-- ============================================================
             QUOTE VALIDITY / ACTIONS (quote only)
             ============================================================ --}}
        @yield('quote-section')

        {{-- ============================================================
             FOOTER
             ============================================================ --}}
        @section('footer')
        <footer class="inv-footer">
            @yield('footer-notice')
            @yield('footer-legal')
        </footer>
        @show

    </article>

    {{-- ============================================================
         ACTIONS BAR (screen only, hidden in print)
         ============================================================ --}}
    <div class="inv-actions no-print" role="group" aria-label="@t('invoice.doc_actions')">
        <button type="button" class="pui-btn pui-btn--primary" onclick="window.print()">
            @t('invoice.download_pdf')
        </button>
        @yield('extra-actions')
    </div>

    {{-- ============================================================
         PAGE 2: TERMS AND CONDITIONS
         ============================================================ --}}
    @yield('terms-page')

</body>
</html>
