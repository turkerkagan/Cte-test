import eslint from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['resources/js/legacy/**', 'public/**', 'vendor/**', 'node_modules/**'] },
    { ...eslint.configs.recommended, files: ['**/*.{ts,tsx}'] },
    ...tseslint.configs.recommended.map((config) => ({ ...config, files: ['**/*.{ts,tsx}'] })),
    {
        files: ['**/*.{ts,tsx}'],
        rules: {
            // TypeScript checks declarations and unused values with the project's actual types.
            'no-undef': 'off',
            'no-unused-vars': 'off',
            '@typescript-eslint/no-unused-vars': 'off',
            '@typescript-eslint/no-explicit-any': 'error',
        },
    },
    { files: ['tests/frontend/**/*.{ts,tsx}'], rules: { '@typescript-eslint/no-explicit-any': 'off' } },
);
