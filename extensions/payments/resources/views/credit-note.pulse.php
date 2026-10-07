@extends('payments::partials.invoice-layout')

{{-- ============================================================
     Credit Note Template: EU-compliant
     ============================================================

     Expected variables:
       $creditNote : object{creditNoteNumber: string, status: string, issuedAt: DateTimeImmutable, referenceInvoiceNumber: string, reason: string, subtotal: Money, tax: Money, total: Money}
       $seller     : Pulsar\Extension\Payments\Domain\SellerProfile (auto-resolved)
       $buyer      : array{name: string, address: string, vatNumber?: string, email?: string}
       $lineItems  : list<array{description: string, qty: int, unitPrice: int, taxRate: string, taxAmount: int, lineTotal: int}>
       $taxGroups  : list<array{category: string, taxableAmount: int, rate: string, taxAmount: int}>
       $currency   : Pulsar\Extension\Payments\Domain\Currency
       $locale     : string
       $statusBadgeClass : string (draft, issued, applied)
     ============================================================ --}}

<?php
    $e = htmlspecialchars(...);
$fmt = static fn(int $amount, \Pulsar\Extension\Payments\Domain\Currency $cur): string
    => $cur->symbol() . ' ' . number_format($amount / (10 ** $cur->minorDigits()), $cur->minorDigits(), '.', ',');
?>

@section('doc-title')
    @t('credit_note.title') {{ $e($creditNote->creditNoteNumber, ENT_QUOTES, 'UTF-8') }}
@endsection

@section('doc-aria-label')
    @t('credit_note.title') {{ $e($creditNote->creditNoteNumber, ENT_QUOTES, 'UTF-8') }}
@endsection

@section('doc-type-label')
    @t('credit_note.title')
@endsection

@section('doc-number')
    {{ $e($creditNote->creditNoteNumber, ENT_QUOTES, 'UTF-8') }}
@endsection

@section('doc-status-badge')
    <span class="inv-status inv-status--{{ $statusBadgeClass }}">
        {{ $e(ucfirst($creditNote->status), ENT_QUOTES, 'UTF-8') }}
    </span>
@endsection

@section('doc-info-items')
    <div class="inv-info__item">
        <span class="inv-info__label">@t('invoice.date')</span>
        <span class="inv-info__value">{{ $creditNote->issuedAt->format('d/m/Y') }}</span>
    </div>
    <div class="inv-info__item">
        <span class="inv-info__label">@t('credit_note.number')</span>
        <span class="inv-info__value">{{ $e($creditNote->creditNoteNumber, ENT_QUOTES, 'UTF-8') }}</span>
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

@section('reference-section')
    <div class="inv-reference" role="note">
        <div class="inv-reference__item">
            <span class="inv-reference__label">@t('credit_note.reference')</span>
            <span class="inv-reference__value">{{ $e($creditNote->referenceInvoiceNumber, ENT_QUOTES, 'UTF-8') }}</span>
        </div>
        <div class="inv-reference__item">
            <span class="inv-reference__label">@t('credit_note.reason')</span>
            <span class="inv-reference__value">{{ $e($creditNote->reason, ENT_QUOTES, 'UTF-8') }}</span>
        </div>
    </div>
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
        <td class="inv-totals__value">{{ $fmt($creditNote->subtotal->amount, $currency) }}</td>
    </tr>
    <tr>
        <td class="inv-totals__label">@t('invoice.tax_total')</td>
        <td class="inv-totals__value">{{ $fmt($creditNote->tax->amount, $currency) }}</td>
    </tr>
    <tr class="inv-totals__row--grand">
        <td class="inv-totals__label">@t('credit_note.total_credit')</td>
        <td class="inv-totals__value">{{ $fmt($creditNote->total->amount, $currency) }} {{ $currency->value }}</td>
    </tr>
@endsection

@section('footer-notice')
    <p class="inv-footer__notice">@t('credit_note.issued_notice', ['number' => $creditNote->referenceInvoiceNumber])</p>
    <p class="inv-footer__notice">@t('invoice.electronic_notice')</p>
@endsection

@section('footer-legal')
    @if ($seller->vatNumber !== '')
        <p class="inv-footer__legal">@t('invoice.vat_number'): {{ $e($seller->vatNumber, ENT_QUOTES, 'UTF-8') }} | {{ $e($seller->companyName, ENT_QUOTES, 'UTF-8') }}</p>
    @endif
@endsection

@section('terms-page')
    @include('payments::partials.terms-and-conditions')
@endsection
