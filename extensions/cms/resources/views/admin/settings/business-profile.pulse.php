@extends('admin.layout')

@section('title', @t('admin.business_profile.title'))

@section('cms-content')
<div class="cms-settings">
    <header class="cms-settings__header">
        <h1 class="cms-settings__title">@t('admin.business_profile.title')</h1>
        <p class="cms-settings__description">@t('admin.business_profile.description')</p>
    </header>

    <?php
        /** @var \Pulsar\Config\BusinessProfileConfig $profile */
        $profile ??= new \Pulsar\Config\BusinessProfileConfig();
    /** @var array<string, mixed> $settingsOverrides */
    $settingsOverrides ??= [];

    // Helper: resolve display value from settings overrides first, then config DTO
    $val = static function (string $field) use ($profile, $settingsOverrides): string {
        if (isset($settingsOverrides[$field]) && is_string($settingsOverrides[$field])) {
            return $settingsOverrides[$field];
        }
        $v = $profile->{$field} ?? null;
        return is_string($v) ? $v : '';
    };
    ?>

    <form method="POST" action="/admin/cms/settings/business" class="cms-settings__form" id="business-profile-form">
        @csrf

        {{-- Section: Company Identity --}}
        <fieldset class="cms-form-fieldset">
            <legend class="cms-form-fieldset__legend">
                <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                @t('admin.business_profile.section_identity')
            </legend>

            <div class="cms-form-group">
                <label for="bp-company-name" class="cms-form-group__label">
                    @t('admin.business_profile.company_name') <span class="cms-form-group__required" aria-label="required">*</span>
                </label>
                <input type="text"
                       id="bp-company-name"
                       name="profile[company_name]"
                       value="{{ $val('companyName') }}"
                       class="cms-form-group__input"
                       required
                       autocomplete="organization"
                       placeholder="Acme Corporation">
                <span class="cms-form-group__hint">@t('admin.business_profile.company_name_hint')</span>
            </div>

            <div class="cms-form-group">
                <label for="bp-trading-name" class="cms-form-group__label">
                    @t('admin.business_profile.trading_name')
                </label>
                <input type="text"
                       id="bp-trading-name"
                       name="profile[trading_name]"
                       value="{{ $val('tradingName') }}"
                       class="cms-form-group__input"
                       placeholder="Acme">
                <span class="cms-form-group__hint">@t('admin.business_profile.trading_name_hint')</span>
            </div>

            <div class="cms-form-row">
                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-legal-form" class="cms-form-group__label">
                        @t('admin.business_profile.legal_form')
                    </label>
                    <input type="text"
                           id="bp-legal-form"
                           name="profile[legal_form]"
                           value="{{ $val('legalForm') }}"
                           class="cms-form-group__input"
                           placeholder="LLC, Ltd, GmbH, SARL, BV...">
                </div>

                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-registration-number" class="cms-form-group__label">
                        @t('admin.business_profile.registration_number')
                    </label>
                    <input type="text"
                           id="bp-registration-number"
                           name="profile[registration_number]"
                           value="{{ $val('registrationNumber') }}"
                           class="cms-form-group__input">
                </div>
            </div>

            <div class="cms-form-row">
                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-vat-number" class="cms-form-group__label">
                        @t('admin.business_profile.vat_number')
                    </label>
                    <input type="text"
                           id="bp-vat-number"
                           name="profile[vat_number]"
                           value="{{ $val('vatNumber') }}"
                           class="cms-form-group__input"
                           placeholder="BE0123456789">
                    <span class="cms-form-group__hint">@t('admin.business_profile.vat_number_hint')</span>
                </div>

                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-tax-id" class="cms-form-group__label">
                        @t('admin.business_profile.tax_id')
                    </label>
                    <input type="text"
                           id="bp-tax-id"
                           name="profile[tax_id]"
                           value="{{ $val('taxId') }}"
                           class="cms-form-group__input"
                           placeholder="EIN, TFN, SIREN...">
                    <span class="cms-form-group__hint">@t('admin.business_profile.tax_id_hint')</span>
                </div>
            </div>
        </fieldset>

        {{-- Section: Address --}}
        <fieldset class="cms-form-fieldset">
            <legend class="cms-form-fieldset__legend">
                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                @t('admin.business_profile.section_address')
            </legend>

            <div class="cms-form-group">
                <label for="bp-address-line1" class="cms-form-group__label">
                    @t('admin.business_profile.address_line1')
                </label>
                <input type="text"
                       id="bp-address-line1"
                       name="profile[address_line1]"
                       value="{{ $val('addressLine1') }}"
                       class="cms-form-group__input"
                       autocomplete="address-line1"
                       placeholder="123 Main Street">
            </div>

            <div class="cms-form-group">
                <label for="bp-address-line2" class="cms-form-group__label">
                    @t('admin.business_profile.address_line2')
                </label>
                <input type="text"
                       id="bp-address-line2"
                       name="profile[address_line2]"
                       value="{{ $val('addressLine2') }}"
                       class="cms-form-group__input"
                       autocomplete="address-line2"
                       placeholder="Suite 100">
            </div>

            <div class="cms-form-row">
                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-city" class="cms-form-group__label">
                        @t('admin.business_profile.city')
                    </label>
                    <input type="text"
                           id="bp-city"
                           name="profile[city]"
                           value="{{ $val('city') }}"
                           class="cms-form-group__input"
                           autocomplete="address-level2">
                </div>

                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-postal-code" class="cms-form-group__label">
                        @t('admin.business_profile.postal_code')
                    </label>
                    <input type="text"
                           id="bp-postal-code"
                           name="profile[postal_code]"
                           value="{{ $val('postalCode') }}"
                           class="cms-form-group__input"
                           autocomplete="postal-code">
                </div>
            </div>

            <div class="cms-form-row">
                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-region" class="cms-form-group__label">
                        @t('admin.business_profile.region')
                    </label>
                    <input type="text"
                           id="bp-region"
                           name="profile[region]"
                           value="{{ $val('region') }}"
                           class="cms-form-group__input"
                           autocomplete="address-level1">
                </div>

                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-country" class="cms-form-group__label">
                        @t('admin.business_profile.country') <span class="cms-form-group__required" aria-label="required">*</span>
                    </label>
                    <input type="text"
                           id="bp-country"
                           name="profile[country]"
                           value="{{ $val('country') }}"
                           class="cms-form-group__input"
                           required
                           autocomplete="country"
                           maxlength="2"
                           pattern="[A-Z]{2}"
                           placeholder="US"
                           title="ISO 3166-1 alpha-2 country code (e.g., US, DE, FR)">
                    <span class="cms-form-group__hint">@t('admin.business_profile.country_hint')</span>
                </div>
            </div>
        </fieldset>

        {{-- Section: Contact --}}
        <fieldset class="cms-form-fieldset">
            <legend class="cms-form-fieldset__legend">
                <i class="fa-solid fa-address-book" aria-hidden="true"></i>
                @t('admin.business_profile.section_contact')
            </legend>

            <div class="cms-form-group">
                <label for="bp-phone" class="cms-form-group__label">
                    @t('admin.business_profile.phone')
                </label>
                <input type="tel"
                       id="bp-phone"
                       name="profile[phone]"
                       value="{{ $val('phone') }}"
                       class="cms-form-group__input"
                       autocomplete="tel"
                       placeholder="+1 555 123 4567">
            </div>

            <div class="cms-form-group">
                <label for="bp-email" class="cms-form-group__label">
                    @t('admin.business_profile.email')
                </label>
                <input type="email"
                       id="bp-email"
                       name="profile[email]"
                       value="{{ $val('email') }}"
                       class="cms-form-group__input"
                       autocomplete="email"
                       placeholder="contact@example.com">
            </div>

            <div class="cms-form-group">
                <label for="bp-website" class="cms-form-group__label">
                    @t('admin.business_profile.website')
                </label>
                <input type="url"
                       id="bp-website"
                       name="profile[website]"
                       value="{{ $val('website') }}"
                       class="cms-form-group__input"
                       autocomplete="url"
                       placeholder="https://example.com">
            </div>
        </fieldset>

        {{-- Section: Banking --}}
        <fieldset class="cms-form-fieldset">
            <legend class="cms-form-fieldset__legend">
                <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                @t('admin.business_profile.section_banking')
            </legend>

            <p class="cms-form-fieldset__info">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                @t('admin.business_profile.banking_info')
            </p>

            <div class="cms-form-group">
                <label for="bp-iban" class="cms-form-group__label">
                    @t('admin.business_profile.iban')
                </label>
                <input type="text"
                       id="bp-iban"
                       name="profile[iban]"
                       value="{{ $val('iban') }}"
                       class="cms-form-group__input"
                       autocomplete="off"
                       placeholder="DE89370400440532013000">
            </div>

            <div class="cms-form-row">
                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-bic" class="cms-form-group__label">
                        @t('admin.business_profile.bic')
                    </label>
                    <input type="text"
                           id="bp-bic"
                           name="profile[bic]"
                           value="{{ $val('bic') }}"
                           class="cms-form-group__input"
                           autocomplete="off"
                           placeholder="COBADEFFXXX">
                </div>

                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-bank-name" class="cms-form-group__label">
                        @t('admin.business_profile.bank_name')
                    </label>
                    <input type="text"
                           id="bp-bank-name"
                           name="profile[bank_name]"
                           value="{{ $val('bankName') }}"
                           class="cms-form-group__input">
                </div>
            </div>
        </fieldset>

        {{-- Section: Branding --}}
        <fieldset class="cms-form-fieldset">
            <legend class="cms-form-fieldset__legend">
                <i class="fa-solid fa-image" aria-hidden="true"></i>
                @t('admin.business_profile.section_branding')
            </legend>

            <div class="cms-form-group">
                <label for="bp-logo-path" class="cms-form-group__label">
                    @t('admin.business_profile.logo_path')
                </label>
                <input type="text"
                       id="bp-logo-path"
                       name="profile[logo_path]"
                       value="{{ $val('logoPath') }}"
                       class="cms-form-group__input"
                       placeholder="images/logo.svg">
                <span class="cms-form-group__hint">@t('admin.business_profile.logo_path_hint')</span>
            </div>
        </fieldset>

        {{-- Section: E-Invoicing --}}
        <fieldset class="cms-form-fieldset">
            <legend class="cms-form-fieldset__legend">
                <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
                @t('admin.business_profile.section_einvoicing')
            </legend>

            <div class="cms-form-row">
                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-peppol-id" class="cms-form-group__label">
                        @t('admin.business_profile.peppol_id')
                    </label>
                    <input type="text"
                           id="bp-peppol-id"
                           name="profile[peppol_id]"
                           value="{{ $val('peppolId') }}"
                           class="cms-form-group__input">
                    <span class="cms-form-group__hint">@t('admin.business_profile.peppol_id_hint')</span>
                </div>

                <div class="cms-form-group cms-form-group--half">
                    <label for="bp-peppol-scheme" class="cms-form-group__label">
                        @t('admin.business_profile.peppol_scheme')
                    </label>
                    <input type="text"
                           id="bp-peppol-scheme"
                           name="profile[peppol_scheme]"
                           value="{{ $val('peppolScheme') }}"
                           class="cms-form-group__input"
                           placeholder="0088">
                    <span class="cms-form-group__hint">@t('admin.business_profile.peppol_scheme_hint')</span>
                </div>
            </div>
        </fieldset>

        {{-- Change reason --}}
        <div class="cms-form-group">
            <label for="bp-reason" class="cms-form-group__label">@t('admin.business_profile.change_reason')</label>
            <input type="text"
                   id="bp-reason"
                   name="reason"
                   class="cms-form-group__input"
                   placeholder="@t('admin.business_profile.change_reason_placeholder')">
        </div>

        @can('cms.settings.manage')
            <div class="cms-form-actions">
                <button type="submit" class="cms-btn cms-btn--primary">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    @t('admin.business_profile.save')
                </button>
            </div>
        @endcan
    </form>
</div>
@endsection
