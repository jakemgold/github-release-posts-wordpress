const defaultConfig = require( '@10up/scripts/config/webpack.config' );

// Remove ESLint and Stylelint plugins so the build doesn't fail on lint errors.
defaultConfig.plugins = defaultConfig.plugins.filter(
	( plugin ) =>
		plugin.constructor.name !== 'ESLintWebpackPlugin' &&
		plugin.constructor.name !== 'StylelintWebpackPlugin'
);

module.exports = defaultConfig;
