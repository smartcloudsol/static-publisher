const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const path = require("path");
const webpack = require("webpack");

module.exports = function () {
    const isPremium = process.env.WPSUITE_PREMIUM === "true";
    console.log("PREMIUM BUILD:", isPremium);

    return {
        ...defaultConfig,
        entry: {
            index: [path.resolve(process.cwd(), "src", "index.tsx")],
        },
        externals: {
            ...defaultConfig.externals,
            "@mantine/core": "WpSuiteMantine",
            "@mantine/hooks": "WpSuiteMantine",
            "@mantine/notifications": "WpSuiteMantine",
            "crypto": "WpSuiteCrypto",
            "jose": "WpSuiteJose",
        },
        plugins: [
            ...defaultConfig.plugins,
            new webpack.DefinePlugin({
                __WPSUITE_PREMIUM__: JSON.stringify(isPremium),
            }),
        ],
    };
};
