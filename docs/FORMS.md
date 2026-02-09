# Form Extension

The Form extension provides a comprehensive, security-first form building system for Pulsar applications. It includes type-safe field definitions, CSRF protection, accessible HTML rendering, file upload handling, multi-step wizard workflows, and regulated field types for compliance-critical domains.

## Installation

The Form extension ships with Pulsar. Register it in your application:

```php
use Pulsar\Extension\Form\FormExtension;

// In your application bootstrap
$app->register(new FormExtension());
```

## Quick Start

```php
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Extension\Form\Field\EmailField;
use Pulsar\Extension\Form\Renderer\HtmlFormRenderer;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\Email;

// Build the form
$form = $builder
    ->id('contact')
    ->action('/contact')
    ->method('POST')
    ->add((new TextField('name', 'Your Name'))->setRequired(true))
    ->add((new EmailField('email', 'Email Address'))->setRules([new Required(), new Email()]))
    ->add(new TextareaField('message', 'Message'))
    ->build();

// Handle submission
$form->submit($request->post());
$result = $form->validate();

if ($result->passed()) {
    $data = $form->getData();
    // Process form data...
}

// Render
echo $renderer->renderFormStart($form);
foreach ($form->getFields() as $field) {
    echo $renderer->renderField($field, $result);
}
echo $renderer->renderFormEnd();
```

## Field Types

### Text Input Fields

| Field           | Type Attribute | Key Options                                                 |
| --------------- | -------------- | ----------------------------------------------------------- |
| `TextField`     | `text`         | `minLength`, `maxLength`, `placeholder`, `pattern`          |
| `EmailField`    | `email`        | `placeholder`                                               |
| `PasswordField` | `password`     | `minLength`, `placeholder` (getValue() always returns null) |
| `TelField`      | `tel`          | `placeholder`, `pattern`                                    |
| `UrlField`      | `url`          | `placeholder`                                               |
| `TextareaField` | `textarea`     | `rows`, `cols`, `maxLength`, `placeholder`                  |

### Numeric and Date Fields

| Field           | Type Attribute   | Key Options                         |
| --------------- | ---------------- | ----------------------------------- |
| `NumberField`   | `number`         | `min`, `max`, `step`, `placeholder` |
| `DateField`     | `date`           | `min`, `max`                        |
| `DateTimeField` | `datetime-local` | `min`, `max`                        |
| `TimeField`     | `time`           | `min`, `max`, `step`                |
| `RangeField`    | `range`          | `min`, `max`, `step`                |
| `ColorField`    | `color`          | -                                   |

### Choice Fields

| Field              | Type Attribute     | Key Options                      |
| ------------------ | ------------------ | -------------------------------- |
| `SelectField`      | `select`           | `options`, `placeholder`         |
| `MultiSelectField` | `select[multiple]` | `options`                        |
| `RadioField`       | `radio`            | `options` (rendered as fieldset) |
| `CheckboxField`    | `checkbox`         | `checkedValue`                   |

### Special Fields

| Field             | Type Attribute | Key Options                               |
| ----------------- | -------------- | ----------------------------------------- |
| `HiddenField`     | `hidden`       | -                                         |
| `FileField`       | `file`         | `allowedMimeTypes`, `maxSize`, `multiple` |
| `FieldsetField`   | `fieldset`     | `children`, `legend`                      |
| `CollectionField` | collection     | `prototype`, `minEntries`, `maxEntries`   |

### Field Configuration

All fields support:

```php
$field = new TextField('username', 'Username');
$field->setRequired(true);
$field->setDisabled(true);
$field->setRules([new Required(), new MinLength(3)]);
$field->setAttributes(['class' => 'form-control', 'autofocus' => true]);
```

## CSRF Protection

CSRF protection is enabled by default and binds tokens to the session ID, form ID, and action route.

```php
// Configuration (config/form.php)
return [
    'csrf' => [
        'enabled' => true,
        'ttl' => 3600,       // Token TTL in seconds (default: 3600)
        'field_name' => '_token', // Hidden field name
    ],
];

// FormBuilder automatically handles CSRF when session is available
$form = $builder
    ->id('login')
    ->action('/login')
    ->csrf($csrfManager, '/login')
    ->build();

// Render includes hidden CSRF field
echo $renderer->renderCsrfField($form);
```

Token validation is automatic during `$form->validate()`. Expired or invalid tokens throw `CsrfException`.

## HTML Rendering & Accessibility

The `HtmlFormRenderer` produces WCAG 2.1 AA compliant markup:

```php
$renderer = new HtmlFormRenderer($rendererConfig);

// Full form
echo $renderer->renderForm($form, $validationResult);

// Or piece by piece
echo $renderer->renderFormStart($form);
echo $renderer->renderErrorSummary($form);    // Error summary with anchor links
echo $renderer->renderCsrfField($form);
foreach ($form->getFields() as $field) {
    echo $renderer->renderField($field, $validationResult);
}
echo $renderer->renderFormEnd();
```

### Accessibility Features

- `aria-required="true"` and `required` on required fields
- `aria-invalid="true"` on fields with validation errors
- `aria-describedby` linking fields to their error messages
- Error messages wrapped in `role="alert"` containers
- Error summary with `tabindex="-1"` for focus management
- Anchor links from error summary to individual fields
- Radio groups rendered as `<fieldset>` with `role="radiogroup"` and `<legend>`
- Proper `<label for="...">` associations on all fields
- `novalidate` on `<form>` to use server-side validation
- All output HTML-escaped to prevent XSS

### Renderer Configuration

```php
return [
    'renderer' => [
        'theme' => 'default',
        'error_class' => 'form-error',
        'field_class' => 'form-group',
        'label_class' => 'form-label',
        'input_class' => 'form-control',
    ],
];
```

## Validation

Forms integrate with Pulsar's validation system. Attach rules to fields, then call `validate()`:

```php
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\Email;
use Pulsar\Http\Validation\Rule\MinLength;

$nameField = new TextField('name', 'Name');
$nameField->setRules([new Required(), new MinLength(2)]);

$emailField = new EmailField('email', 'Email');
$emailField->setRules([new Required(), new Email()]);

$form = $builder->id('register')
    ->add($nameField)
    ->add($emailField)
    ->build();

$form->submit($postData);
$result = $form->validate();

if ($result->failed()) {
    $nameErrors = $result->forField('name');
    // Render errors...
}
```

## Data Binding

The `FormDataBinder` hydrates validated form data into typed DTOs:

```php
use Pulsar\Extension\Form\Binding\FormDataBinder;

class ContactDto {
    public string $name = '';
    public string $email = '';
    public string $message = '';
}

$binder = new FormDataBinder(new PropertyAccessor());

// Hydrate DTO from form data
$form->submit($postData);
$dto = $binder->hydrate($form, ContactDto::class);

// Reverse: populate form from existing DTO
$existingDto = loadContact($id);
$binder->populate($form, $existingDto);
```

The `PropertyAccessor` supports dot-notation for nested properties:

```php
$accessor->read($dto, 'address.city');     // Reads $dto->address->city
$accessor->write($dto, 'address.city', 'Paris');
```

## File Uploads

Secure file upload handling with MIME sniffing, size limits, and optional antivirus scanning:

```php
use Pulsar\Extension\Form\Upload\UploadedFileHandler;

// Configuration
return [
    'upload' => [
        'directory' => '/var/uploads',
        'max_size' => 10_485_760,          // 10 MB
        'regulated_preset' => false,       // Set to true for regulated environments
    ],
];

// Handle upload
$result = $handler->handle(
    $tmpPath,           // Temporary file path
    'document.pdf',     // Original filename
    'attachments',      // Storage subdirectory
    ['application/pdf'] // Allowed MIME types (optional)
);

echo $result->storagePath;   // /var/uploads/attachments/a1b2c3d4-...pdf
echo $result->originalName;  // document.pdf
echo $result->mimeType;      // application/pdf
echo $result->size;          // 45231
```

### Security Features

- **MIME sniffing**: Detects actual file type by magic bytes, not file extension
- **UUID filenames**: Storage names are random UUIDs to prevent path traversal
- **Path sanitization**: Strips null bytes, control characters, and traversal sequences
- **Size limits**: Configurable per-upload and globally
- **Antivirus scanning**: Optional `AntivirusPort` integration for regulated environments

### Antivirus Integration

```php
use Pulsar\Extension\Form\Contract\AntivirusPort;
use Pulsar\Extension\Form\Contract\AntivirusScanResult;

class ClamAvAdapter implements AntivirusPort {
    public function scan(string $filePath): AntivirusScanResult {
        // Scan with ClamAV...
        return AntivirusScanResult::clean();
        // or: return AntivirusScanResult::infected('Trojan.Generic');
    }
}
```

## Multi-Step Wizard

The wizard state machine manages multi-step form workflows with encrypted server-side state:

```php
use Pulsar\Extension\Form\Wizard\WizardStateMachine;
use Pulsar\Extension\Form\Wizard\WizardStep;

// Define steps
$steps = [
    new WizardStep(0, 'Personal Info', [
        'name' => new TextField('name', 'Name'),
        'email' => new EmailField('email', 'Email'),
    ]),
    new WizardStep(1, 'Address', [
        'street' => new TextField('street', 'Street'),
        'city' => new TextField('city', 'City'),
    ]),
    new WizardStep(2, 'Confirm', []),
];

// Start wizard
$state = $wizard->start('registration-wizard');

// Advance through steps
$state = $wizard->advance($state, ['name' => 'John', 'email' => 'john@example.com']);
$state = $wizard->advance($state, ['street' => '123 Main', 'city' => 'Springfield']);

// Check position
$wizard->isLastStep($state);        // true
$wizard->getCurrentStep($state);    // WizardStep(2, 'Confirm', [])

// Go back
$state = $wizard->goBack($state, 0); // Return to step 0

// Complete and get all data
$allData = $wizard->complete($state);
// [0 => ['name' => 'John', ...], 1 => ['street' => '123 Main', ...]]
```

### Security Features

- **Encrypted state**: Wizard state is encrypted via framework AEAD before session storage
- **Monotonic step counter**: Prevents step replay attacks
- **TTL enforcement**: Wizard sessions expire after configurable timeout
- **Resume tokens**: Single-use, cryptographically random tokens for "save and continue later"

### Resume Tokens

```php
// Issue a resume token
$token = $wizard->issueResumeToken($state);
// Store $token somewhere (email link, etc.)

// Later: resume the wizard
$state = $wizard->resume('registration-wizard', $token);
// Token is consumed - cannot be reused
```

## Regulated Fields

For compliance-critical domains (banking, healthcare, legal), regulated fields capture immutable consent evidence:

### ConsentCheckbox

```php
use Pulsar\Extension\Form\Field\Regulated\ConsentCheckbox;

$consent = new ConsentCheckbox(
    name: 'gdpr_consent',
    label: 'I consent to data processing',
    purpose: 'gdpr_data_processing',
    policyVersion: '2.1',
    policyText: 'Full text of the consent policy...',
);

$consent->setValue('yes');

if ($consent->isConsented()) {
    $evidence = $consent->captureEvidence(
        subject: 'user-123',
        correlationId: 'tx-abc-456',
        locale: 'en',
        templateHash: hash('sha256', $templateContent),
    );

    // ConsentEvidence contains:
    // - timestamp (Unix epoch)
    // - purpose ('gdpr_data_processing')
    // - policyVersion ('2.1')
    // - locale ('en')
    // - subject ('user-123')
    // - correlationId ('tx-abc-456')
    // - templateHash (SHA-256 of template)
    // - policyTextHash (SHA-256 of policy text - detects legal text changes)

    $store->persist($evidence);
}
```

### Other Regulated Fields

- **`DataProcessingAgreement`**: For DPA acceptance with evidence capture
- **`AgeVerification`**: Minimum age verification with configurable threshold
- **`SignatureField`**: Digital signature capture (e.g., Base64 canvas data)

### Policy Text Hashing

The `policyTextHash` (SHA-256 of the rendered consent text) detects when legal text changes independently of template layout changes. This ensures audit trails can detect material changes to consent language.

## Events

The form system dispatches events at key lifecycle points:

| Event                  | When                              | Payload                         |
| ---------------------- | --------------------------------- | ------------------------------- |
| `PreSubmitEvent`       | Before data is bound to fields    | `form`, `data` (raw array)      |
| `PostSubmitEvent`      | After data is bound to fields     | `form`                          |
| `PreValidateEvent`     | Before validation runs            | `form`                          |
| `ConsentCapturedEvent` | When consent evidence is captured | `form`, `fieldName`, `evidence` |

```php
$dispatcher->listen(PreSubmitEvent::class, function (PreSubmitEvent $event) {
    // Sanitize or transform data before binding
    $data = $event->data;
});
```

## Configuration Reference

```php
// config/form.php
return [
    'csrf' => [
        'enabled' => true,           // Enable CSRF protection
        'ttl' => 3600,               // Token lifetime in seconds
        'field_name' => '_token',     // Hidden field name
    ],
    'renderer' => [
        'theme' => 'default',        // CSS theme name
        'error_class' => 'form-error',
        'field_class' => 'form-group',
        'label_class' => 'form-label',
        'input_class' => 'form-control',
    ],
    'upload' => [
        'directory' => '/var/uploads',
        'max_size' => 10_485_760,     // Max file size in bytes
        'regulated_preset' => false,  // Require antivirus in regulated mode
    ],
    'wizard' => [
        'ttl' => 3600,               // Wizard session timeout
        'storage' => 'session',       // State storage backend
    ],
];
```

## Architecture

The Form extension follows Pulsar's extension-first architecture:

- **Contracts** (`Contract/`): Public interfaces (`FieldInterface`, `FormInterface`, `FormRendererInterface`, `AntivirusPort`, `ConsentStoreInterface`)
- **Fields** (`Field/`): 17+ field types implementing `FieldInterface`, plus regulated field variants
- **Builder** (`Builder/`): Fluent `FormBuilder` and `Form` implementation
- **Renderer** (`Renderer/`): WCAG-compliant HTML renderer
- **Binding** (`Binding/`): DTO hydration via `FormDataBinder` and `PropertyAccessor`
- **Upload** (`Upload/`): Secure file handling with MIME sniffing
- **Wizard** (`Wizard/`): Encrypted multi-step state machine
- **Events** (`Event/`): Lifecycle events for form processing
- **Config** (`Config/`): Typed readonly DTOs with `fromArray()` factories
