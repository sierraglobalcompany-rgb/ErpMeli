async (page, { outputRoot, baseUrl, logJson }) => {
  const base = baseUrl || 'http://127.0.0.1:18145';
  const root = outputRoot;
  const expect = (ok, message) => { if (!ok) throw new Error(message); };
  const mark = (stage, data = {}) => logJson?.('browser-events.jsonl', Object.assign({stage}, data));
  const dom = async (stage) => {
    const state = await page.evaluate(() => {
      const form = document.querySelector('[data-qv4-action="readiness"]');
      const button = form?.querySelector('button');
      const feedback = document.querySelector('[data-qv4-feedback]');
      return {
        button_disabled: Boolean(button?.disabled),
        button_text: button?.textContent || '',
        feedback: feedback?.textContent || '',
        scroll_width: document.documentElement.scrollWidth,
        inner_width: window.innerWidth,
      };
    }).catch(error => ({error: String(error)}));
    logJson?.('dom-state.jsonl', Object.assign({stage}, state));
  };
  const control = path => page.evaluate(async target => {
    const response = await fetch(target, {credentials:'same-origin',cache:'no-store'});
    if (!response.ok) throw new Error(`Local endpoint status ${response.status}`);
    return response.json();
  }, path);
  const state = () => control('/settings/cron/queue-v4.json');
  let wireBaseline = 0;
  const wire = async () => Number((await control('/__fixture/wire-count')).count) - wireBaseline;
  const form = key => page.locator(`[data-qv4-action="${key}"]`);
  const button = key => form(key).locator('button');
  const describeReadinessBlocker = (key, plan) => {
    const snap = plan.snapshot || {};
    const issues = Array.isArray(snap.issues) ? snap.issues : [];
    const currentState = snap.state || 'UNKNOWN';
    const nextStep = Number(snap.next_step);
    if (plan.action === 'prepare' && (!['READY_TO_TEST', 'FAILED'].includes(currentState) || issues.length > 0)) {
      return `${key} terminal NOT_READY: state=${currentState} engine=${snap.engine || 'UNKNOWN'} issues=${issues.join('|') || 'none'}`;
    }
    if (plan.action === 'check' && (currentState !== 'TESTING' || !Number.isInteger(nextStep) || nextStep < 1 || nextStep > 3)) {
      return `${key} terminal check unavailable: state=${currentState} next_step=${snap.next_step ?? 'null'}`;
    }
    if (key === 'activate' && (currentState !== 'CERTIFIED' || !['CERTIFIED', 'STOPPED'].includes(snap.engine) || issues.length > 0)) {
      return `${key} terminal activate unavailable: state=${currentState} engine=${snap.engine || 'UNKNOWN'} issues=${issues.join('|') || 'none'}`;
    }
    if (key === 'cancel' && currentState !== 'TESTING' && !Number(snap.active_run_id || 0)) {
      return `${key} terminal cancel unavailable: state=${currentState}`;
    }
    return '';
  };
  const consoleErrors = [];
  const pageErrors = [];
  const unexpectedHttp = [];
  page.on('console', message => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('pageerror', error => pageErrors.push(error && error.message ? error.message : String(error)));
  page.on('response', response => {
    const url = new URL(response.url());
    const status = response.status();
    if (status >= 400) {
      unexpectedHttp.push({path:url.pathname, status, method:response.request().method()});
    }
  });
  const fieldFromPost = (data, key) => data.match(new RegExp(`name="${key}"\\r?\\n\\r?\\n([^\\r\\n]*)`))?.[1] || new URLSearchParams(data).get(key);
  const submitPlan = async key => {
    const current = await state();
    const action = key === 'readiness' ? (current.state === 'TESTING' ? 'check' : 'prepare') : key === 'cancel' ? 'cancel' : null;
    const path = key === 'activate' ? '/settings/cron/queue-v4/activate'
      : key === 'stop' ? '/settings/cron/queue-v4/stop'
        : '/settings/cron/queue-v4/readiness';
    return {path, action, step: action === 'check' ? String(current.next_step) : null, snapshot: current};
  };
  const waitActionReady = async (key, plan) => {
    await form(key).waitFor({state:'visible', timeout:10000});
    const terminalBlocker = describeReadinessBlocker(key, plan);
    if (terminalBlocker) {
      await dom(`terminal-not-ready-${key}`);
      throw new Error(terminalBlocker);
    }
    try {
      await page.waitForFunction(({key}) => {
        const form = document.querySelector(`[data-qv4-action="${key}"]`);
        const btn = form?.querySelector('button');
        const feedback = document.querySelector('[data-qv4-feedback]')?.textContent || '';
        return Boolean(btn) && !btn.disabled && btn.textContent.trim() !== 'Procesando…'
          && !/Cargando estado sin mutaciones/i.test(feedback);
      }, {key}, {timeout:10000});
    } catch (error) {
      await dom(`not-ready-${key}`);
      throw new Error(`${key} was not ready for submit: state=${plan.snapshot?.state || 'UNKNOWN'} engine=${plan.snapshot?.engine || 'UNKNOWN'} issues=${(plan.snapshot?.issues || []).join('|') || 'none'}`);
    }
  };
  const requestMatchesPlan = (request, plan) => {
    if (request.method() !== 'POST') return false;
    const url = new URL(request.url());
    if (url.pathname !== plan.path) return false;
    const data = request.postData() || '';
    const action = fieldFromPost(data, 'action');
    const step = fieldFromPost(data, 'step_no');
    if (plan.action !== null && action !== plan.action) return false;
    if (plan.action === null && action !== null && action !== '') return false;
    if (plan.step !== null && step !== plan.step) return false;
    return true;
  };
  const waitForSubmitRequest = plan => page.waitForRequest(request => requestMatchesPlan(request, plan), {timeout:10000});
  const responseForRequest = async request => {
    const timeout = new Promise((_, reject) => setTimeout(() => reject(new Error('planned response timeout')), 10000));
    const response = await Promise.race([request.response(), timeout]);
    expect(Boolean(response), 'planned request completed without response');
    return response;
  };
  const posts = [];
  page.on('request', request => {
    if (request.method() === 'POST' && request.url().includes('/settings/')) {
      const data = request.postData() || '';
      const record = {path:new URL(request.url()).pathname,action:fieldFromPost(data, 'action'),step:fieldFromPost(data, 'step_no')};
      posts.push(record);
      console.log('READINESS_POST', JSON.stringify(record));
      logJson?.('network.jsonl', Object.assign({stage:'request', url:request.url(), method:request.method()}, record));
    }
  });
  page.on('response', async response => {
    if (response.request().method() === 'POST' && response.url().includes('/settings/cron/queue-v4/')) {
      const data = await response.json().catch(() => ({}));
      console.log('READINESS_RESPONSE', JSON.stringify({path:new URL(response.url()).pathname,status:response.status(),
        ok:data.ok,state:data.state,next_step:data.next_step,calls:data.physical_http_calls,replayed:data.replayed}));
      logJson?.('network.jsonl', {stage:'response', url:response.url(), method:response.request().method(),
        status:response.status(), ok:data.ok, state:data.state, next_step:data.next_step,
        calls:data.physical_http_calls, replayed:data.replayed});
    }
  });
  const submit = async key => {
    const plan = await submitPlan(key);
    await waitActionReady(key, plan);
    await dom(`before-${key}`);
    mark('submit-attempt', {key, path:plan.path, action:plan.action, step:plan.step});
    const [request] = await Promise.all([
      waitForSubmitRequest(plan),
      button(key).click({timeout:10000})
    ]);
    mark('submit-request', {key, url:request.url(), method:request.method(), post_action:fieldFromPost(request.postData() || '', 'action'), post_step:fieldFromPost(request.postData() || '', 'step_no')});
    const result = await responseForRequest(request);
    mark('submit-response', {key, url:result.url(), status:result.status()});
    const data = await result.json();
    mark('submit-json', {key, ok:data.ok, state:data.state, next_step:data.next_step, calls:data.physical_http_calls});
    expect(result.ok() && data.ok !== false, `${key} rejected (${result.status()})`);
    await dom(`after-${key}`);
    return data;
  };
  const open = async () => {
    mark('open-start');
    await page.goto(`${base}/settings/cron`);
    await page.locator('[data-qv4-feedback]').filter({hasText: /Cargando/}).waitFor({state:'hidden'}).catch(() => {});
    await page.locator('.cron-admin-actions > summary').click();
    await dom('open-complete');
  };
  const settleMobile = async (stage) => {
    const openBefore = await page.evaluate(() => Boolean(document.querySelector('.sidebar')?.classList.contains('open')));
    if (openBefore) {
      const viewport = page.viewportSize() || {width:390, height:844};
      await page.mouse.click(Math.max(260, viewport.width - 20), 20);
    }
    await page.keyboard.press('Home');
    await page.waitForFunction(() => window.scrollY === 0, null, {timeout:5000});
    await page.waitForFunction(() => {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.querySelector('.sidebar-overlay');
      const rect = sidebar ? sidebar.getBoundingClientRect() : null;
      return document.documentElement.scrollWidth <= window.innerWidth
        && (!sidebar || (!/\bopen\b/.test(sidebar.className) && rect.right <= 0))
        && (!overlay || !/\bopen\b/.test(overlay.className));
    }, null, {timeout:5000});
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const metrics = await page.evaluate(stage => {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.querySelector('.sidebar-overlay');
      const sidebarRect = sidebar ? sidebar.getBoundingClientRect() : {left:null,right:null,width:null};
      const style = sidebar ? getComputedStyle(sidebar) : null;
      return {
        stage,
        innerWidth: window.innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
        scrollY: window.scrollY,
        sidebarClass: sidebar?.className || '',
        sidebarTransform: style?.transform || '',
        sidebarLeft: sidebarRect.left,
        sidebarRight: sidebarRect.right,
        sidebarWidth: sidebarRect.width,
        overlayClass: overlay?.className || '',
      };
    }, stage);
    logJson?.('mobile-state.jsonl', metrics);
    expect(metrics.scrollWidth <= metrics.innerWidth, `${stage} mobile horizontal overflow`);
    expect(!/\bopen\b/.test(metrics.sidebarClass), `${stage} sidebar class open`);
    expect(metrics.sidebarRight === null || metrics.sidebarRight <= 0, `${stage} sidebar visible in mobile capture`);
    expect(!/\bopen\b/.test(metrics.overlayClass), `${stage} overlay class open`);
  };
  await page.goto(`${base}/__fixture/session?kind=admin`);
  // A retained debug fixture can include a terminal prior run. Measure this
  // browser run's delta without deleting any transport evidence or ledger.
  wireBaseline = Number((await control('/__fixture/wire-count')).count);
  console.log('READINESS_WIRE_BASELINE', wireBaseline);
  mark('wire-baseline', {wireBaseline});
  await page.setViewportSize({width:1440,height:1000});
  await open();
  expect(await wire() === 0 && posts.length === 0, 'initial render performed work');
  const initial = await state();
  console.log('READINESS_INITIAL', JSON.stringify({state:initial.state,engine:initial.engine,issues:initial.issues,accounts_oauth:initial.accounts_oauth}));
  await page.screenshot({path:`${root}/readiness-initial-desktop.png`,fullPage:true});
  await page.locator('[data-qv4-password]').fill('entrypoints-fixture-password');
  const prepared = await submit('readiness');
  expect(prepared.state === 'TESTING' && await wire() === 0, 'prepare was not local-only');
  expect(Number(prepared.next_step) === 1, 'prepare did not offer first explicit step');
  const csrf = await form('readiness').locator('[name="_token"]').inputValue();
  const firstPlan = await submitPlan('readiness');
  await waitActionReady('readiness', firstPlan);
  const firstRequest = waitForSubmitRequest(firstPlan);
  await dom('before-dblclick');
  mark('dblclick-attempt');
  await button('readiness').dblclick();
  const firstResponse = await responseForRequest(await firstRequest);
  const first = await firstResponse.json();
  mark('dblclick-json', {ok:first.ok, next_step:first.next_step, calls:first.physical_http_calls});
  expect(first.ok === true && Number(first.next_step) === 2, 'first check did not pass exactly once');
  await page.waitForTimeout(500);
  console.log('READINESS_DOUBLE_CLICK', JSON.stringify({posts:posts.length,wire:await wire(),next_step:(await state()).next_step}));
  expect(await wire() === 1 && posts.length === 2, 'double-click or automatic chain dispatched another check');
  await page.screenshot({path:`${root}/readiness-step1-desktop.png`,fullPage:true});
  await open();
  expect(await wire() === 1 && posts.length === 2, 'reload dispatched work');
  expect(Number((await state()).next_step) === 2, 'reload lost explicit next step');
  const replay = await page.evaluate(async payload => {
    const response = await fetch('/settings/cron/queue-v4/readiness', {method:'POST',credentials:'same-origin',
      body:new URLSearchParams(payload)});
    const data = await response.json();
    return {status:response.status,calls:data.physical_http_calls};
  }, {_token:csrf,action:'check',run_id:String(prepared.run_id),run_token:prepared.run_token,step_no:'1'});
  expect(replay.status === 200 && Number(replay.calls) === 0 && await wire() === 1, 'same-step replay sent again');
  await submit('readiness');
  expect(await wire() === 2, 'second explicit click did not consume one call');
  const certified = await submit('readiness');
  expect(certified.state === 'CERTIFIED' && await wire() === 3, 'third explicit click did not certify');
  const stoppedCertification = await state();
  expect(stoppedCertification.engine === 'CERTIFIED' && stoppedCertification.scheduler === 'inactive', 'certification activated automatically');
  await page.locator('[data-qv4-password]').fill('entrypoints-fixture-password');
  await submit('activate');
  expect(await wire() === 3, 'activation used physical HTTP');
  await page.setViewportSize({width:390,height:844});
  await page.locator('#menuToggle').click({timeout:5000});
  await settleMobile('certified');
  await page.screenshot({path:`${root}/readiness-certified-mobile.png`,fullPage:true});
  await page.locator('[data-qv4-password]').fill('entrypoints-fixture-password');
  await submit('stop');
  expect(await wire() === 3, 'stop used physical HTTP');
  await page.locator('[data-qv4-password]').fill('entrypoints-fixture-password');
  await submit('readiness');
  await submit('readiness');
  expect(await wire() === 4, 'second run explicit check missing');
  await submit('cancel');
  await page.waitForTimeout(500);
  expect(await wire() === 4, 'cancel continued checking');
  await page.locator('[data-qv4-password]').fill('entrypoints-fixture-password');
  await submit('readiness');
  await control('/__fixture/expire-readiness');
  await open();
  expect(await button('readiness').isDisabled(), 'expired manifest still allows check');
  expect(await wire() === 4, 'expiry/reload dispatched HTTP');
  await page.locator('[data-qv4-password]').fill('entrypoints-fixture-password');
  await submit('cancel');
  expect(await wire() === 4, 'expired run cancellation used HTTP');
  await settleMobile('expired-cancel');
  await page.screenshot({path:`${root}/readiness-expired-cancel-mobile.png`,fullPage:true});
  expect(consoleErrors.length === 0, `console errors observed: ${consoleErrors.join(' | ')}`);
  expect(pageErrors.length === 0, `page errors observed: ${pageErrors.join(' | ')}`);
  expect(unexpectedHttp.length === 0, `unexpected HTTP errors observed: ${JSON.stringify(unexpectedHttp)}`);
  return 'PASS readiness real browser: prepare0; double-click/no autochain; reload; replay0; checks1+1+1; explicit activation/stop0; partial cancel; TTL expiry/cancel; desktop+mobile; fixture wire4; no real Meli HTTP';
}
