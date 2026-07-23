'use strict';

/**
 * @file
 * Custom step definitions for the AI Agent Modes test suite.
 *
 * Every step drives the site through the browser only - no Drush, no shell.
 * The Mink-style navigation / assertion / form steps (`I am on ...`,
 * `I should see ...`, `I fill in ...`, `I press ...`), the JavaScript-error
 * check and the accessibility audits are all provided by webship-js. Only the
 * steps below are module-specific: logging in a named test user, provisioning
 * the non-admin fixtures, asserting server-side access denial, placing the AI
 * Agent Mode selector block, and asserting the rendered dropdown's options.
 *
 * Navigation and waiting reuse webship-js's own helpers - gotoUrl (friendly
 * navigation errors) and waitForPageLoad (BBR smart-settle: DOM ready, network
 * idle, no pending AJAX/timers, DOM-quiet) - instead of raw Playwright waits,
 * and failures are wrapped with friendly().
 */

const { Given, Then, When } = require('@cucumber/cucumber');
const {
  friendly,
  gotoUrl,
  waitForPageLoad,
} = require('webship-js/tests/step-definitions/webship');

/**
 * Run a step body and rethrow any failure as a tester-friendly error.
 *
 * @param {Function} body  - async function performing the step.
 * @param {string} message - human-readable description for failures.
 */
async function attempt(body, message) {
  try {
    await body();
  } catch (err) {
    throw friendly(message, err);
  }
}

/**
 * Log in as a named test user defined in cucumber.shared.js
 * worldParameters.users.
 *
 * Uses Drupal's stable field IDs so the step is theme-independent (Olivero,
 * Claro/Gin and Gin all render `#edit-name` / `#edit-pass`).
 *
 * Example #1: Given I am a logged in user with the "Webmaster" user
 * Example #2: Given I am a logged in user with the "webmaster" user
 * Example #3: Given I am a logged in user with the "Content editor" user
 * Example #4: Given I am a logged in user with the "Authenticated user" user
 */
Given(/^I am a logged in user with( the)*( username)* "([^"]*)?"( user)?$/, async function (theCase, usernameCase, key, userCase) {
  const users = this.parameters.users || {};
  if (!(key in users)) {
    throw new Error(`No user named "${key}" in cucumber.shared.js worldParameters.users`);
  }
  const { username, password } = users[key];
  if (!username || !password) {
    throw new Error(`User "${key}" is missing username or password in worldParameters.users`);
  }
  await attempt(async () => {
    await this.context.clearCookies();
    await gotoUrl(this.page, `${this.parameters.launchUrl}/user/login`);
    await this.page.locator('#edit-name').fill(username);
    await this.page.locator('#edit-pass').fill(password);
    // Scope the submit to the login form so it works whether the active theme
    // renders it as an <input> (Olivero / Claro / Gin) or a <button>, and never
    // matches a header search button.
    await this.page.locator('#user-login-form #edit-submit').first().click();
    await waitForPageLoad(this.page, this.minWaitTime && this.minWaitTime.page);
  }, `Could not log in as "${key}"`);
});

/**
 * Assert the page does not contain a PHP error, fatal, warning, notice, or
 * Drupal's "unexpected error" page.
 *
 * Example #1: Then the page should not have PHP errors
 * Example #2: And I the page should not have PHP errors
 * Example #3: And we the page should not have PHP errors
 */
Then(/^(?:I |we )?the page should not have PHP errors$/, async function () {
  await attempt(async () => {
    const content = await this.page.content();
    const phpErrorPatterns = [
      /Fatal error:/i,
      /Warning:.*on line/i,
      /Notice:.*on line/i,
      /Parse error:/i,
      /The website encountered an unexpected error/i,
    ];
    for (const pattern of phpErrorPatterns) {
      if (pattern.test(content)) {
        throw new Error(`PHP error detected on page: ${this.page.url()}`);
      }
    }
  }, 'Expected page to be free of PHP errors');
});

/**
 * Navigate to a path and assert the server denied access. Checks the HTTP
 * response status (403) first - theme-agnostic - and falls back to the Drupal
 * "not authorized" body text.
 *
 * Example #1: Then I am denied access to "/admin/config/ai/agent-modes"
 * Example #2: And I am denied access to "/admin/config/ai/agent-modes/add"
 * Example #3: Then we am denied access to "/admin/config/ai/agent-modes/settings"
 */
Then(/^(?:I |we )?am denied access to "([^"]*)"$/, async function (path) {
  await attempt(async () => {
    const response = await this.page.goto(`${this.parameters.launchUrl}${path}`, { waitUntil: 'networkidle' });
    const status = response ? response.status() : 0;
    if (status === 403) {
      return;
    }
    const body = await this.page.content();
    if (!/not authorized to access this page|access denied/i.test(body)) {
      throw new Error(`Expected access to "${path}" to be denied (HTTP 403), got HTTP ${status}`);
    }
  }, `Expected to be denied access to "${path}"`);
});

/**
 * Provision every non-admin user from worldParameters.users via Drupal's
 * /admin/people/create form. Entries flagged isAdmin: true are skipped.
 * Idempotent. Must be invoked while logged in as the Webmaster.
 *
 * Example #1: Given I add testing users
 * Example #2: And I add testing users
 */
Given(/^(?:I |we )?add( the)? testing users$/, async function (theCase) {
  const users = this.parameters.users || {};
  await attempt(async () => {
    for (const [key, info] of Object.entries(users)) {
      if (info.isAdmin) continue;
      if (key === 'webmaster') continue;
      await gotoUrl(this.page, `${this.parameters.launchUrl}/admin/people/create`);
      await this.page.evaluate((info) => {
        const set = (sel, val) => { const el = document.querySelector(sel); if (el) el.value = val; };
        set('#edit-name', info.username);
        set('#edit-mail', info.email || `${info.username}@example.test`);
        set('#edit-pass-pass1', info.password);
        set('#edit-pass-pass2', info.password);
        for (const role of info.roles || []) {
          const cb = document.querySelector(`input[name="roles[${role}]"]`);
          if (cb) cb.checked = true;
        }
      }, info);
      await this.page.evaluate(() => document.querySelector('#edit-submit').click());
      await waitForPageLoad(this.page);
    }
  }, 'Could not provision the testing users');
});

/**
 * Place the "AI Agent Mode selector" block, configured to read its parent
 * agent from a given AI Assistant, into the default theme's content region.
/**
 * Assert the AI Agent Mode selector dropdown (a `select[name="mode"]`) is
 * present on the page and offers an option whose visible text contains the
 * given label. `<option>` text is in the DOM but not "visible" until the select
 * is opened, so this asserts option text directly rather than page visibility.
 * Scans every mode selector on the page, so it is tolerant of more than one
 * selector block being placed.
 *
 * The selector is rendered by the "AI Agent Mode selector" block, which the
 * test fixtures (tests/recipes/ai_agent_modes_test) place in the Gin admin
 * theme's content region reading its parent agent from the seeded AI Assistant.
 * That is the same resolveAgent() + `ai_agent_mode_select` render path the AI
 * Assistant chatbot integrations (ChatbotHooks / ChatFormHooks) use, proving
 * the dropdown renders without the deep-chat web component or a live provider.
 *
 * Example #1: Then the mode selector should offer the option "Free-form (all sub-agents)"
 * Example #2: Then the mode selector should offer the option "Test Child One"
 * Example #3: And the mode selector should offer the option "Focus on Child One"
 */
Then(/^the mode selector should offer the option "([^"]*)"$/, async function (label) {
  await attempt(async () => {
    const select = this.page.locator("select[name='mode']").first();
    await select.waitFor({ state: 'attached', timeout: 10000 });
    const found = await this.page.evaluate((label) => {
      const selects = document.querySelectorAll("select[name='mode']");
      return Array.from(selects).some((el) =>
        Array.from(el.querySelectorAll('option')).some(
          (o) => o.textContent.replace(/\s+/g, ' ').trim().includes(label),
        ),
      );
    }, label);
    if (!found) {
      throw new Error(`No option containing "${label}" in any mode selector`);
    }
  }, `Expected the mode selector to offer the option "${label}"`);
});

/**
 * Assert a JSON response body (rendered as text by the browser) contains the
 * given fragment. Used for the Canvas AI options endpoint, whose JSON the
 * Canvas panel JS fetches to build the dropdown client-side.
 *
 * Example #1: Then the JSON response should contain "All — let the assistant decide"
 * Example #2: And the JSON response should contain "Focus on Child One"
 */
Then(/^the JSON response should contain "([^"]*)"$/, async function (fragment) {
  await attempt(async () => {
    const body = await this.page.evaluate(() => document.body ? document.body.innerText : '');
    if (!body.includes(fragment)) {
      throw new Error(`JSON response did not contain "${fragment}". Body: ${body.slice(0, 300)}`);
    }
  }, `Expected the JSON response to contain "${fragment}"`);
});

/**
 * Resolve a webship-js named selector from the world registry (hydrated from
 * cucumber.shared.js's selectors.files - see tests/selectors/*.json). Throws on
 * an unknown name so a typo never silently passes through as a literal CSS
 * string.
 *
 * @param {object} world - the cucumber World (this).
 * @param {string} name  - the registered selector name.
 *
 * @return {string} the resolved CSS selector.
 */
function resolveName(world, name) {
  const css = world.__selectorsCss || {};
  const key = name.trim();
  if (Object.prototype.hasOwnProperty.call(css, key)) {
    return css[key];
  }
  throw new Error(`Unknown named selector "${key}". Run "Then print css selectors" to see all ${Object.keys(css).length} registered names.`);
}

/**
 * Assert a named selector is visible / hidden / attached on the page.
 *
 * Example #1: Then the "drupal page heading" element should be visible
 * Example #2: Then the "mode selector" element should be visible within 5 seconds
 * Example #3: Then the "mode add form" element should be attached
 */
Then(/^the "([^"]*)" element should be (visible|hidden|attached)(?: within (\d+) seconds?)?$/, async function (name, state, sec) {
  const sel = resolveName(this, name);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  await attempt(async () => {
    await this.page.locator(sel).first().waitFor({ state, timeout });
  }, `Expected "${name}" (${sel}) to be ${state}`);
});

/**
 * Assert the first element matching a named selector contains the given text.
 *
 * Example #1: Then the "drupal page heading" element should contain text "AI Agent Modes"
 * Example #2: Then the "drupal page heading" element should contain text "Status report"
 */
Then(/^the "([^"]*)" element should contain text "([^"]*)"(?: within (\d+) seconds?)?$/, async function (name, text, sec) {
  const sel = resolveName(this, name);
  const timeout = sec ? Number(sec) * 1000 : 10000;
  await attempt(async () => {
    await this.page.waitForFunction(
      ([s, t]) => {
        const el = document.querySelector(s);
        return el && el.textContent.includes(t);
      },
      [sel, text],
      { timeout, polling: 100 },
    );
  }, `Expected "${name}" (${sel}) to contain text "${text}"`);
});

/**
 * Submit the current entity form by clicking its primary submit button in the
 * page context. The Gin admin theme renders a sticky form-actions bar that can
 * intercept a normal pointer click on the "Save" button, so a JS click on
 * #edit-submit is used instead. Follows the same approach the sibling AI Figma
 * suite uses for its Gin/vartheme_bs5 form saves.
 *
 * Example #1: When I save the form
 * Example #2: And I save the form
 */
When(/^(?:I |we )?save the form$/, async function () {
  await attempt(async () => {
    await this.page.evaluate(() => {
      const btn = document.querySelector('#edit-submit')
        || document.querySelector('.form-actions [type="submit"], .gin-sticky-form-actions [type="submit"]');
      if (btn) btn.click();
    });
    await waitForPageLoad(this.page, this.minWaitTime && this.minWaitTime.page);
  }, 'Could not save the form');
});
