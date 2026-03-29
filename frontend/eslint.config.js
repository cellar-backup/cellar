import pluginVue from "eslint-plugin-vue";
import tsPlugin from "@typescript-eslint/eslint-plugin";
import tsParser from "@typescript-eslint/parser";

export default [
  {
    ignores: ["dist/**", "node_modules/**"],
  },
  ...pluginVue.configs["flat/recommended"],
  {
    files: ["**/*.ts", "**/*.tsx"],
    plugins: {
      "@typescript-eslint": tsPlugin,
    },
    languageOptions: {
      parser: tsParser,
      parserOptions: {
        ecmaVersion: "latest",
        sourceType: "module",
      },
    },
    rules: {
      ...tsPlugin.configs.recommended.rules,
    },
  },
  {
    files: ["**/*.vue"],
    plugins: {
      "@typescript-eslint": tsPlugin,
    },
    languageOptions: {
      parserOptions: {
        parser: tsParser,
      },
    },
    rules: {
      ...tsPlugin.configs.recommended.rules,
    },
  },
  {
    rules: {
      "vue/multi-word-component-names": "off",
    },
  },
];
