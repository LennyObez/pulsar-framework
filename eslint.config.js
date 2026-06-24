import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import globals from 'globals';

export default tseslint.config(
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    ignores: [
      'vendor/**',
      'bootstrap/cache/**',
      '**/dist/**',
      'extensions/studio/frontend/dist/**',
      'coverage/**',
      'node_modules/**',
      'build/**',
      '*.config.js',
      '*.config.ts',
    ],
  },
  {
    files: ['**/*.ts'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
      },
    },
    rules: {
      '@typescript-eslint/no-unused-vars': [
        'error',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
        },
      ],
      'no-console': 'warn',
    },
  },
  {
    files: ['**/*.js', '**/*.mjs'],
    ignores: ['resources/**/*.js', 'extensions/**/resources/**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.node,
      },
    },
    rules: {
      'no-unused-vars': [
        'error',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
        },
      ],
      'no-console': 'warn',
    },
  },
  {
    files: ['resources/**/*.js', 'extensions/**/resources/**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'script',
      globals: {
        ...globals.browser,
        // UMD-style guarded exports (typeof module !== 'undefined' && module.exports)
        module: 'readonly',
        exports: 'readonly',
        // External libraries loaded via <script> tag from CDN
        Hls: 'readonly',
        pdfjsLib: 'readonly',
      },
    },
    rules: {
      // typescript-eslint variant fires on JS via the recommended preset and
      // ignores our caughtErrorsIgnorePattern; the native rule alone is enough
      // for plain JS resources.
      '@typescript-eslint/no-unused-vars': 'off',
      'no-unused-vars': [
        'error',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
        },
      ],
      'no-console': 'warn',
    },
  },
);
