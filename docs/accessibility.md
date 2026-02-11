# Accessibility

## Overview

The `pulsar/accessibility` extension provides composable building blocks for WCAG 2.1 AA accessibility in Pulsar applications. It is designed for teams in regulated domains (healthcare, banking, legal) who need structured accessibility tooling with honest reporting about what automated checks can and cannot detect.

The extension provides:

- **Template helpers** for skip navigation, ARIA landmarks, live regions, and focus management
- **Static validators** that analyze HTML for common WCAG violations
- **Design token contrast checker** that evaluates CSS custom property color pairs against WCAG contrast thresholds
- **CLI audit command** for running accessibility checks in development and CI pipelines
- **Manual testing checklist** generator for issues that require human evaluation

### What this extension does NOT do

- It does not inject ARIA attributes into your HTML automatically. There is no middleware that rewrites markup. All helpers are explicit, opt-in building blocks that you call in your templates.
- It does not claim your application is "WCAG compliant." Automated tools can only detect approximately 30-40% of WCAG 2.1 issues. The remaining 60-70% require manual testing by humans, including screen reader evaluation, keyboard navigation testing, cognitive load assessment, and content quality review.

### Automated detection coverage

| What automated checks cover (~30-40%) | What requires manual testing (~60-70%)  |
| ------------------------------------- | --------------------------------------- |
| Missing alt attributes                | Alt text quality and accuracy           |
| Heading hierarchy violations          | Meaningful reading order                |
| Missing form labels                   | Cognitive load and form usability       |
| Landmark structure issues             | Color conveying meaning beyond contrast |
| Color contrast ratios (static tokens) | Content reflow at 400% zoom             |
| Missing ARIA attributes               | Screen reader navigation experience     |
|                                       | Touch target sizing on mobile           |
|                                       | Audio/video caption accuracy            |
|                                       | Complex interaction patterns            |

---

## Installation

Enable the accessibility extension in your application's `pulsar.json` manifest or extension configuration:

```json
{
  "extensions": ["pulsar/accessibility"]
}
```

The extension registers all services automatically through its service provider. The `a11y:audit` CLI command is registered only in non-production environments (when `app.debug` is true or `app.environment` is not `production`/`prod`).

### Configuration

Publish the configuration file to `config/accessibility.php`:

```php
return [
    'validators' => [
        'heading_hierarchy' => true,
        'form_labels' => true,
        'alt_text' => true,
        'landmark_structure' => true,
    ],

    'contrast' => [
        'aa_normal' => 4.5,
        'aa_large' => 3.0,
        'aaa_normal' => 7.0,
        'aaa_large' => 4.5,
    ],

    'report' => [
        'format' => 'text',
        'min_severity' => 'warning',
        'include_checklist' => true,
        'include_limitations' => true,
    ],
];
```

---

## Accessibility helpers

All helpers generate standards-conformant HTML with proper ARIA attributes and escaping. Inject them via constructor injection in your controllers or resolve them from the container in templates.

### SkipNavigation

Renders a visually hidden skip-to-main-content link that becomes visible on keyboard focus. This allows keyboard users to bypass repetitive navigation blocks (WCAG 2.4.1).

```php
use Pulsar\Extension\Accessibility\Helper\SkipNavigation;

$skip = new SkipNavigation();

// Default target: #main-content
echo $skip->render();
// <a class="pui-skip-link" href="#main-content">Skip to main content</a>

// Custom target ID
echo $skip->render('primary-content');
// <a class="pui-skip-link" href="#primary-content">Skip to main content</a>
```

Place the skip link as the first focusable element in your layout, before the site header:

```html
<!-- layout.php -->
<?= $skip->render() ?>

<header><!-- site navigation --></header>

<main id="main-content"><!-- page content --></main>
```

The `pui-skip-link` CSS class should visually hide the link and reveal it on `:focus`. See the [Reduced motion and keyboard navigation](#reduced-motion-and-keyboard-navigation) section for the CSS implementation.

### LandmarkRegion

Wraps content in semantic HTML elements with ARIA landmark roles. Landmarks enable assistive technology users to navigate directly between page sections (WCAG 1.3.1).

```php
use Pulsar\Extension\Accessibility\Helper\LandmarkRegion;

$landmark = new LandmarkRegion();

// Main content area
echo $landmark->main($content);
// <main>...</main>

echo $landmark->main($content, 'Primary content');
// <main aria-label="Primary content">...</main>

// Navigation (label is required for distinguishing multiple navs)
echo $landmark->navigation($navHtml, 'Primary navigation');
// <nav aria-label="Primary navigation">...</nav>

// Site banner (header)
echo $landmark->banner($headerHtml);
// <header role="banner">...</header>

// Footer (contentinfo)
echo $landmark->contentinfo($footerHtml);
// <footer role="contentinfo">...</footer>

// Sidebar (complementary)
echo $landmark->complementary($sidebarHtml, 'Related articles');
// <aside aria-label="Related articles">...</aside>

// Named region (section)
echo $landmark->region($sectionHtml, 'Search results');
// <section role="region" aria-label="Search results">...</section>
```

When multiple landmarks of the same type exist on a page, each must have a unique `aria-label` to distinguish them. The `LandmarkStructureValidator` checks for this.

### LiveRegion

Renders ARIA live region containers for dynamic content that should be announced to screen readers without requiring the user to navigate to the updated area (WCAG 4.1.3).

```php
use Pulsar\Extension\Accessibility\Helper\LiveRegion;

$live = new LiveRegion();

// Polite announcement (waits for current speech to finish)
echo $live->polite('status-message');
// <div id="status-message" aria-live="polite" aria-atomic="true"></div>

// With initial content
echo $live->polite('status-message', 'Form saved successfully.');

// Assertive announcement (interrupts current speech — use sparingly)
echo $live->assertive('error-alert');
// <div id="error-alert" aria-live="assertive" aria-atomic="true"></div>

// Status role (implicit polite live region)
echo $live->status('save-status');
// <div id="save-status" role="status" aria-live="polite" aria-atomic="true"></div>

// Log role (announces additions only)
echo $live->log('activity-log');
// <div id="activity-log" role="log" aria-live="polite" aria-relevant="additions"></div>
```

Update the content of these regions with JavaScript to trigger screen reader announcements:

```javascript
document.getElementById('status-message').textContent = '3 items added to cart.';
```

**Guidelines for live regions:**

- Use `polite` for status messages, save confirmations, and non-critical updates.
- Use `assertive` only for errors or time-sensitive alerts. Overuse of assertive announcements degrades the screen reader experience.
- Place live region containers in the DOM on page load (before content updates). Dynamically injected live regions may not be recognized by all screen readers.

### FocusManager

Generates data attributes consumed by client-side JavaScript to implement keyboard focus management patterns (WCAG 2.4.3, 2.1.2).

```php
use Pulsar\Extension\Accessibility\Helper\FocusManager;

$focus = new FocusManager();

// Focus trap — keeps focus within a container (for modals, dialogs)
echo '<div ' . $focus->trapAttributes('modal-1') . '>';
// <div data-focus-trap="modal-1" tabindex="-1">

// Focus restore — returns focus to trigger element when container closes
echo '<button ' . $focus->restoreFocusAttributes() . '>';
// <button data-focus-restore="true">

// Skip-to — programmatic focus jump to a target element
echo '<a ' . $focus->skipToAttributes('search-results') . '>';
// <a data-skip-to="search-results">

// Auto-focus — focus this element when its container becomes visible
echo '<input ' . $focus->autofocusAttributes() . '>';
// <input data-focus-auto="true">

// Roving tabindex — arrow key navigation within a group (tab panels, toolbars)
echo '<button ' . $focus->rovingtabAttributes('toolbar-1') . '>';
// <button data-roving-tab="toolbar-1" tabindex="-1">
```

These data attributes require companion JavaScript to function. See the [Reduced motion and keyboard navigation](#reduced-motion-and-keyboard-navigation) section for the `focus-manager.js` implementation.

---

## Validators

Validators analyze HTML strings for common WCAG violations. Each validator implements `ValidatorInterface` and returns a list of `AccessibilityViolation` objects with the rule name, severity, affected element, human-readable message, and WCAG criterion reference.

### Severity levels

| Severity | Meaning                                  |
| -------- | ---------------------------------------- |
| Error    | Definite WCAG failure that must be fixed |
| Warning  | Likely issue that should be reviewed     |
| Info     | Suggestion for improvement               |

### HeadingHierarchyValidator

Checks WCAG 1.3.1 (Info and Relationships) heading structure rules.

**What it detects:**

| Rule                 | Severity | Description                                                  |
| -------------------- | -------- | ------------------------------------------------------------ |
| `heading-level-skip` | Error    | Heading levels are skipped (e.g., `<h1>` followed by `<h3>`) |
| `multiple-h1`        | Warning  | More than one `<h1>` element found on the page               |

```php
use Pulsar\Extension\Accessibility\Validator\HeadingHierarchyValidator;

$validator = new HeadingHierarchyValidator();
$violations = $validator->validate('<h1>Title</h1><h3>Subsection</h3>');

foreach ($violations as $violation) {
    echo $violation->severity->value; // 'error'
    echo $violation->rule;            // 'heading-level-skip'
    echo $violation->wcagCriterion;   // '1.3.1'
    echo $violation->message;         // 'Heading level skipped: <h3> follows <h1>...'
    echo $violation->line;            // Line number in the HTML
}
```

### FormLabelValidator

Checks WCAG 1.3.1 (Info and Relationships) and 4.1.2 (Name, Role, Value) for form accessibility.

**What it detects:**

| Rule                 | Severity | Description                                                            |
| -------------------- | -------- | ---------------------------------------------------------------------- |
| `missing-form-label` | Error    | Input/select/textarea has no associated label, aria-label, or title    |
| `missing-fieldset`   | Warning  | Group of radio/checkbox inputs with the same name lacks a `<fieldset>` |

The validator correctly skips inputs of type `hidden`, `submit`, `button`, `image`, and `reset`. It recognizes labels associated by `for`/`id`, wrapping `<label>` elements, `aria-label`, `aria-labelledby`, and `title` attributes.

```php
use Pulsar\Extension\Accessibility\Validator\FormLabelValidator;

$validator = new FormLabelValidator();

// This will produce a violation — input has no label
$violations = $validator->validate('<input type="text" name="email">');

// These are all valid and produce no violations:
$validator->validate('<label for="email">Email</label><input id="email" type="text">');
$validator->validate('<label>Email <input type="text"></label>');
$validator->validate('<input type="text" aria-label="Email address">');
```

### AltTextValidator

Checks WCAG 1.1.1 (Non-text Content) for image alternative text.

**What it detects:**

| Rule               | Severity | Description                                              |
| ------------------ | -------- | -------------------------------------------------------- |
| `missing-alt`      | Error    | `<img>` element has no `alt` attribute                   |
| `generic-alt-text` | Warning  | Alt text is a generic word like "image", "photo", "icon" |

The validator accepts `alt=""` for decorative images and exempts images with `role="presentation"` or `aria-hidden="true"`.

```php
use Pulsar\Extension\Accessibility\Validator\AltTextValidator;

$validator = new AltTextValidator();

// Error — missing alt attribute
$validator->validate('<img src="photo.jpg">');

// Warning — generic alt text
$validator->validate('<img src="photo.jpg" alt="image">');

// Valid — descriptive alt text
$validator->validate('<img src="team.jpg" alt="Engineering team at the 2025 company retreat">');

// Valid — decorative image
$validator->validate('<img src="divider.png" alt="">');

// Valid — presentational image
$validator->validate('<img src="bg.png" role="presentation">');
```

Generic alt text values that trigger warnings: `image`, `photo`, `picture`, `icon`, `logo`, `graphic`, `screenshot`.

### LandmarkStructureValidator

Checks WCAG 1.3.1 (Info and Relationships) and 4.1.2 (Name, Role, Value) for ARIA landmark structure.

**What it detects:**

| Rule                               | Severity | Description                                                 |
| ---------------------------------- | -------- | ----------------------------------------------------------- |
| `missing-main-landmark`            | Warning  | No `<main>` or `role="main"` element found                  |
| `multiple-main-landmarks`          | Error    | Multiple main landmarks without distinct aria-labels        |
| `missing-nav-landmark`             | Warning  | No `<nav>` or `role="navigation"` element found             |
| `duplicate-landmark-missing-label` | Warning  | Multiple landmarks of same type, one or more missing labels |
| `duplicate-landmark-label`         | Warning  | Multiple landmarks of same type share the same label        |

```php
use Pulsar\Extension\Accessibility\Validator\LandmarkStructureValidator;

$validator = new LandmarkStructureValidator();

$html = <<<'HTML'
<nav aria-label="Primary">...</nav>
<nav aria-label="Footer">...</nav>
<main>...</main>
HTML;

$violations = $validator->validate($html);
// No violations — landmarks are properly labeled
```

### Running all validators together

Use `AccessibilityAuditor` to run all registered validators against HTML content in a single call:

```php
use Pulsar\Extension\Accessibility\Audit\AccessibilityAuditor;

$auditor = $container->get(AccessibilityAuditor::class);

// Audit a raw HTML string
$report = $auditor->auditHtml($html);

// Audit a template file
$report = $auditor->auditTemplateFile('/path/to/template.php');

// Audit all matching files in a directory
$report = $auditor->auditDirectory('/path/to/templates', '*.php');

// Inspect results
echo $report->summary()->errorCount;    // Number of errors
echo $report->summary()->warningCount;  // Number of warnings
echo $report->hasErrors();              // true if any errors exist
```

---

## Design token contrast checker

The `DesignTokenContrastChecker` validates color contrast ratios between design token pairs defined as CSS custom properties. It extracts `--color-*` tokens from `:root` blocks in CSS files and evaluates standard text/background combinations against WCAG 2.1 contrast thresholds.

### WCAG contrast thresholds

| Level      | Text size    | Minimum ratio | Typical use                         |
| ---------- | ------------ | ------------- | ----------------------------------- |
| AA Normal  | < 18pt       | 4.5:1         | Body text, form labels, link text   |
| AA Large   | >= 18pt bold | 3.0:1         | Headings, large UI text             |
| AAA Normal | < 18pt       | 7.0:1         | Enhanced readability (recommended)  |
| AAA Large  | >= 18pt bold | 4.5:1         | Enhanced readability for large text |

### Checking a CSS file

The checker extracts `--color-*` custom properties from `:root` blocks and evaluates standard text/background pairs:

```php
use Pulsar\Extension\Accessibility\Contrast\DesignTokenContrastChecker;

$checker = $container->get(DesignTokenContrastChecker::class);

$report = $checker->checkCssFile('resources/css/theme.css');

echo $report->totalPairs;  // Number of token pairs evaluated
echo $report->passingAa;   // Pairs passing AA Normal (4.5:1)
echo $report->failingAa;   // Pairs failing AA Normal

// Get all failing pairs
foreach ($report->failures('aa_normal') as $result) {
    echo sprintf(
        "%s on %s: ratio %.2f:1 (needs 4.5:1)\n",
        $result->foregroundToken,
        $result->backgroundToken,
        $result->ratio,
    );
}
```

The checker evaluates these standard token pairs automatically:

- `--color-text` against `--color-bg`, `--color-bg-secondary`, `--color-bg-tertiary`, `--color-bg-elevated`
- `--color-text-secondary` against all four background tokens
- `--color-text-muted` against all four background tokens
- `--color-link` against all four background tokens

### Checking explicit color pairs

For custom color combinations not covered by the standard pairs:

```php
$report = $checker->checkTokenPairs([
    [
        'fg' => '#333333',
        'bg' => '#ffffff',
        'fgToken' => '--color-primary-text',
        'bgToken' => '--color-primary-bg',
    ],
    [
        'fg' => 'rgb(100, 100, 100)',
        'bg' => 'hsl(0, 0%, 95%)',
        'fgToken' => '--color-muted',
        'bgToken' => '--color-surface',
    ],
]);

foreach ($report->results as $result) {
    echo $result->passesAaNormal;  // bool
    echo $result->passesAaLarge;   // bool
    echo $result->passesAaaNormal; // bool
    echo $result->passesAaaLarge;  // bool
}
```

### Supported color formats

The `ColorParser` supports these CSS color formats:

- Hex: `#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA`
- RGB: `rgb(R, G, B)`, `rgba(R, G, B, A)`, `rgb(R G B)`, `rgb(R G B / A)`
- HSL: `hsl(H, S%, L%)`, `hsla(H, S%, L%, A)`, `hsl(H S% L%)`, `hsl(H S% L% / A)`
- Named colors: `black`, `white`, `red`, `blue`, `transparent`, and 40+ additional CSS named colors

Color values containing `var()` references are skipped during CSS file analysis since they cannot be resolved statically.

### Serializing reports

Both `ContrastReport` and `AuditReport` support `toArray()` for serialization:

```php
$data = $report->toArray();
// Returns: [
//     'total_pairs' => 16,
//     'passing_aa' => 14,
//     'failing_aa' => 2,
//     'results' => [
//         ['foreground' => '#333333', 'background' => '#ffffff', 'ratio' => 12.63, ...],
//     ],
// ]
```

---

## CLI audit command

The `a11y:audit` command runs accessibility validators against template files from the command line. It is registered only in development and CI environments.

### Basic usage

```bash
# Audit a single template file
pulsar a11y:audit resources/views/home.php

# Audit all PHP templates in a directory
pulsar a11y:audit resources/views/

# Audit with a specific file pattern
pulsar a11y:audit resources/views/ --pattern "*.html"
```

### Output formats

```bash
# Default: human-readable text output
pulsar a11y:audit resources/views/

# JSON output for CI integration
pulsar a11y:audit resources/views/ --format json
```

The JSON output includes violations, summary statistics, the manual checklist, known limitations, and a disclaimer. Pipe it to `jq` or parse it in CI scripts:

```bash
# Fail CI if any errors exist (exit code 1 on errors, 0 otherwise)
pulsar a11y:audit resources/views/ --format json
```

The command returns exit code `0` when no errors are found (warnings are allowed) and exit code `1` when at least one error-severity violation exists.

### Severity filtering

```bash
# Show only errors (skip warnings and info)
pulsar a11y:audit resources/views/ --severity error

# Show errors and warnings (default)
pulsar a11y:audit resources/views/ --severity warning

# Show everything including informational items
pulsar a11y:audit resources/views/ --severity info
```

### Browser-based auditing (optional)

For computed accessibility checks on rendered pages served over HTTP:

```bash
pulsar a11y:audit http://localhost:8000 --browser
```

Browser-based auditing requires Playwright or Puppeteer to be installed (`npm install -D playwright`). It provides computed contrast ratios (accounting for stacking, transparency, and inherited styles), focus order analysis, and rendered ARIA property evaluation that static analysis cannot perform.

### CI integration example

```yaml
# .github/workflows/accessibility.yml
- name: Accessibility audit
  run: php bin/pulsar a11y:audit resources/views/ --format json --severity error
```

### Text output example

```
  Pulsar Accessibility Audit
  ========================================

  [ERROR] Heading level skipped: <h3> follows <h1>. Expected <h2> or lower. (WCAG 1.3.1)
         Element: <h3>Quick Start</h3>
         Line: 42

  [WARN] <input> element has no associated <label>, aria-label, or aria-labelledby attribute. (WCAG 4.1.2)
         Element: <input type="text" name="search">
         Line: 18

  Summary: 1 errors, 1 warnings, 0 info (4 checks, 1 files)

  LIMITATIONS
  ----------------------------------------
  - Automated tools can only detect approximately 30-40% of WCAG 2.1 issues.
  - Cognitive accessibility (reading level, cognitive load) requires human evaluation.
  - ...

  MANUAL TESTING CHECKLIST
  ----------------------------------------
  [Images]
    [ ] Verify that alt text accurately describes the purpose and content of each image. (WCAG 1.1.1 A)

  [Structure]
    [ ] Verify that the visual reading order matches the DOM order. (WCAG 1.3.2 A)
  ...
```

---

## Manual testing checklist

Automated accessibility checks cover only a fraction of WCAG 2.1 requirements. The `ManualChecklistGenerator` produces a structured checklist of items that require human evaluation.

### Generating the checklist

```php
use Pulsar\Extension\Accessibility\Audit\ManualChecklistGenerator;

$generator = new ManualChecklistGenerator();

// Flat list of all checklist items
$items = $generator->generate();

foreach ($items as $item) {
    echo $item->id;             // 'manual-keyboard-navigation'
    echo $item->category;       // 'Keyboard'
    echo $item->description;    // 'Verify all interactive elements...'
    echo $item->wcagCriterion;  // '2.1.1'
    echo $item->wcagLevel;      // 'A'
    echo $item->guidance;       // Detailed testing guidance
}

// Grouped by category
$grouped = $generator->generateGrouped();

foreach ($grouped as $category => $items) {
    echo "## {$category}\n";
    foreach ($items as $item) {
        echo "- [ ] {$item->description} (WCAG {$item->wcagCriterion} {$item->wcagLevel})\n";
    }
}
```

### Checklist categories

| Category      | Items | Focus area                                     |
| ------------- | ----- | ---------------------------------------------- |
| Images        | 1     | Alt text quality and accuracy                  |
| Structure     | 1     | Visual reading order matches DOM order         |
| Color         | 1     | Color not sole means of conveying information  |
| Responsive    | 1     | Content reflow at 400% zoom                    |
| Typography    | 1     | Readability with increased text spacing        |
| Keyboard      | 2     | Keyboard navigation and focus visibility       |
| Cognitive     | 1     | Form usability and cognitive load              |
| Forms         | 1     | Error identification and description           |
| Screen Reader | 1     | Full page screen reader navigation             |
| Mobile        | 1     | Touch target sizing (44x44 CSS pixels minimum) |
| Media         | 1     | Video captions and audio descriptions          |

### Screen reader testing guidance

The checklist includes guidance for screen reader testing. At minimum, test with one of:

- **NVDA** (free, Windows) - most common free screen reader
- **VoiceOver** (built-in, macOS/iOS) - test on Safari
- **JAWS** (commercial, Windows) - most common enterprise screen reader

Test these interactions with each screen reader:

1. Navigate the full page using screen reader commands (not Tab)
2. Verify all content is announced, including dynamic updates
3. Verify landmarks provide navigation shortcuts (NVDA: `D` key, VoiceOver: rotor)
4. Verify live regions announce dynamic changes
5. Verify custom widgets expose correct roles, states, and values

### Keyboard navigation testing

Test these keyboard interactions without a screen reader:

1. **Tab** through every interactive element. Verify a visible focus indicator appears.
2. **Enter/Space** activate buttons and links.
3. **Escape** closes modals and dropdowns and returns focus to the trigger element.
4. **Arrow keys** navigate within tab panels, menus, toolbars, and radio groups.
5. No keyboard traps exist (focus can always move away from any element, except intentional focus traps in modals that close on Escape).

---

## Reduced motion and keyboard navigation

### `prefers-reduced-motion` support

Respect the user's motion preferences by wrapping animations in a `prefers-reduced-motion` media query. Users who have enabled "Reduce motion" in their OS settings have this preference set to `reduce`.

```css
/* Default: animations run normally */
.animated-element {
  transition: transform 0.3s ease;
}

/* Reduced motion: disable or minimize animations */
@media (prefers-reduced-motion: reduce) {
  .animated-element {
    transition: none;
  }
}
```

For JavaScript animations, check the preference before starting:

```javascript
const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

if (!prefersReduced) {
  element.animate(keyframes, options);
}
```

### Skip navigation CSS

Style the `pui-skip-link` class to be visually hidden until focused:

```css
.pui-skip-link {
  position: absolute;
  top: -100%;
  left: 0;
  z-index: 10000;
  padding: 0.75rem 1.5rem;
  background: var(--color-bg, #ffffff);
  color: var(--color-text, #1a1a1a);
  font-weight: 600;
  text-decoration: none;
  border: 2px solid var(--color-focus, #2563eb);
  border-radius: 0.25rem;
}

.pui-skip-link:focus {
  top: 0.5rem;
  left: 0.5rem;
  outline: 3px solid var(--color-focus, #2563eb);
  outline-offset: 2px;
}
```

### Focus management with `focus-manager.js`

The `FocusManager` helper generates data attributes that require companion JavaScript. A reference implementation:

```javascript
// Focus trap: keep Tab/Shift+Tab within a container
document.querySelectorAll('[data-focus-trap]').forEach((container) => {
  container.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab') return;

    const focusable = container.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    );

    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
});

// Auto-focus: focus the first [data-focus-auto] element
document.querySelectorAll('[data-focus-auto]').forEach((el) => {
  el.focus();
});

// Skip-to: jump focus to target element
document.querySelectorAll('[data-skip-to]').forEach((trigger) => {
  trigger.addEventListener('click', (event) => {
    event.preventDefault();
    const targetId = trigger.getAttribute('data-skip-to');
    const target = document.getElementById(targetId);
    if (target) {
      target.setAttribute('tabindex', '-1');
      target.focus();
    }
  });
});

// Focus restore: return focus when a container closes
document.querySelectorAll('[data-focus-restore]').forEach((trigger) => {
  trigger.addEventListener('click', () => {
    // Store the trigger reference; restore focus when the opened
    // container (modal, dropdown) is closed
    trigger._previousFocus = document.activeElement;
  });
});

// Roving tabindex: arrow key navigation within a group
document.querySelectorAll('[data-roving-tab]').forEach((item) => {
  item.addEventListener('keydown', (event) => {
    const groupId = item.getAttribute('data-roving-tab');
    const group = [...document.querySelectorAll(`[data-roving-tab="${groupId}"]`)];
    const currentIndex = group.indexOf(item);

    let nextIndex = -1;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      nextIndex = (currentIndex + 1) % group.length;
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      nextIndex = (currentIndex - 1 + group.length) % group.length;
    }

    if (nextIndex >= 0) {
      event.preventDefault();
      group.forEach((el) => el.setAttribute('tabindex', '-1'));
      group[nextIndex].setAttribute('tabindex', '0');
      group[nextIndex].focus();
    }
  });
});
```

### Focus trap configuration

Set up a focus trap for modal dialogs:

```php
// In your template
<div class="modal" <?= $focus->trapAttributes('settings-modal') ?>>
    <h2>Settings</h2>
    <input type="text" <?= $focus->autofocusAttributes() ?>>
    <button type="submit">Save</button>
    <button type="button" onclick="closeModal()">Cancel</button>
</div>
```

When the modal opens, focus is trapped within it. Tab and Shift+Tab cycle through focusable elements inside the container. When the modal closes, focus should return to the element that triggered it.

---

## Compliance documentation

The accessibility extension provides structured output suitable for generating compliance evidence, but it does not produce compliance certificates. Compliance is a process that requires both automated testing and human evaluation.

### Generating evidence from automated checks

```php
$auditor = $container->get(AccessibilityAuditor::class);
$report = $auditor->auditDirectory('resources/views/', '*.php');

// Structured data for compliance documentation
$evidence = $report->toArray();

// evidence['summary'] includes:
//   total_checks, errors, warnings, info, total_violations, files_audited

// evidence['violations'] includes per-violation:
//   rule, severity, element, message, wcag_criterion, line

// evidence['limitations'] lists what automated tools cannot check

// evidence['disclaimer'] states:
//   "This report covers automated checks only. Automated tools detect
//    approximately 30-40% of WCAG 2.1 issues. Manual testing is required
//    for full compliance assessment."
```

### Compliance evidence template

A compliance assessment should include these sections:

**1. Automated test evidence**

- Date and scope of automated audit
- Tool and version used (Pulsar Accessibility Extension)
- Number of templates audited
- Violations found and remediation status
- Known limitations of automated checks

**2. Manual test evidence**

- Date and tester identity
- Screen reader(s) used and version(s)
- Browser(s) and version(s) tested
- Manual checklist completion status (from `ManualChecklistGenerator`)
- Issues found and remediation status

**3. Limitation disclosures**

Every compliance document should include these disclosures:

- Automated accessibility checking covers approximately 30-40% of WCAG 2.1 criteria.
- The remaining criteria require manual evaluation by trained testers.
- Accessibility is an ongoing process, not a one-time certification. Template changes, new features, and dependency updates may introduce new issues.
- This tooling assists with identifying issues but does not guarantee that all issues have been found.

### Regulatory context

WCAG 2.1 AA is the technical standard referenced by major accessibility regulations. This extension helps identify a subset of WCAG issues relevant to these frameworks but does not guarantee compliance with any of them:

- **ADA Title III** (United States): The DOJ final rule (April 2024) requires WCAG 2.1 Level AA for state and local government web content. Private sector web accessibility follows similar standards through case law and settlement agreements.
- **Section 508** (United States): Federal ICT accessibility standard references WCAG 2.0 Level AA. WCAG 2.1 is a strict superset of 2.0, so meeting 2.1 AA satisfies Section 508 technical requirements.
- **EN 301 549** (European Union): The European ICT accessibility standard references WCAG 2.1 Level AA for web content (clause 9).

Organizations subject to these regulations should consult legal counsel and accessibility specialists to determine their specific obligations. Automated checks are one component of a broader compliance program.

### Generating the manual checklist for evidence

```php
$generator = new ManualChecklistGenerator();
$grouped = $generator->generateGrouped();

// Output as a Markdown checklist for evidence documentation
foreach ($grouped as $category => $items) {
    echo "### {$category}\n\n";
    foreach ($items as $item) {
        echo "- [ ] {$item->description}\n";
        echo "  - WCAG: {$item->wcagCriterion} (Level {$item->wcagLevel})\n";
        echo "  - Guidance: {$item->guidance}\n\n";
    }
}
```

---

## WCAG criteria reference

Quick reference for which WCAG 2.1 criteria are covered by the automated validators in this extension versus those requiring manual review.

### Automated checks

| Criterion | Level | Name                   | Validator                                                                       |
| --------- | ----- | ---------------------- | ------------------------------------------------------------------------------- |
| 1.1.1     | A     | Non-text Content       | `AltTextValidator`                                                              |
| 1.3.1     | A     | Info and Relationships | `HeadingHierarchyValidator`, `FormLabelValidator`, `LandmarkStructureValidator` |
| 4.1.2     | A     | Name, Role, Value      | `FormLabelValidator`, `LandmarkStructureValidator`                              |

### Automated checks (contrast)

| Criterion | Level | Name                | Tool                         |
| --------- | ----- | ------------------- | ---------------------------- |
| 1.4.3     | AA    | Contrast (Minimum)  | `DesignTokenContrastChecker` |
| 1.4.6     | AAA   | Contrast (Enhanced) | `DesignTokenContrastChecker` |

### Manual review required

| Criterion | Level | Name                              | Checklist item                |
| --------- | ----- | --------------------------------- | ----------------------------- |
| 1.2.2     | A     | Captions (Prerecorded)            | `manual-media-captions`       |
| 1.3.2     | A     | Meaningful Sequence               | `manual-reading-order`        |
| 1.4.1     | A     | Use of Color                      | `manual-color-meaning`        |
| 1.4.10    | AA    | Reflow                            | `manual-reflow`               |
| 1.4.12    | AA    | Text Spacing                      | `manual-text-spacing`         |
| 2.1.1     | A     | Keyboard                          | `manual-keyboard-navigation`  |
| 2.4.7     | AA    | Focus Visible                     | `manual-focus-visible`        |
| 2.5.5     | AAA   | Target Size                       | `manual-touch-target`         |
| 3.3.1     | A     | Error Identification              | `manual-error-identification` |
| 3.3.2     | A     | Labels or Instructions            | `manual-cognitive-load`       |
| 4.1.2     | A     | Name, Role, Value (screen reader) | `manual-screen-reader`        |

### Not covered

Many WCAG 2.1 criteria are outside the scope of this extension entirely. These include but are not limited to:

- 1.2.x (Audio and Video) - media captioning, audio descriptions, sign language
- 2.2.x (Enough Time) - timing adjustments, pause/stop/hide
- 2.3.x (Seizures and Physical Reactions) - flashing content limits
- 2.4.x (Navigable) - page titles, link purpose, section headings (partially covered)
- 2.5.x (Input Modalities) - pointer gestures, motion actuation
- 3.1.x (Readable) - language of page, language of parts
- 3.2.x (Predictable) - on focus, on input, consistent navigation

For a complete WCAG 2.1 criteria list, refer to the [W3C WCAG 2.1 specification](https://www.w3.org/TR/WCAG21/).
