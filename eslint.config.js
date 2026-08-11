import js from '@eslint/js';
import globals from 'globals';

export default [
    {
        ignores: ['coverage/**', 'node_modules/**', 'vendor/**'],
    },
    js.configs.recommended,
    {
        files: ['js/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'script',
            globals: {
                ...globals.browser,
                OC: 'readonly',
            },
        },
    },
    {
        files: ['tests/**/*.js', '*.config.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.node,
            },
        },
    },
];
