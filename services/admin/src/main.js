/**
 * Pulsar Admin Console — bootstrap entry point.
 *
 * Loads design-system tokens (linked from index.html) and registers Web Components.
 * Each component file self-registers via customElements.define() on import.
 */

import './lib/signals.js';
import './lib/router.js';
import './lib/api-client.js';

import './components/admin-shell.js';
