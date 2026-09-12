async (page, {outputRoot, baseUrl, logJson}) => {
  // Executed by the existing calls_browser_runner.cjs. Never emit DOM, form
  // values, request payloads, cookies, CSRF, passwords, or query strings.
  const check = (value, label) => { if (!value) throw new Error(label); };
  const context = page.context();
  const healthy = process.env.CAPACITY_BROWSER_STAGE === 'healthy';
  const forbidden = [];
  const errors = [];
  const consoleIssues = [];
  const negativeResponses = new Set();
  const observedPages = new WeakSet();
  const observe = p => {
    if (observedPages.has(p)) return;
    observedPages.add(p);
    p.on('pageerror', () => errors.push('pageerror'));
    p.on('response', response => {
      const path = new URL(response.url()).pathname;
      if ([403,409,422].includes(response.status())) negativeResponses.add(path+':'+response.status());
    });
    p.on('console', message => {
      if (!['warning','error'].includes(message.type())) return;
      const location = message.location().url;
      consoleIssues.push({text:message.text(),path:location ? new URL(location).pathname : '',type:message.type()});
    });
  };
  const consoleHealth = () => {
    const unexpected = consoleIssues.filter(issue => {
      const status = issue.text.match(/^Failed to load resource: the server responded with a status of (403|409|422)\b/);
      return !(status && /^\/settings\/(cron\/(rhythm|call-budget)|manual-processing(?:\/call-budget)?)$/.test(issue.path)
        && negativeResponses.has(issue.path+':'+status[1]));
    });
    logJson('capacity-checks.jsonl',{check:'console_health',expected_negative_errors:consoleIssues.length-unexpected.length,
      unexpected_console_errors:unexpected.length,page_errors:errors.length});
    check(unexpected.length === 0,'no_unexpected_console_warning_or_error');
    check(errors.length === 0,'no_browser_page_errors');
  };
  const settleMobileDrawer = async (p,label) => {
    const state = () => p.locator('#sidebar').evaluate(sidebar => {
      const rect=sidebar.getBoundingClientRect();
      const css=getComputedStyle(sidebar);
      return {left:rect.left,right:rect.right,width:rect.width,open:sidebar.classList.contains('open'),
        transform:css.transform,transition_duration:css.transitionDuration};
    });
    // Crossing the mobile breakpoint starts the real .2s CSS transform.
    // Observe it; never remove classes or override styles to obtain a clean PNG.
    logJson('drawer-geometry.jsonl',{check:label,stage:'after_resize_or_navigation',...await state()});
    await p.waitForFunction(() => {
      const sidebar=document.getElementById('sidebar');
      return !sidebar.classList.contains('open') && sidebar.getBoundingClientRect().right <= 0;
    },null,{timeout:5000});
    logJson('drawer-geometry.jsonl',{check:label,stage:'initial_closed_settled',...await state()});
    await p.locator('#menuToggle').click();
    await p.waitForFunction(() => {
      const sidebar=document.getElementById('sidebar');
      const rect=sidebar.getBoundingClientRect();
      return sidebar.classList.contains('open') && rect.left >= -0.01;
    },null,{timeout:5000});
    logJson('drawer-geometry.jsonl',{check:label,stage:'user_opened_settled',...await state()});
    const overlay=p.locator('#sidebarOverlay');
    const bounds=await overlay.boundingBox();
    check(bounds && bounds.width > 250,'mobile_drawer_overlay_available');
    // Click the visible backdrop to the right of the drawer, as a user does.
    await overlay.click({position:{x:bounds.width-12,y:Math.min(100,bounds.height-12)}});
    await p.waitForFunction(() => {
      const sidebar=document.getElementById('sidebar');
      return !sidebar.classList.contains('open') && sidebar.getBoundingClientRect().right <= 0
        && !document.getElementById('sidebarOverlay').classList.contains('open');
    },null,{timeout:5000});
    logJson('drawer-geometry.jsonl',{check:label,stage:'user_closed_fully_offscreen',...await state()});
  };
  const protect = async ctx => {
    await ctx.route('**/*', route => {
      const url = new URL(route.request().url());
      if (!['127.0.0.1', '[::1]', 'localhost'].includes(url.hostname)) {
        forbidden.push(url.hostname); return route.abort('blockedbyclient');
      }
      return route.continue();
    });
    ctx.on('page', observe);
    ctx.pages().forEach(observe);
  };
  await protect(context);
  const modules = {
    automation: {path:'/settings/cron/rhythm', action:'/settings/cron/call-budget', field:'automation_max_api_calls_per_cycle', title:'Cron'},
    manual: {path:'/settings/manual-processing', action:'/settings/manual-processing/call-budget', field:'manual_api_calls_per_step', title:'Procesar ahora'},
  };
  const form = (p, module) => p.locator(`form[action$="${modules[module].action}"]`);
  const current = (p, module) => form(p,module).locator(`[name="${modules[module].field}"]`);
  const ceiling = (p, module) => form(p,module).locator(`[name="${module}_api_calls_ceiling"]`);
  const login = async (p, email='manual@example.invalid') => {
    await p.goto(baseUrl + '/login');
    const token = await p.locator('[name="_token"]').inputValue();
    await p.getByLabel('Correo electrónico').fill(email);
    await p.getByLabel('Contraseña').fill('capacity-disposable-fixture-only');
    await Promise.all([p.waitForURL(url => url.pathname !== '/login'), p.getByRole('button',{name:'Ingresar al ERP'}).click()]);
    return token;
  };
  const open = async (p,module) => {
    const response = await p.goto(baseUrl + modules[module].path);
    check(response.status() === 200, module+'_real_GET_200');
    check((await p.title()).includes(modules[module].title), module+'_page_identity');
    check(await p.locator('.app-shell .sidebar').count() === 1, module+'_real_full_layout');
    check(await current(p,module).isVisible(), module+'_capacity_visible');
    if (module === 'automation' && !healthy) {
      check(await p.getByText('Diagnóstico no disponible',{exact:true}).isVisible(), 'real_missing_telemetry_warning');
      check(await p.locator('#rhythm-profile-form').count() === 0, 'unavailable_rhythm_form_omitted');
      check(await p.getByText('Sin 429 reciente',{exact:true}).count() === 0, 'unavailable_not_falsely_healthy');
    }
  };
  const pair = async (p,module,wantCurrent,wantCeiling) => {
    check(await current(p,module).inputValue() === String(wantCurrent), module+'_persisted_current_'+wantCurrent);
    check(await ceiling(p,module).inputValue() === String(wantCeiling), module+'_persisted_ceiling_'+wantCeiling);
  };
  const prepare = async (p,module,value,limit) => {
    await current(p,module).fill(String(value)); await ceiling(p,module).fill(String(limit));
    await Promise.all([p.waitForURL(url => url.pathname === modules[module].action), form(p,module).getByRole('button',{name:/Revisar y guardar/}).click()]);
    check(await p.getByRole('heading',{name:/Revisar capacidad/}).isVisible(), module+'_confirmation_rendered');
    check(await p.locator('.app-shell .sidebar').count() === 1, module+'_confirmation_full_layout');
    const values = await p.locator('.panel dd').allTextContents();
    check(values.length === 2 && values[0].endsWith('→ '+value) && values[1].endsWith('→ '+limit), module+'_confirmation_pair');
  };
  const finish = async (p,module,name='Confirmar') => {
    await Promise.all([p.waitForURL(url => url.pathname === modules[module].path), form(p,module).getByRole('button',{name,exact:true}).click()]);
  };
  const save = async (p,module,value,limit) => {
    await prepare(p,module,value,limit); await finish(p,module);
    check(await p.getByText('Capacidad guardada. No se inició ningún procesamiento.',{exact:true}).isVisible(), module+'_save_success');
    await p.reload(); await pair(p,module,value,limit);
  };
  const post = async (p,path,data) => p.evaluate(async ({path,data}) => {
    const headers = {'Content-Type':'application/x-www-form-urlencoded'};
    const response = await fetch(path,{method:'POST',headers,body:new URLSearchParams(data)});
    const text = await response.text();
    return {status:response.status,login:new URL(response.url).pathname === '/login',
      stale:/capacidad cambió|confirmación venció/.test(text)};
  }, {path,data});
  await login(page);
  if (healthy) {
    await open(page,'automation'); await pair(page,'automation',50,60);
    check(await page.getByText('Diagnóstico no disponible',{exact:true}).count() === 0,'restored_telemetry_warning_absent');
    check(await page.locator('#rhythm-profile-form').count() === 1,'restored_real_rhythm_form_present');
    check(await page.getByText('1 llamada cada 173 segundos',{exact:true}).isVisible(),'nondefault_saved_billing_rhythm_visible');
    await page.locator('details.rhythm-advanced-settings summary').click();
    check(await page.locator('[name="billing_min_interval_seconds"]').inputValue() === '173','advanced_rhythm_not_defaulted');
    await page.screenshot({path:outputRoot+'/automation-restored-desktop.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});
    await settleMobileDrawer(page,'restored_390');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1),'restored_390_no_horizontal_overflow');
    await form(page,'automation').scrollIntoViewIfNeeded();
    await page.screenshot({path:outputRoot+'/automation-restored-390.png',fullPage:false});
    await open(page,'manual'); await pair(page,'manual',50,60);
    consoleHealth(); check(forbidden.length === 0,'healthy_no_non_loopback_request_attempts');
    logJson('capacity-checks.jsonl',{check:'restored_real_diagnostics_and_nondefault_rhythm_without_save',status:'PASS'});
    return 'STATUS=PASS CAPACITY_BROWSER restored_telemetry real_nondefault_rhythm desktop1440 mobile390 no_rhythm_save';
  }
  for (const module of Object.keys(modules)) {
    await open(page,module); await pair(page,module,50,55);
    await prepare(page,module,50,60);
    await page.screenshot({path:outputRoot+'/'+module+'-confirm-desktop.png',fullPage:true});
    await finish(page,module,'Cancelar'); await page.reload(); await pair(page,module,50,55);
    await save(page,module,50,60); // Raising only the ceiling must keep current=50.
    await save(page,module,45,50);
    await save(page,module,40,100);
    await save(page,module,50,100);
    await save(page,module,90,100);
    await save(page,module,100,100);
    await save(page,module,55,55);
    await save(page,module,50,60);
    const untouched = module === 'automation' ? 'manual' : 'automation';
    const verify = await context.newPage();
    await open(verify,untouched); await pair(verify,untouched,50, module === 'automation' ? 55 : 60); await verify.close();
    // Both independent numeric inputs accept 99/50; server rejects the pair.
    const invalidData = await form(page,module).evaluate(f => Object.fromEntries(new FormData(f)));
    invalidData[modules[module].field]='99'; invalidData[module+'_api_calls_ceiling']='50';
    check((await post(page,modules[module].action,invalidData)).status === 422, module+'_invalid_pair_422');
    await open(page,module); await pair(page,module,50,60);
    // Old form in a second tab retains its old revision after the first saves.
    const staleTab = await context.newPage(); await open(staleTab,module);
    await save(page,module,55,60);
    const staleData = await form(staleTab,module).evaluate(f => Object.fromEntries(new FormData(f)));
    staleData[modules[module].field]='51';
    const stale = await post(staleTab,modules[module].action,staleData);
    check(stale.status === 409 && stale.stale, module+'_two_tabs_stale_revision_409');
    await staleTab.reload(); await pair(staleTab,module,55,60); await staleTab.close();
    await save(page,module,50,60);
    await page.screenshot({path:outputRoot+'/'+module+'-saved-desktop.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});
    await open(page,module); await pair(page,module,50,60);
    await settleMobileDrawer(page,module+'_390');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1), module+'_390_no_horizontal_overflow');
    await form(page,module).scrollIntoViewIfNeeded();
    await page.screenshot({path:outputRoot+'/'+module+'-saved-390.png',fullPage:false});
    await prepare(page,module,50,60); await finish(page,module,'Cancelar');
    await pair(page,module,50,60);
    await page.setViewportSize({width:1440,height:1000});
    logJson('capacity-checks.jsonl',{check:module+'_cancel_save_reload_pairs_independence_stale_mobile',status:'PASS'});
  }
  // Security negatives use production login/session/metadata/controller guards.
  await open(page,'automation');
  const valid = await form(page,'automation').evaluate(f => Object.fromEntries(new FormData(f)));
  for (const module of Object.keys(modules)) {
    await open(page,module);
    const data = await form(page,module).evaluate(f => Object.fromEntries(new FormData(f)));
    for (const kind of ['missing','invalid']) {
      const bad = {...data}; if (kind === 'missing') delete bad._token; else bad._token='invalid';
      check((await post(page,modules[module].action,bad)).status === 403, module+'_'+kind+'_csrf_403');
    }
    // Chromium may rewrite the forbidden Origin header on fetch interception.
    // A direct request to this fixed loopback URL carries the actual bad Origin
    // through the same production Router/CSRF/controller with browser cookies.
    check(new URL(baseUrl).hostname === '127.0.0.1','origin_probe_loopback_only');
    const badOrigin = await context.request.post(baseUrl+modules[module].action,{
      form:data,headers:{Origin:'https://untrusted.invalid'},maxRedirects:0,
    });
    check(badOrigin.status() === 403,module+'_wrong_origin_403');
  }
  for (const kind of ['anonymous','operator','temporary','foreign']) {
    const ctx = await context.browser().newContext(); await protect(ctx);
    const p = await ctx.newPage();
    let token='invalid';
    if (kind === 'anonymous') await p.goto(baseUrl+'/login');
    else token=await login(p, {operator:'operator@example.invalid',temporary:'temporary@example.invalid',foreign:'other@example.invalid'}[kind]);
    for (const module of Object.keys(modules)) {
      const result = await post(p,modules[module].action,{_token:token,capacity_revision:valid.capacity_revision,
        [modules[module].field]:'1',[module+'_api_calls_ceiling']:'60'});
      check(kind === 'anonymous' ? result.login : result.status === 403, module+'_'+kind+'_denied');
    }
    await ctx.close();
  }
  await open(page,'automation'); await pair(page,'automation',50,60);
  await open(page,'manual'); await pair(page,'manual',50,60);
  check(forbidden.length === 0,'no_non_loopback_request_attempts');
  consoleHealth();
  logJson('capacity-checks.jsonl',{check:'auth_csrf_origin_telemetry_and_zero_external',status:'PASS'});
  return 'STATUS=PASS CAPACITY_BROWSER desktop=1440 mobile=390 real_auth real_layout telemetry_unavailable no_external_requests';
}
