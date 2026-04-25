/**
 * WebdriverIO configuration for Pulsar Admin E2E tests (Decision 2.47).
 *
 * Placeholder configuration. Sprint 3.1 fills in the test specs and reporters.
 */
export const config = {
  runner: 'local',
  specs: ['./specs/**/*.spec.ts'],
  capabilities: [
    { browserName: 'chromium' },
    { browserName: 'firefox' },
    { browserName: 'webkit' },
  ],
  framework: 'mocha',
  reporters: ['spec'],
} as const;
