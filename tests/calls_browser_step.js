async (page) => {
  const base = 'http://127.0.0.1:18139';
  const artifacts = 'D:/Codex/tmp/erp-meli/calls-20260906/browser-step';
  const blockedHosts = [];
  const expect = (condition, message) => {
    if (!condition) throw new Error(message);
  };
  const body = async () => page.locator('body').innerText();

  await page.context().route('**/*', async route => {
    const requestUrl = route.request().url();
    if (requestUrl === base || requestUrl.startsWith(`${base}/`)) {
      await route.continue();
      return;
    }
    blockedHosts.push(requestUrl.replace(/([?#]).*$/, '$1<redacted>'));
    await route.abort('blockedbyclient');
  });

  const control = async path => page.evaluate(async target => {
    const response = await fetch(target, { credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok) throw new Error(`control ${target} returned ${response.status}`);
    return response.json();
  }, path);
  const wireCount = async () => Number((await control('/__fixture/wire-count')).count);
  const state = async token => control(`/__fixture/state?token=${encodeURIComponent(token)}`);
  const resultMetric = async label => {
    const card = page.locator('#resultado-proceso article').filter({ has: page.locator('span', { hasText: label }) });
    expect(await card.count() === 1, `missing result metric: ${label}`);
    return (await card.locator('strong').innerText()).trim();
  };
  const expectResult = async expected => {
    await page.locator('#resultado-proceso').waitFor({ state: 'visible' });
    for (const [label, value] of Object.entries(expected)) {
      expect(await resultMetric(label) === String(value), `${label} expected ${value}, got ${await resultMetric(label)}`);
    }
    expect((await body()).includes('No quedó continuación manual en segundo plano'), 'result does not disclose bounded request completion');
  };
  const preview = async (accountId, expectedRows) => {
    await page.goto(`${base}/settings/manual-processing?scope=recommended&account_id=${accountId}`);
    await page.getByRole('radio', { name: /Recomendado/ }).check();
    expect(await page.locator('input[name="block_size"]').count() === 0, 'resource capacity input returned');
    await page.getByRole('button', { name: 'PREVISUALIZAR' }).click();
    await page.locator('#resultado-calculo').waitFor({ state: 'visible' });
    const rows = page.locator('#resultado-calculo tbody tr');
    expect(await rows.count() === expectedRows, `account ${accountId} preview expected ${expectedRows} rows, got ${await rows.count()}`);
    const token = await page.locator('form.manual-confirm-form input[name="preview_token"]').inputValue();
    expect(/^[a-f0-9]{40}$/.test(token), `account ${accountId} did not receive an actual preview token`);
    const post = await page.locator('form.manual-confirm-form').evaluate(form => new URLSearchParams(new FormData(form)).toString());
    return { token, post };
  };
  const replay = async post => page.evaluate(async payload => {
    const response = await fetch('/settings/manual-processing/start', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload,
    });
    return { status: response.status, url: response.url, text: await response.text() };
  }, post);

  const session = await page.goto(`${base}/__fixture/session`);
  expect(session && session.ok(), 'local authenticated session setup failed');
  const meta = await page.goto(`${base}/__fixture/meta`);
  expect(meta && meta.ok(), 'local fixture metadata is unavailable');

  await page.setViewportSize({ width: 1440, height: 1000 });
  let before = await wireCount();
  const normal = await preview(9111, 2);
  await page.getByRole('button', { name: 'PROCESAR SELECCIÓN' }).click();
  await expectResult({
    'Elementos atendidos': 2,
    'Elementos completados': 1,
    'Elementos esperando': 1,
    'Elementos para revisión': 0,
    'Sin iniciar': 0,
  });
  expect(await wireCount() === before + 2, 'normal checkpoint selection did not use exactly two physical calls');
  const normalState = await state(normal.token);
  expect(normalState.preview_status === 'consumed', 'normal preview was not consumed');
  expect(normalState.chunks.length === 1 && Number(normalState.chunks[0].cursor_offset) === 1, 'normal 200 checkpoint was not durably saved');
  await page.screenshot({ path: `${artifacts}/normal-checkpoint-desktop.png`, fullPage: true });

  await page.setViewportSize({ width: 1440, height: 1000 });
  before = await wireCount();
  const failure = await preview(9114, 2);
  await control('/__fixture/failure-on');
  await page.getByRole('button', { name: 'PROCESAR SELECCIÓN' }).click();
  expect(/No fue posible procesar el trabajo exacto|Código de diagnóstico/i.test(await body()), 'checkpoint failure was not presented safely');
  expect(await wireCount() === before + 1, 'post-checkpoint failure used a physical call after the durable first checkpoint');
  const failedState = await state(failure.token);
  expect(failedState.preview_status === 'consumed', 'post-checkpoint failure left the old preview reusable');
  expect(Number(failedState.failure_chunks.first_chunk_id.cursor_offset) === 1, 'post-checkpoint failure lost the first durable cursor');
  const failedSecond = failedState.notifications.find(row => String(row.resource_id) === '93402');
  expect(failedSecond && failedSecond.status !== 'complete', 'post-checkpoint failure fabricated the unprocessed second resource as complete');
  await page.screenshot({ path: `${artifacts}/post-checkpoint-failure-desktop.png`, fullPage: true });
  const failureBeforeReplay = await wireCount();
  const failureReplay = await replay(failure.post);
  expect(failureReplay.status === 200 && /venció|ya se utilizó|Vuelva a calcular|No fue posible procesar el trabajo exacto|Código de diagnóstico/i.test(failureReplay.text), 'old failed preview replay did not visibly reject');
  expect(await wireCount() === failureBeforeReplay, 'old failed preview replay reached the physical wire');

  await control('/__fixture/prepare-continuation');
  const continuation = await preview(9114, 2, 2);
  expect(continuation.token !== failure.token, 'continuation did not require an actual new preview');
  expect(await page.locator('form.manual-confirm-form input[name="process_limit"]').count() === 0, 'removed resource control returned');
  expect(await page.locator('form.manual-confirm-form input[name="physical_api_call_budget"]').inputValue() === '3', 'new preview lost the saved physical budget');
  before = await wireCount();
  await page.getByRole('button', { name: 'PROCESAR SELECCIÓN' }).click();
  await expectResult({
    'Elementos atendidos': 2,
    'Elementos completados': 2,
    'Elementos esperando': 0,
    'Elementos para revisión': 0,
    'Sin iniciar': 0,
  });
  expect(await wireCount() === before + 2, 'explicit continuation did not use exactly two physical calls');
  const continuedState = await state(continuation.token);
  expect(continuedState.preview_status === 'consumed', 'new continuation preview was not consumed');
  expect(Number(continuedState.failure_chunks.first_chunk_id.cursor_offset) === 2
    && continuedState.failure_chunks.first_chunk_id.status === 'complete', 'new preview did not continue the saved cursor to completion');
  const continuedSecond = continuedState.notifications.find(row => String(row.resource_id) === '93402');
  expect(continuedSecond && continuedSecond.status === 'complete', 'second selected resource was not truthfully completed through its own wire call');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${artifacts}/continuation-result-mobile.png`, fullPage: true });

  // Keep the deliberate shared 429 last: its real global pause must not be
  // reset merely to let an unrelated browser scenario continue afterward.
  before = await wireCount();
  const protected429 = await preview(9113, 2);
  await page.getByRole('button', { name: 'PROCESAR SELECCIÓN' }).click();
  await expectResult({
    'Elementos atendidos': 1,
    'Elementos completados': 0,
    'Elementos esperando': 0,
    'Elementos para revisión': 1,
    'Sin iniciar': 1,
  });
  expect(await wireCount() === before + 1, 'protected 429 did not stop before the second exact resource');
  const protectedState = await state(protected429.token);
  expect(protectedState.preview_status === 'consumed', 'protected preview was not terminal');
  const untouched = protectedState.notifications.find(row => String(row.resource_id) === '93302');
  expect(untouched && untouched.status !== 'complete', 'resource after protected 429 was processed');
  await page.screenshot({ path: `${artifacts}/protected-429-mobile.png`, fullPage: true });
  const protectedBeforeReplay = await wireCount();
  const protectedReplay = await replay(protected429.post);
  expect(protectedReplay.status === 200 && /venció|ya se utilizó|Vuelva a calcular|No fue posible procesar el trabajo exacto|Código de diagnóstico/i.test(protectedReplay.text), 'old protected preview replay did not visibly reject');
  expect(await wireCount() === protectedBeforeReplay, 'old protected preview replay reached the physical wire');

  expect(blockedHosts.length === 0, `full-shell browser attempted non-local network: ${[...new Set(blockedHosts)].join(',')}`);
  return 'CALLS_BROWSER_STEP_PASS actual UI previews; normal 200 checkpoint; protected 429 truthful stop; post-checkpoint failure; old POST zero-wire replay; explicit new preview continuation; desktop/mobile; LOCAL_NETWORK_ONLY=PASS';
}
