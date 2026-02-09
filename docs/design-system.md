# Design system

Pulsar UI is a zero-dependency CSS framework providing design tokens, a responsive grid, utility classes, and a component library. It powers all Pulsar GUIs (Admin, Studio, user portals) with a unified visual language.

## Installation

Import the entry point in your HTML or layout template:

```html
<link rel="stylesheet" href="/ui/css/pulsar-ui.css" />
```

Or in a Pulsar template layout:

```html
<link rel="stylesheet" href="{{ url('/ui/css/pulsar-ui.css') }}" />
```

The entry point (`resources/ui/css/pulsar-ui.css`) imports all layers in order:

1. **tokens.css**: Design tokens (CSS custom properties)
2. **base.css**: Element resets and base typography
3. **grid.css**: 12-column responsive grid
4. **utilities.css**: Utility classes
5. **print.css**: Print stylesheet
6. **components/**: All component CSS files

## Design tokens

Design tokens are CSS custom properties defined in `resources/ui/css/tokens.css`. They are the single source of truth for all visual properties. Components reference tokens, never raw values.

### Color palettes

Each palette provides shades from 50 (lightest) to 950 (darkest):

| Palette   | Token prefix          | Base color |
| --------- | --------------------- | ---------- |
| Primary   | `--color-primary-*`   | Blue       |
| Secondary | `--color-secondary-*` | Indigo     |
| Success   | `--color-success-*`   | Green      |
| Warning   | `--color-warning-*`   | Amber      |
| Danger    | `--color-danger-*`    | Red        |
| Info      | `--color-info-*`      | Sky        |
| Neutral   | `--color-gray-*`      | Slate      |

Example usage:

```css
.my-element {
  background: var(--color-primary-500);
  color: var(--color-gray-50);
}
```

### Semantic colors

Semantic tokens map to palette values and change between light and dark themes:

| Token                    | Light default         | Purpose                   |
| ------------------------ | --------------------- | ------------------------- |
| `--color-text`           | `--color-gray-900`    | Primary text              |
| `--color-text-secondary` | `--color-gray-700`    | Secondary text            |
| `--color-text-muted`     | `--color-gray-500`    | Muted/hint text           |
| `--color-text-disabled`  | `--color-gray-400`    | Disabled state text       |
| `--color-text-inverse`   | `#ffffff`             | Text on dark backgrounds  |
| `--color-bg`             | `#ffffff`             | Primary background        |
| `--color-bg-secondary`   | `--color-gray-50`     | Secondary background      |
| `--color-bg-tertiary`    | `--color-gray-100`    | Tertiary background       |
| `--color-bg-elevated`    | `#ffffff`             | Elevated surfaces (cards) |
| `--color-border`         | `--color-gray-200`    | Default border            |
| `--color-border-strong`  | `--color-gray-300`    | Emphasized border         |
| `--color-border-muted`   | `--color-gray-100`    | Subtle border             |
| `--color-focus-ring`     | `--color-primary-500` | Focus indicator           |
| `--color-link`           | `--color-primary-600` | Link text                 |
| `--color-link-hover`     | `--color-primary-700` | Link hover state          |

### Spacing scale

A consistent spacing scale from 0 to 64 (in `rem` units):

| Token        | Value     | Token        | Value    |
| ------------ | --------- | ------------ | -------- |
| `--space-0`  | `0`       | `--space-8`  | `2rem`   |
| `--space-px` | `1px`     | `--space-10` | `2.5rem` |
| `--space-1`  | `0.25rem` | `--space-12` | `3rem`   |
| `--space-2`  | `0.5rem`  | `--space-16` | `4rem`   |
| `--space-3`  | `0.75rem` | `--space-20` | `5rem`   |
| `--space-4`  | `1rem`    | `--space-24` | `6rem`   |
| `--space-5`  | `1.25rem` | `--space-32` | `8rem`   |
| `--space-6`  | `1.5rem`  | `--space-64` | `16rem`  |

Half-step values are also available: `--space-0-5`, `--space-1-5`, `--space-2-5`, `--space-3-5`.

### Typography

#### Font families

| Token          | Stack                                                            |
| -------------- | ---------------------------------------------------------------- |
| `--font-sans`  | system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, etc. |
| `--font-serif` | Georgia, Cambria, Times New Roman, Times, serif                  |
| `--font-mono`  | ui-monospace, SFMono-Regular, SF Mono, Menlo, Consolas, etc.     |

#### Font sizes

| Token         | Size       | Paired line-height token | Line height |
| ------------- | ---------- | ------------------------ | ----------- |
| `--text-xs`   | `0.75rem`  | `--text-xs-leading`      | `1rem`      |
| `--text-sm`   | `0.875rem` | `--text-sm-leading`      | `1.25rem`   |
| `--text-base` | `1rem`     | `--text-base-leading`    | `1.5rem`    |
| `--text-lg`   | `1.125rem` | `--text-lg-leading`      | `1.75rem`   |
| `--text-xl`   | `1.25rem`  | `--text-xl-leading`      | `1.75rem`   |
| `--text-2xl`  | `1.5rem`   | `--text-2xl-leading`     | `2rem`      |
| `--text-3xl`  | `1.875rem` | `--text-3xl-leading`     | `2.25rem`   |
| `--text-4xl`  | `2.25rem`  | `--text-4xl-leading`     | `2.5rem`    |
| `--text-5xl`  | `3rem`     | `--text-5xl-leading`     | `1`         |

#### Font weights

`--font-thin` (100), `--font-extralight` (200), `--font-light` (300), `--font-normal` (400), `--font-medium` (500), `--font-semibold` (600), `--font-bold` (700), `--font-extrabold` (800), `--font-black` (900).

#### Letter spacing

`--tracking-tighter` (-0.05em) through `--tracking-widest` (0.1em).

### Border radii

| Token           | Value      |
| --------------- | ---------- |
| `--radius-none` | `0`        |
| `--radius-sm`   | `0.125rem` |
| `--radius`      | `0.25rem`  |
| `--radius-md`   | `0.375rem` |
| `--radius-lg`   | `0.5rem`   |
| `--radius-xl`   | `0.75rem`  |
| `--radius-2xl`  | `1rem`     |
| `--radius-3xl`  | `1.5rem`   |
| `--radius-full` | `9999px`   |

### Shadows

| Token            | Description        |
| ---------------- | ------------------ |
| `--shadow-xs`    | Minimal shadow     |
| `--shadow-sm`    | Small shadow       |
| `--shadow`       | Default shadow     |
| `--shadow-md`    | Medium shadow      |
| `--shadow-lg`    | Large shadow       |
| `--shadow-xl`    | Extra large shadow |
| `--shadow-2xl`   | Heavy shadow       |
| `--shadow-inner` | Inset shadow       |
| `--shadow-none`  | No shadow          |

### Transitions

| Token                 | Value        |
| --------------------- | ------------ |
| `--transition-fast`   | `150ms ease` |
| `--transition-normal` | `200ms ease` |
| `--transition-slow`   | `300ms ease` |

### Z-Index scale

| Token                | Value |
| -------------------- | ----- |
| `--z-base`           | 0     |
| `--z-dropdown`       | 1000  |
| `--z-sticky`         | 1020  |
| `--z-fixed`          | 1030  |
| `--z-modal-backdrop` | 1040  |
| `--z-modal`          | 1050  |
| `--z-popover`        | 1060  |
| `--z-tooltip`        | 1070  |
| `--z-toast`          | 1080  |

## Grid system

Pulsar UI provides a 12-column responsive grid system with both flexbox and CSS Grid options.

### Breakpoints

| Prefix | Min width | Container max width |
| ------ | --------- | ------------------- |
| (none) | 0         | 100%                |
| `sm`   | 640px     | 640px               |
| `md`   | 768px     | 768px               |
| `lg`   | 1024px    | 1024px              |
| `xl`   | 1280px    | 1280px              |
| `2xl`  | 1536px    | 1536px              |

### Container

```html
<!-- Responsive container with max-width per breakpoint -->
<div class="container">...</div>

<!-- Full-width container with padding -->
<div class="container-fluid">...</div>
```

### Flexbox grid

```html
<!-- Basic 2-column layout -->
<div class="row">
  <div class="col-6">Left half</div>
  <div class="col-6">Right half</div>
</div>

<!-- Responsive: stacked on mobile, 3 columns on medium+ -->
<div class="row">
  <div class="col-12 col-md-4">Column 1</div>
  <div class="col-12 col-md-4">Column 2</div>
  <div class="col-12 col-md-4">Column 3</div>
</div>

<!-- Sidebar + content layout -->
<div class="row">
  <div class="col-12 col-lg-3">Sidebar</div>
  <div class="col-12 col-lg-9">Main content</div>
</div>
```

Column classes: `.col-1` through `.col-12`, `.col-auto`. Responsive variants: `.col-sm-*`, `.col-md-*`, `.col-lg-*`, `.col-xl-*`, `.col-2xl-*`.

Offset classes: `.offset-1` through `.offset-11` (uses `margin-inline-start` for RTL support).

### CSS grid

```html
<!-- 3-column CSS Grid -->
<div class="grid grid-cols-3 gap-4">
  <div>Item 1</div>
  <div>Item 2</div>
  <div>Item 3</div>
</div>

<!-- Responsive grid: 1 col on mobile, 2 on md, 4 on lg -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
  <div>Card 1</div>
  <div>Card 2</div>
  <div>Card 3</div>
  <div>Card 4</div>
</div>

<!-- Column spanning -->
<div class="grid grid-cols-12 gap-4">
  <div class="col-span-8">Wide content</div>
  <div class="col-span-4">Narrow sidebar</div>
</div>
```

Grid column classes: `.grid-cols-1` through `.grid-cols-12`, `.grid-cols-none`. Responsive variants: `sm:grid-cols-*`, `md:grid-cols-*`, `lg:grid-cols-*`, `xl:grid-cols-*`.

Span classes: `.col-span-1` through `.col-span-12`, `.col-span-full`.

Row classes: `.grid-rows-1` through `.grid-rows-6`, `.row-span-1` through `.row-span-6`, `.row-span-full`.

Placement classes: `.col-start-1` through `.col-start-13`, `.col-end-1` through `.col-end-13`.

### Gap utilities

| Class      | Property     | Value               |
| ---------- | ------------ | ------------------- |
| `.gap-0`   | `gap`        | `var(--space-0)`    |
| `.gap-1`   | `gap`        | `var(--space-1)`    |
| `.gap-2`   | `gap`        | `var(--space-2)`    |
| `.gap-4`   | `gap`        | `var(--space-4)`    |
| `.gap-6`   | `gap`        | `var(--space-6)`    |
| `.gap-8`   | `gap`        | `var(--space-8)`    |
| `.gap-x-*` | `column-gap` | Corresponding space |
| `.gap-y-*` | `row-gap`    | Corresponding space |

## Utility classes

Utility classes reference design tokens and support responsive prefixes (`sm:`, `md:`, `lg:`, `xl:`).

### Display

`.d-none`, `.d-block`, `.d-inline`, `.d-inline-block`, `.d-flex`, `.d-inline-flex`, `.d-grid`, `.d-inline-grid`, `.d-table`, `.d-table-row`, `.d-table-cell`, `.d-contents`.

Responsive: `sm:d-none`, `md:d-block`, `lg:d-flex`, etc.

### Flexbox

**Direction**: `.flex-row`, `.flex-row-reverse`, `.flex-col`, `.flex-col-reverse`

**Wrap**: `.flex-wrap`, `.flex-nowrap`, `.flex-wrap-reverse`

**Grow/Shrink**: `.flex-1`, `.flex-auto`, `.flex-initial`, `.flex-none`, `.flex-grow`, `.flex-grow-0`, `.flex-shrink`, `.flex-shrink-0`

**Align items**: `.items-start`, `.items-end`, `.items-center`, `.items-baseline`, `.items-stretch`

**Align self**: `.self-auto`, `.self-start`, `.self-end`, `.self-center`, `.self-stretch`, `.self-baseline`

**Justify content**: `.justify-start`, `.justify-end`, `.justify-center`, `.justify-between`, `.justify-around`, `.justify-evenly`

**Place**: `.place-items-center`, `.place-content-center`

**Order**: `.order-first`, `.order-last`

### Spacing

Margin and padding utilities follow the pattern `.{property}{side}-{size}`:

- Property: `m` (margin), `p` (padding)
- Side: (none = all), `t` (top), `b` (bottom), `s` (inline-start), `e` (inline-end), `x` (inline), `y` (block)
- Size: `0`, `1`, `2`, `3`, `4`, `5`, `6`, `8`, `10`, `12`, `16`, `auto` (margin only)

```html
<div class="p-4 mt-2 mx-auto">Padded, top-margin, centered</div>
```

### Text

**Size**: `.text-xs`, `.text-sm`, `.text-base`, `.text-lg`, `.text-xl`, `.text-2xl`, `.text-3xl`, `.text-4xl`, `.text-5xl`

**Weight**: `.font-thin`, `.font-light`, `.font-normal`, `.font-medium`, `.font-semibold`, `.font-bold`, `.font-extrabold`, `.font-black`

**Alignment**: `.text-start`, `.text-end`, `.text-center`, `.text-justify`

**Transform**: `.uppercase`, `.lowercase`, `.capitalize`, `.normal-case`

**Wrapping**: `.truncate`, `.text-nowrap`, `.text-wrap`, `.break-words`, `.break-all`

### Colors

**Text colors**: `.text-primary`, `.text-secondary`, `.text-success`, `.text-warning`, `.text-danger`, `.text-info`, `.text-muted`

**Background colors**: `.bg-primary`, `.bg-secondary`, `.bg-success`, `.bg-warning`, `.bg-danger`, `.bg-info`, `.bg-white`, `.bg-transparent`

### Borders

**Width**: `.border`, `.border-0`, `.border-2`

**Color**: `.border-primary`, `.border-success`, `.border-danger`, `.border-warning`

**Radius**: `.rounded-none`, `.rounded-sm`, `.rounded`, `.rounded-md`, `.rounded-lg`, `.rounded-xl`, `.rounded-full`

### Other utilities

**Shadow**: `.shadow-xs`, `.shadow-sm`, `.shadow`, `.shadow-md`, `.shadow-lg`, `.shadow-xl`, `.shadow-none`

**Opacity**: `.opacity-0`, `.opacity-25`, `.opacity-50`, `.opacity-75`, `.opacity-100`

**Overflow**: `.overflow-auto`, `.overflow-hidden`, `.overflow-visible`, `.overflow-scroll`

**Position**: `.relative`, `.absolute`, `.fixed`, `.sticky`

**Width/Height**: `.w-full`, `.w-auto`, `.h-full`, `.h-auto`, `.min-h-screen`

**Cursor**: `.cursor-pointer`, `.cursor-default`, `.cursor-not-allowed`

**Visibility**: `.visible`, `.invisible`

**Screen reader**: `.sr-only` (visually hidden, accessible to screen readers)

## Component library

### Layout

#### Container

```html
<div class="container">Responsive max-width container</div>
<div class="container-fluid">Full-width container</div>
```

#### Stack

Vertical stack with consistent spacing between children:

```html
<div class="stack gap-4">
  <div>First item</div>
  <div>Second item</div>
  <div>Third item</div>
</div>
```

#### Cluster

Horizontal grouping with wrap and gap:

```html
<div class="cluster gap-2">
  <span class="badge">Tag 1</span>
  <span class="badge">Tag 2</span>
  <span class="badge">Tag 3</span>
</div>
```

#### Sidebar layout

Content area with a fixed-width sidebar:

```html
<div class="sidebar-layout">
  <aside class="sidebar-layout-sidebar">Navigation</aside>
  <main class="sidebar-layout-content">Main content</main>
</div>
```

### Navigation

#### Navbar

```html
<nav class="navbar">
  <div class="navbar-brand">
    <a href="/">Pulsar App</a>
  </div>
  <div class="navbar-menu">
    <a href="/dashboard" class="navbar-item active">Dashboard</a>
    <a href="/settings" class="navbar-item">Settings</a>
  </div>
  <div class="navbar-end">
    <a href="/profile" class="navbar-item">Profile</a>
  </div>
</nav>
```

#### Sidebar navigation

```html
<nav class="sidebar-nav">
  <div class="sidebar-nav-header">Admin</div>
  <a href="/dashboard" class="sidebar-nav-item active">Dashboard</a>
  <a href="/users" class="sidebar-nav-item">Users</a>
  <div class="sidebar-nav-divider"></div>
  <a href="/settings" class="sidebar-nav-item">Settings</a>
</nav>
```

#### Breadcrumbs

```html
<nav class="breadcrumbs" aria-label="Breadcrumb">
  <a href="/" class="breadcrumbs-item">Home</a>
  <a href="/users" class="breadcrumbs-item">Users</a>
  <span class="breadcrumbs-item active" aria-current="page">John Doe</span>
</nav>
```

#### Tabs

```html
<div class="tabs">
  <button class="tabs-item active">Overview</button>
  <button class="tabs-item">Details</button>
  <button class="tabs-item">History</button>
</div>
```

#### Pagination

```html
<nav class="pagination" aria-label="Pagination">
  <a href="?page=1" class="pagination-item" aria-label="Previous">&laquo;</a>
  <a href="?page=1" class="pagination-item">1</a>
  <a href="?page=2" class="pagination-item active" aria-current="page">2</a>
  <a href="?page=3" class="pagination-item">3</a>
  <a href="?page=3" class="pagination-item" aria-label="Next">&raquo;</a>
</nav>
```

#### Menu

```html
<ul class="menu">
  <li class="menu-item active">
    <a href="/dashboard">Dashboard</a>
  </li>
  <li class="menu-item">
    <a href="/reports">Reports</a>
  </li>
  <li class="menu-divider"></li>
  <li class="menu-item">
    <a href="/logout">Log Out</a>
  </li>
</ul>
```

### Forms

#### Input

```html
<input type="text" class="input" placeholder="Enter name" />
<input type="email" class="input input-sm" placeholder="small" />
<input type="text" class="input input-lg" placeholder="large" />
<input type="text" class="input input-error" placeholder="error state" />
<input type="text" class="input" disabled placeholder="disabled" />
```

#### Select

```html
<select class="select">
  <option>Choose...</option>
  <option>Option A</option>
  <option>Option B</option>
</select>
```

#### Checkbox and radio

```html
<label class="checkbox">
  <input type="checkbox" />
  <span class="checkbox-label">Accept terms</span>
</label>

<label class="radio">
  <input type="radio" name="plan" value="free" />
  <span class="radio-label">Free plan</span>
</label>
```

#### Toggle switch

```html
<label class="toggle">
  <input type="checkbox" />
  <span class="toggle-slider"></span>
  <span class="toggle-label">Dark mode</span>
</label>
```

#### File upload

```html
<label class="file-upload">
  <input type="file" />
  <span class="file-upload-label">Choose file...</span>
</label>
```

#### Form group

```html
<div class="form-group">
  <label class="form-label" for="email">Email</label>
  <input type="email" id="email" class="input" />
  <p class="form-hint">We will never share your email.</p>
</div>

<div class="form-group form-group-error">
  <label class="form-label" for="password">Password</label>
  <input type="password" id="password" class="input input-error" />
  <p class="form-error">Password must be at least 8 characters.</p>
</div>
```

### Data display

#### Table

```html
<div class="table-responsive">
  <table class="table table-striped table-hover">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Jane Doe</td>
        <td>jane@example.com</td>
        <td>Admin</td>
      </tr>
    </tbody>
  </table>
</div>
```

Variants: `.table-striped`, `.table-bordered`, `.table-hover`. Wrap in `.table-responsive` for horizontal scrolling on small screens.

#### Card

```html
<div class="card">
  <div class="card-header">Card Title</div>
  <div class="card-body">
    <p>Card content goes here.</p>
  </div>
  <div class="card-footer">
    <button class="btn btn-primary">Action</button>
  </div>
</div>
```

#### Badge

```html
<span class="badge">Default</span>
<span class="badge badge-primary">Primary</span>
<span class="badge badge-success">Success</span>
<span class="badge badge-warning">Warning</span>
<span class="badge badge-danger">Danger</span>
```

#### Tag

```html
<span class="tag">Default</span>
<span class="tag tag-primary">PHP</span>
<span class="tag tag-removable">
  Removable
  <button class="tag-remove" aria-label="Remove">&times;</button>
</span>
```

#### Stat

```html
<div class="stat">
  <div class="stat-label">Total Revenue</div>
  <div class="stat-value">$48,200</div>
  <div class="stat-change stat-change-positive">+12.5%</div>
</div>
```

### Feedback

#### Alert

```html
<div class="alert alert-info" role="alert">Informational message.</div>
<div class="alert alert-success" role="alert">Operation succeeded.</div>
<div class="alert alert-warning" role="alert">Please review carefully.</div>
<div class="alert alert-danger" role="alert">An error occurred.</div>
```

#### Toast

```html
<div class="toast toast-success" role="status" aria-live="polite">
  <div class="toast-body">Changes saved successfully.</div>
  <button class="toast-close" aria-label="Close">&times;</button>
</div>
```

#### Modal

```html
<div class="modal-backdrop" aria-hidden="true"></div>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="modal-header">
    <h2 id="modal-title" class="modal-title">Confirm Action</h2>
    <button class="modal-close" aria-label="Close">&times;</button>
  </div>
  <div class="modal-body">
    <p>Are you sure you want to proceed?</p>
  </div>
  <div class="modal-footer">
    <button class="btn">Cancel</button>
    <button class="btn btn-primary">Confirm</button>
  </div>
</div>
```

#### Dialog

Simpler confirmation dialog variant:

```html
<div class="dialog" role="alertdialog" aria-modal="true">
  <div class="dialog-body">
    <p>Delete this item?</p>
  </div>
  <div class="dialog-actions">
    <button class="btn">Cancel</button>
    <button class="btn btn-danger">Delete</button>
  </div>
</div>
```

#### Tooltip

```html
<span class="tooltip" data-tooltip="Helpful tip" aria-label="Helpful tip"> Hover me </span>
```

#### Progress

```html
<div class="progress" role="progressbar" aria-valuenow="65" aria-valuemin="0" aria-valuemax="100">
  <div class="progress-bar" style="width: 65%">65%</div>
</div>
```

### Actions

#### Button

```html
<button class="btn">Default</button>
<button class="btn btn-primary">Primary</button>
<button class="btn btn-secondary">Secondary</button>
<button class="btn btn-success">Success</button>
<button class="btn btn-warning">Warning</button>
<button class="btn btn-danger">Danger</button>
<button class="btn btn-outline">Outline</button>
<button class="btn btn-ghost">Ghost</button>
```

Sizes: `.btn-sm`, `.btn-lg`.

States: `disabled` attribute, `.btn-loading`.

#### Button group

```html
<div class="btn-group" role="group" aria-label="Actions">
  <button class="btn btn-primary">Save</button>
  <button class="btn">Cancel</button>
  <button class="btn btn-danger">Delete</button>
</div>
```

#### Dropdown

```html
<div class="dropdown">
  <button class="btn dropdown-toggle" aria-expanded="false">Options</button>
  <div class="dropdown-menu">
    <a href="#" class="dropdown-item">Edit</a>
    <a href="#" class="dropdown-item">Duplicate</a>
    <div class="dropdown-divider"></div>
    <a href="#" class="dropdown-item dropdown-item-danger">Delete</a>
  </div>
</div>
```

### Typography

#### Prose

The `.prose` class provides sensible defaults for long-form content:

```html
<div class="prose">
  <h1>Article Title</h1>
  <p>Long form content with proper spacing, line heights, and link styling.</p>
  <ul>
    <li>List items styled automatically</li>
  </ul>
  <blockquote>Blockquotes are styled too.</blockquote>
</div>
```

#### Code

```html
<code class="code">inline code</code>

<pre class="code-block">
  <code>function example(): string {
    return 'Hello, world!';
}</code>
</pre>
```

### Media

#### Avatar

```html
<span class="avatar avatar-sm">AB</span>
<img src="/photo.jpg" alt="User" class="avatar" />
<span class="avatar avatar-lg">JD</span>
```

Sizes: `.avatar-sm`, (default), `.avatar-lg`.

#### Figure

```html
<figure class="figure">
  <img src="/chart.png" alt="Revenue chart" class="figure-img" />
  <figcaption class="figure-caption">Fig. 1: Quarterly revenue</figcaption>
</figure>
```

### Miscellaneous

#### Accordion

```html
<div class="accordion">
  <details class="accordion-item">
    <summary class="accordion-header">Section 1</summary>
    <div class="accordion-body">Content for section 1.</div>
  </details>
  <details class="accordion-item">
    <summary class="accordion-header">Section 2</summary>
    <div class="accordion-body">Content for section 2.</div>
  </details>
</div>
```

#### Skeleton loader

```html
<div class="skeleton skeleton-text"></div>
<div class="skeleton skeleton-text" style="width: 80%"></div>
<div class="skeleton skeleton-circle"></div>
<div class="skeleton skeleton-rect" style="height: 200px"></div>
```

#### Empty state

```html
<div class="empty-state">
  <div class="empty-state-icon">&#128269;</div>
  <h3 class="empty-state-title">No results found</h3>
  <p class="empty-state-description">Try adjusting your search or filters.</p>
  <button class="btn btn-primary">Clear filters</button>
</div>
```

## Theming

### Dark/Light mode

Dark mode activates automatically via `prefers-color-scheme: dark`, or explicitly with the `data-theme` attribute or `.dark` class:

```html
<!-- Explicit dark mode -->
<html data-theme="dark">
  <!-- Explicit light mode (overrides system preference) -->
  <html data-theme="light">
    <!-- CSS class alternative -->
    <html class="dark"></html>
  </html>
</html>
```

All semantic color tokens and component tokens are remapped in dark mode. No markup changes are needed - the same HTML works in both themes.

### Custom themes

Custom themes override CSS custom properties. Create a file at `resources/themes/{name}.css`:

```css
/* Theme: Corporate Blue */
/* Version: 1.0 */
/* Based on: default */

:root {
  --color-primary-500: #1e40af;
  --color-primary-600: #1e3a8a;
  --color-primary-700: #172554;

  --color-bg: #fafbff;
  --color-bg-secondary: #f0f4ff;

  --btn-radius: var(--radius-lg);
  --card-radius: var(--radius-xl);
  --card-shadow: var(--shadow-md);
}
```

Set the active theme in `config/view.php`:

```php
'active_theme' => 'corporate-blue',
```

The default theme is always available as a baseline. Custom themes only need to override the tokens they change.

## RTL support

Pulsar UI uses CSS logical properties (`margin-inline`, `padding-inline`, `border-inline-start`, etc.) throughout. RTL layouts work automatically with the `dir` attribute:

```html
<html dir="rtl" lang="ar"></html>
```

Grid offsets use `margin-inline-start` instead of `margin-left`, so columns shift correctly in both directions.

## Print stylesheet

The `print.css` layer activates via `@media print` and:

- Removes navigation, sidebars, and interactive elements
- Sets text to black on white background
- Removes shadows and decorative borders
- Ensures tables and content break cleanly across pages

## Accessibility

All Pulsar UI components target WCAG 2.1 AA compliance:

- **Color contrast**: All text/background combinations meet minimum 4.5:1 ratio (3:1 for large text)
- **Focus indicators**: Visible focus rings via `--color-focus-ring` on all interactive elements
- **ARIA patterns**: Correct `role`, `aria-*` attributes on modals, dialogs, tabs, pagination, progress bars, and alerts
- **Screen reader support**: `.sr-only` utility for visually hidden but accessible content
- **Keyboard navigation**: All interactive components are keyboard-accessible
- **Reduced motion**: Animations respect `prefers-reduced-motion`

## Token versioning and governance

- Design tokens and component CSS APIs follow semantic versioning
- Breaking changes to component class names or token names require a major version bump
- All components reference tokens, never raw values
- Token changes are reviewed as part of the standard PR process
- The `pulsar build` step compiles tokens into a versioned artifact; CI verifies no drift between token definitions and CSS output
