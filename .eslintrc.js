module.exports = {
	extends: [ '@10up/eslint-config' ],
	globals: {
		wp: 'readonly',
	},
	settings: {
		// Treat `@wordpress/*` packages as built-in modules — they are
		// provided by WordPress at runtime (externalized by Webpack) and
		// are not in node_modules, so import/no-unresolved would otherwise
		// fail on every import.
		'import/core-modules': [
			'@wordpress/api-fetch',
			'@wordpress/components',
			'@wordpress/core-data',
			'@wordpress/data',
			'@wordpress/editor',
			'@wordpress/element',
			'@wordpress/i18n',
			'@wordpress/plugins',
		],
	},
	rules: {
		// Match the codebase's WordPress-style paren spacing (`fn( arg )`).
		// @10up/eslint-config sets `parenSpacing: false`; with the `prettier`
		// package overridden to `wp-prettier` (see package.json overrides),
		// re-specifying the option here flips it on. Other Prettier options
		// mirror the @10up defaults.
		'prettier/prettier': [
			2,
			{
				useTabs: true,
				tabWidth: 4,
				printWidth: 100,
				singleQuote: true,
				trailingComma: 'all',
				bracketSpacing: true,
				parenSpacing: true,
				bracketSameLine: false,
				semi: true,
				arrowParens: 'always',
			},
			{
				usePrettierrc: false,
			},
		],

		// Removed in eslint-plugin-jsdoc v46 (we pin v46 via package.json
		// `overrides` because v31 — bundled with @10up/scripts@1.3.4 — crashes
		// on Node 20+). The @10up config still tries to enable this removed
		// rule, so we silence it here.
		'jsdoc/newline-after-description': 'off',

		// Allow hoisted function references — codebase relies on hoisting for
		// helper functions declared after their first call site.
		'no-use-before-define': [ 'error', { functions: false, classes: true, variables: true } ],
	},
	overrides: [
		{
			// Editor file uses JSX (WordPress block editor) and React hooks.
			files: [ 'assets/js/editor/**/*.js' ],
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
			plugins: [ 'react', 'react-hooks' ],
			rules: {
				// Mark variables referenced in JSX as used so no-unused-vars
				// doesn't flag imported components like <Button>.
				'react/jsx-uses-vars': 'error',
				'react/jsx-uses-react': 'off',
				// Real check on hook dependency arrays. Inline disable comments
				// at specific call sites suppress intentional omissions.
				'react-hooks/exhaustive-deps': 'warn',
			},
		},
	],
};
