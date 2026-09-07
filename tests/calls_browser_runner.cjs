#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const Module = require('module');

async function main() {
  const scriptPath = process.argv[2];
  if (!scriptPath || !fs.existsSync(scriptPath)) {
    throw new Error('browser_script_missing');
  }

  const toolingPackage = [
    process.env.CALLS_PLAYWRIGHT_PACKAGE_JSON,
    path.resolve(process.cwd(), '.tooling/playwright-runner/package.json'),
    path.resolve(process.cwd(), '../..', '.tooling/playwright-runner/package.json'),
    'C:/codex/ERP-BDM/.tooling/playwright-runner/package.json',
  ].filter(Boolean).find(candidate => fs.existsSync(candidate));
  if (!toolingPackage) {
    throw new Error('calls_playwright_tooling_missing');
  }
  const req = Module.createRequire(toolingPackage);
  const { chromium } = req('playwright');
  const source = fs.readFileSync(scriptPath, 'utf8');
  const scenario = Function(`"use strict"; return (${source});`)();
  if (typeof scenario !== 'function') {
    throw new Error('browser_script_must_export_async_page_function');
  }

  const outputRoot = process.env.CALLS_BROWSER_OUTPUT_ROOT || path.dirname(scriptPath);
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();
  try {
    const result = await scenario(page, { outputRoot });
    if (result !== undefined) {
      console.log(String(result));
    }
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch(error => {
  console.error(error && error.stack ? error.stack : String(error));
  process.exit(1);
});
