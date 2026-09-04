import { test, expect } from '@playwright/test';

const PLUGIN_PAGE = '/wp-admin/admin.php?page=fswa-webhook-actions#/ai-builder';

test('mobile: active model bar wraps instead of overflowing', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'this bug only shows at phone widths');

  await page.goto(PLUGIN_PAGE);
  await page.waitForLoadState('networkidle');

  // The bar only renders once a provider is connected/trialing; if this site
  // has neither configured, there's nothing to check here.
  const bar = page.locator('text=Change model').locator('xpath=ancestor::div[contains(@class, "rounded-lg")][1]');
  if ((await bar.count()) === 0) {
    test.skip(true, 'no active-model bar on this install (no provider connected)');
  }

  const viewportWidth = page.viewportSize().width;
  const box = await bar.first().boundingBox();
  expect(box, 'model bar should be measurable').not.toBeNull();
  expect(box.x + box.width, 'model bar must not extend past the viewport').toBeLessThanOrEqual(viewportWidth + 1);

  // Every direct child (logo, title block, credit badges, review toggle,
  // change-model button) must also stay within the viewport — this is what
  // "wraps instead of clipping/overlapping" actually means.
  const overflowingChildren = await bar.first().evaluate((el, vw) => {
    const offenders = [];
    el.querySelectorAll('*').forEach((node) => {
      const r = node.getBoundingClientRect();
      if (r.width > 0 && r.right > vw + 1) {
        offenders.push({ tag: node.tagName, class: node.className, right: r.right });
      }
    });
    return offenders;
  }, viewportWidth);

  expect(overflowingChildren, JSON.stringify(overflowingChildren)).toHaveLength(0);
});
