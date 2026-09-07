async (page) => {
  const base = 'http://127.0.0.1:18137';
  const artifacts = 'D:/Codex/tmp/erp-meli/calls-20260906/browser-config';
  const expect = (condition, message) => {
    if (!condition) throw new Error(message);
  };
  const body = async (target = page) => target.locator('body').innerText();
  const autoUrl = `${base}/settings/cron/rhythm`;
  const manualUrl = `${base}/settings/manual-processing`;
  const autoCurrent = target => target.getByRole('spinbutton', { name: 'Llamadas API por ciclo automático' });
  const autoCeiling = target => target.getByRole('spinbutton', { name: 'Techo permitido de llamadas API por ciclo' });
  const manualCurrent = target => target.getByRole('spinbutton', { name: 'Máximo de llamadas API por paso' });
  const manualCeiling = target => target.getByRole('spinbutton', { name: /^Techo permitido de llamadas API/ });
  const reviewAuto = target => target.getByRole('button', { name: 'Revisar y guardar presupuesto' });
  const reviewManual = target => target.getByRole('button', { name: 'Revisar y guardar capacidad' });
  const confirm = target => target.getByRole('button', { name: 'Confirmar', exact: true });
  const cancel = target => target.getByRole('button', { name: 'Cancelar', exact: true });
  const expectAutomaticStored = async (currentValue, ceilingValue) => {
    await page.goto(autoUrl);
    expect(await autoCurrent(page).inputValue() === currentValue, `automatic current mutated; expected ${currentValue}`);
    expect(await autoCeiling(page).inputValue() === ceilingValue, `automatic ceiling mutated; expected ${ceilingValue}`);
  };
  const expectManualStored = async (currentValue, ceilingValue) => {
    await page.goto(manualUrl);
    expect(await manualCurrent(page).inputValue() === currentValue, `manual current mutated; expected ${currentValue}`);
    expect(await manualCeiling(page).inputValue() === ceilingValue, `manual ceiling mutated; expected ${ceilingValue}`);
  };
  const reset = async (target = page, query = 'session=admin&health=healthy') => {
    const response = await target.goto(`${base}/__fixture/reset?${query}`);
    expect(response && response.ok(), `fixture reset failed: ${response && response.status()}`);
  };
  const serverInvalid = async (target, locator, value, reviewButton, expected) => {
    await locator(target).evaluate((element, next) => {
      element.type = 'text';
      element.value = next;
    }, value);
    await target.locator('form').filter({ has: reviewButton(target) }).evaluate(form => {
      form.noValidate = true;
      form.requestSubmit();
    });
    await target.waitForLoadState('domcontentloaded');
    expect((await body(target)).includes(expected), `invalid ${value} not rejected: ${await body(target)}`);
  };

  await reset();
  await page.goto(autoUrl);
  expect(await autoCurrent(page).inputValue() === '1', 'automatic current baseline is not 1');
  expect(await autoCeiling(page).inputValue() === '55', 'automatic ceiling baseline is not 55');

  await autoCeiling(page).fill('100');
  await autoCurrent(page).fill('2');
  await reviewAuto(page).click();
  expect((await body()).includes('55 → 100'), 'automatic ceiling review lacks before/after');
  await cancel(page).click();
  expect(await autoCeiling(page).inputValue() === '55', 'automatic cancel mutated ceiling');
  expect(await autoCurrent(page).inputValue() === '1', 'automatic cancel mutated current');

  await autoCeiling(page).fill('100');
  await reviewAuto(page).click();
  await confirm(page).click();
  await page.reload();
  expect(await autoCeiling(page).inputValue() === '100', 'automatic ceiling did not persist');
  expect(await autoCurrent(page).inputValue() === '1', 'ceiling-only automatic save changed current');

  await autoCurrent(page).fill('3');
  await reviewAuto(page).click();
  await confirm(page).click();
  await page.reload();
  expect(await autoCurrent(page).inputValue() === '3', 'automatic current did not persist');

  const staleTab = await page.context().newPage();
  await staleTab.goto(autoUrl);
  await autoCurrent(page).fill('2');
  await reviewAuto(page).click();
  await autoCurrent(staleTab).fill('1');
  await reviewAuto(staleTab).click();
  await confirm(staleTab).click();
  await confirm(page).click();
  expect(/capacidad cambió|confirmación venció/i.test(await body()), 'stale automatic tab was not rejected');
  await page.goto(autoUrl);
  expect(await autoCurrent(page).inputValue() === '1', 'stale automatic tab overwrote newer value');
  await staleTab.close();

  await page.goto(`${base}/__fixture/health?state=429`);
  await page.goto(autoUrl);
  await autoCurrent(page).fill('55');
  await reviewAuto(page).click();
  await confirm(page).click();
  expect(await autoCurrent(page).inputValue() === '1', 'remote 429 allowed automatic current increase');
  expect(/salud global completa/i.test(await body()), 'remote 429 denial is not visible');
  await autoCeiling(page).fill('90');
  await reviewAuto(page).click();
  await confirm(page).click();
  expect(await autoCeiling(page).inputValue() === '90', 'remote 429 blocked ceiling-only save');

  await page.goto(`${base}/__fixture/health?state=unknown`);
  await page.goto(autoUrl);
  await autoCurrent(page).fill('2');
  await reviewAuto(page).click();
  await confirm(page).click();
  expect(await autoCurrent(page).inputValue() === '1', 'unknown health allowed automatic current increase');
  await autoCeiling(page).fill('80');
  await reviewAuto(page).click();
  await confirm(page).click();
  expect(await autoCeiling(page).inputValue() === '80', 'unknown health blocked ceiling-only save');

  await page.goto(autoUrl);
  await serverInvalid(page, autoCurrent, '0', reviewAuto, 'entre 1 y 100');
  await expectAutomaticStored('1', '80');
  await serverInvalid(page, autoCurrent, '101', reviewAuto, 'entre 1 y 100');
  await expectAutomaticStored('1', '80');
  await serverInvalid(page, autoCurrent, '1.5', reviewAuto, 'entero');
  await expectAutomaticStored('1', '80');
  await serverInvalid(page, autoCurrent, 'abc', reviewAuto, 'entero');
  await expectAutomaticStored('1', '80');

  await page.goto(`${base}/__fixture/health?state=healthy`);
  await page.goto(manualUrl);
  expect(await manualCurrent(page).inputValue() === '1', 'manual current baseline is not 1');
  expect(await manualCeiling(page).inputValue() === '55', 'manual ceiling baseline is not 55');
  await manualCeiling(page).fill('100');
  await manualCurrent(page).fill('20');
  await reviewManual(page).click();
  await cancel(page).click();
  expect(await manualCeiling(page).inputValue() === '55', 'manual cancel mutated ceiling');
  expect(await manualCurrent(page).inputValue() === '1', 'manual cancel mutated current');
  await manualCeiling(page).fill('100');
  await reviewManual(page).click();
  await confirm(page).click();
  await page.reload();
  expect(await manualCeiling(page).inputValue() === '100', 'manual ceiling did not persist');
  expect(await manualCurrent(page).inputValue() === '1', 'ceiling-only manual save changed current');
  await manualCurrent(page).fill('20');
  await reviewManual(page).click();
  await confirm(page).click();
  await page.reload();
  expect(await manualCurrent(page).inputValue() === '20', 'manual current did not persist');
  await manualCeiling(page).fill('10');
  await reviewManual(page).click();
  expect((await body()).includes('actual no puede superar'), 'manual current-above-ceiling pair was not rejected');
  await page.goto(manualUrl);
  expect(await manualCurrent(page).inputValue() === '20' && await manualCeiling(page).inputValue() === '100', 'invalid manual pair mutated storage');
  await serverInvalid(page, manualCurrent, '0', reviewManual, 'entre 1 y 100');
  await expectManualStored('20', '100');
  await serverInvalid(page, manualCurrent, '1.5', reviewManual, 'entero');
  await expectManualStored('20', '100');
  await serverInvalid(page, manualCurrent, 'abc', reviewManual, 'entero');
  await expectManualStored('20', '100');

  const csrf = await page.context().newPage();
  await csrf.goto(autoUrl);
  await csrf.locator('form#call-budget-form input[name="_token"]').evaluate(element => { element.value = 'invalid-csrf'; });
  await reviewAuto(csrf).click();
  expect(/sesión de seguridad venció/i.test(await body(csrf)), 'invalid CSRF was not rejected');
  await csrf.close();

  await page.goto(`${base}/__fixture/session?kind=temporary`);
  const temporary = await page.goto(autoUrl);
  expect(temporary && temporary.status() === 403, 'temporary administrator reached settings');
  expect((await body()).includes('accesos temporales'), 'temporary denial is not explicit');
  await page.goto(`${base}/__fixture/session?kind=anonymous`);
  await page.goto(autoUrl);
  expect(page.url().endsWith('/login'), `anonymous user was not redirected to login: ${page.url()}`);

  await reset();
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(autoUrl);
  await page.screenshot({ path: `${artifacts}/automatic-desktop.png`, fullPage: true });
  await page.goto(manualUrl);
  await page.screenshot({ path: `${artifacts}/manual-desktop.png`, fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(autoUrl);
  await page.screenshot({ path: `${artifacts}/automatic-mobile.png`, fullPage: true });
  await page.goto(manualUrl);
  await page.screenshot({ path: `${artifacts}/manual-mobile.png`, fullPage: true });

  return 'CALLS_BROWSER_CONFIG_PASS automatic/manual ceiling+current save/cancel/reload; invalid integer/range/pair; two-tab conflict; 429/unknown increase denied with ceiling-only allowed; anonymous/temporary/CSRF; desktop/mobile';
}
