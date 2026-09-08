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
  fs.mkdirSync(outputRoot, { recursive: true });
  const baseUrl = process.env.CALLS_BROWSER_BASE_URL || 'http://127.0.0.1:18145';
  const logJson = (name, payload) => {
    const event = Object.assign({
      utc: new Date().toISOString(),
      monotonic_ms: Math.round(performance.now()),
    }, payload || {});
    fs.appendFileSync(path.join(outputRoot, name), `${JSON.stringify(event)}\n`);
  };
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();
  page.on('console', message => logJson('console.log', {
    type: message.type(),
    text: message.text(),
  }));
  page.on('pageerror', error => logJson('page-errors.log', {
    message: error && error.message ? error.message : String(error),
    stack: error && error.stack ? error.stack : '',
  }));
  try {
    const result = await scenario(page, { outputRoot, baseUrl, logJson });
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
