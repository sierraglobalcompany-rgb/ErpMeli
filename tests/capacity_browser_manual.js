async (page) => {
  const check = (v, why) => { if (!v) throw new Error(why); };
  const auto = 'http://127.0.0.1:8097/settings/cron/rhythm';
  const manual = 'http://127.0.0.1:8097/settings/manual-processing';
  const current = p => p.getByRole('spinbutton', {name:'Máximo de llamadas API por paso'});
  const ceiling = p => p.getByRole('spinbutton', {name:'Techo permitido de llamadas'});
  const review = p => p.getByRole('button', {name:'Revisar y guardar capacidad'});
  const confirm = p => p.getByRole('button', {name:'Confirmar',exact:true});
  await page.goto(manual);
  check(await current(page).inputValue() === '15', 'manual legacy effective not preserved');
  await ceiling(page).fill('100'); await review(page).click(); await page.getByRole('button',{name:'Cancelar',exact:true}).click();
  check(await ceiling(page).inputValue() === '55', 'manual cancellation wrote');
  await ceiling(page).fill('100'); await review(page).click(); await confirm(page).click(); await page.reload();
  check(await current(page).inputValue() === '15', 'manual ceiling increased current');
  await current(page).fill('3'); await review(page).click(); await confirm(page).click();
  for (const health of ['dead','stale','unknown','429']) {
    await page.goto(manual+'?health='+health); await current(page).fill('55'); await review(page).click(); await confirm(page).click();
    check(await current(page).inputValue() === '3', 'manual unsafe increase: '+health);
    await page.goto(auto+'?health='+health);
    await page.getByRole('spinbutton',{name:'Llamadas API por ciclo automático'}).fill('55');
    await page.getByRole('button',{name:'Revisar y guardar presupuesto'}).click(); await confirm(page).click();
    check(await page.getByRole('spinbutton',{name:'Llamadas API por ciclo automático'}).inputValue() === '1', 'auto unsafe increase: '+health);
  }
  await page.goto(manual+'?health=healthy'); await current(page).fill('55'); await review(page).click(); await confirm(page).click();
  check(await current(page).inputValue() === '55', 'manual healthy save');
  await ceiling(page).fill('2'); await review(page).click();
  check(/actual no puede superar/.test(await page.locator('body').innerText()), 'invalid pair not rejected');
  await page.goto(manual); check(await ceiling(page).inputValue() === '100', 'invalid pair mutated');
  await page.setViewportSize({width:390,height:844});
  await page.screenshot({path:'manual-mobile.png',fullPage:true});
  await page.setViewportSize({width:1440,height:1000}); await page.screenshot({path:'manual-desktop.png',fullPage:true});
  await page.goto(auto);
  check(await page.getByRole('spinbutton',{name:'Llamadas API por ciclo automático'}).inputValue() === '1','manual changed automatic');
  await page.screenshot({path:'auto-desktop.png',fullPage:true});
  console.log('BROWSER_MANUAL_PASS cancellation,persistence,independence,invalid-pair,dead,stale,unknown,429,healthy,mobile');
}
