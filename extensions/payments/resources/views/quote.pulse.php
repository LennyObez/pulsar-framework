@extends('payments::partials.invoice-layout')

{{-- ============================================================
     Quote / Estimate Template
     ============================================================

     Expected variables:
       $quote     : object{quoteNumber: string, status: string, validUntil: DateTimeImmutable, issuedAt: DateTimeImmutable, subtotal: Money, tax: Money, total: Money}
       $seller    : Pulsar\Extension\Payments\Domain\SellerProfile (auto-resolved)
       $buyer     : array{name: string, address: string, vatNumber?: string, email?: string}
       $lineItems : list<array{description: string, qty: int, unitPrice: int, taxRate: string, taxAmount: int, lineTotal: int}>
       $taxGroups : list<array{category: string, taxableAmount: int, rate: string, taxAmount: int}>
       $currency  : Pulsar\Extension\Payments\Domain\Currency
       $locale    : string
       $statusBadgeClass : string (draft, sent, accepted, rejected, expired)
       $acceptUrl : string (URL for quote acceptance)
       $rejectUrl : string (URL for quote rejection)
     ============================================================ --}}

<?php
    $e = htmlspecialchars(...);
$fmt = static fn(int $amount, \Pulsar\Extension\Payments\Domain\Currency $cur): string
    => $cur->symbol() . ' ' . number_format($amount / (10 ** $cur->minorDigits()), $cur->minorDigits(), '.', ',');
?>

@section('doc-title')
    @t('quote.title') {{ $e($quote->quoteNumber, ENT_QUOTES, 'UTF-8') }}
@endsection

@section('doc-aria-label')
    @t('quote.title') {{ $e($quote->quoteNumber, ENT_QUOTES, 'UTF-8') }}
@endsection

@section('doc-type-label')
    @t('quote.title')
@endsection

@section('doc-number')
    {{ $e($quote->quoteNumber, ENT_QUOTES, 'UTF-8') }}
@endsection

@section('doc-status-badge')
    <span class="inv-status inv-status--{{ $statusBadgeClass }}">
        {{ $e(ucfirst($quote->status), ENT_QUOTES, 'UTF-8') }}
    </span>
@endsection

@section('doc-info-items')
    <div class="inv-info__item">
        <span class="inv-info__label">@t('invoice.date')</span>
        <span class="inv-info__value">{{ $quote->issuedAt->format('d/m/Y') }}</span>
    </div>
    <div class="inv-info__item">
        <span class="inv-info__label">@t('quote.valid_until')</span>
        <span class="inv-info__value">{{ $quote->validUntil->format('d/m/Y') }}</span>
    </div>
    <div class="inv-info__item">
        <span class="inv-info__label">@t('quote.number')</span>
        <span class="inv-info__value">{{ $e($quote->quoteNumber, ENT_QUOTES, 'UTF-8') }}</span>
    </div>
@endsection

@section('buyer-details')
    <p class="inv-party__name">{{ $e($buyer['name'] ?? '', ENT_QUOTES, 'UTF-8') }}</p>
    @if (!empty($buyer['address']))
        @foreach (explode("\n", $buyer['address']) as $line)
            @if ($line !== '')
                <p class="inv-party__detail">{{ $e($line, ENT_QUOTES, 'UTF-8') }}</p>
            @endif
        @endforeach
    @endif
    @if (!empty($buyer['vatNumber']))
        <p class="inv-party__detail inv-party__detail--mono">@t('invoice.vat_number'): {{ $e($buyer['vatNumber'], ENT_QUOTES, 'UTF-8') }}</p>
    @endif
    @if (!empty($buyer['email']))
        <p class="inv-party__detail">{{ $e($buyer['email'], ENT_QUOTES, 'UTF-8') }}</p>
    @endif
@endsection

@section('line-item-rows')
    @foreach ($lineItems as $i => $item)
        <tr>
            <td class="inv-row-num">{{ $i + 1 }}</td>
            <td>{{ $e($item['description'], ENT_QUOTES, 'UTF-8') }}</td>
            <td class="inv-num">{{ $item['qty'] }}</td>
            <td class="inv-num">{{ $fmt($item['unitPrice'], $currency) }}</td>
            <td class="inv-num">{{ $e($item['taxRate'], ENT_QUOTES, 'UTF-8') }}</td>
            <td class="inv-num">{{ $fmt($item['taxAmount'], $currency) }}</td>
            <td class="inv-num">{{ $fmt($item['lineTotal'], $currency) }}</td>
        </tr>
    @endforeach
@endsection

@section('tax-summary-content')
    @if (!empty($taxGroups))
        <table class="inv-tax-summary" aria-label="@t('invoice.tax_summary')">
            <thead>
                <tr>
                    <th scope="col">@t('invoice.tax_category')</th>
                    <th scope="col" class="inv-num">@t('invoice.taxable_amount')</th>
                    <th scope="col" class="inv-num">@t('invoice.col_tax_rate')</th>
                    <th scope="col" class="inv-num">@t('invoice.col_tax_amount')</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($taxGroups as $group)
                    <tr>
                        <td>{{ $e($group['category'], ENT_QUOTES, 'UTF-8') }}</td>
                        <td class="inv-num">{{ $fmt($group['taxableAmount'], $currency) }}</td>
                        <td class="inv-num">{{ $e($group['rate'], ENT_QUOTES, 'UTF-8') }}</td>
                        <td class="inv-num">{{ $fmt($group['taxAmount'], $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection

@section('totals-rows')
    <tr>
        <td class="inv-totals__label">@t('invoice.subtotal')</td>
        <td class="inv-totals__value">{{ $fmt($quote->subtotal->amount, $currency) }}</td>
    </tr>
    <tr>
        <td class="inv-totals__label">@t('invoice.tax_total')</td>
        <td class="inv-totals__value">{{ $fmt($quote->tax->amount, $currency) }}</td>
    </tr>
    <tr class="inv-totals__row--grand">
        <td class="inv-totals__label">@t('invoice.grand_total')</td>
        <td class="inv-totals__value">{{ $fmt($quote->total->amount, $currency) }} {{ $currency->value }}</td>
    </tr>
@endsection

@section('quote-section')
    <div class="inv-payment">
        <h3 class="inv-payment__heading">@t('quote.validity_heading')</h3>
        <p class="inv-payment__field-value">@t('quote.validity_notice', ['date' => $quote->validUntil->format('d/m/Y')])</p>
    </div>
@endsection

@section('footer-notice')
    <p class="inv-footer__notice">@t('quote.expiry_notice')</p>
@endsection

@section('footer-legal')
    @if ($seller->vatNumber !== '')
        <p class="inv-footer__legal">@t('invoice.vat_number'): {{ $e($seller->vatNumber, ENT_QUOTES, 'UTF-8') }} | {{ $e($seller->companyName, ENT_QUOTES, 'UTF-8') }}</p>
    @endif
@endsection

@section('extra-actions')
    @if ($quote->status === 'sent')
        <a href="{{ $e($acceptUrl ?? '#', ENT_QUOTES, 'UTF-8') }}" class="pui-btn pui-btn--success">
            @t('quote.accept')
        </a>
        <a href="{{ $e($rejectUrl ?? '#', ENT_QUOTES, 'UTF-8') }}" class="pui-btn pui-btn--danger">
            @t('quote.reject')
        </a>
    @endif
@endsection

@section('terms-page')
    @include('payments::partials.terms-and-conditions')
@endsection
