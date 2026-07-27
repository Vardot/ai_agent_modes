'use strict';

/**
 * @file
 * Custom step definitions for the AI Agent Modes test suite.
 *
 * Every step drives the site through the browser only - no Drush, no shell.
 * The Mink-style navigation / assertion / form steps (`I am on ...`,
 * `I should see ...`, `I fill in ...`, `I press ...`), the JavaScript-error
 * check and the accessibility audits are all provided by webship-js. So are the
 * table-row assertions (`I should see "..." in the "..." row`) and the radio
 * assertions (`the radio button with value "..." should be selected`). Only the
 * steps below are module-specific: logging in a named test user, provisioning
 * the non-admin fixtures, asserting server-side access denial, asserting the
 * rendered dropdown's options, reading the options endpoint the chat surfaces
 * build their dropdown from (its labels, how many options it offers and which
 * dropdown placement it reports), and opening a row operation from an admin
 * listing.
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
 * The step asserts the session was really established: a rejected login
 * re-renders the login form at /user/login with the reason in the message
 * region, and the step fails there, quoting that reason. Without that check a
 * failed login is silent and only surfaces later, as an unrelated step reading
 * an anonymous 403 page.
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
    const outcome = await this.page.evaluate(() => {
      const region = document.querySelector('[data-drupal-messages]');
      return {
        url: window.location.href,
        onLoginForm: !!document.querySelector('form#user-login-form'),
        message: region ? region.textContent.replace(/\s+/g, ' ').trim() : '',
      };
    });
    // Both conditions together: a rejected login re-renders the form at
    // /user/login, while a login block elsewhere on a post-login page would
    // match the form alone.
    if (outcome.onLoginForm && outcome.url.includes('/user/login')) {
      throw new Error(`the login form came back at ${outcome.url}, so no session was established. The site said: ${outcome.message || '(no message)'}`);
    }
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
 * Set a user's password from the admin account edit form.
 *
 * An administrator editing somebody else's account is not asked for a current
 * password, so the two password fields are all that is needed. Used to repair a
 * fixture user that already exists with an unknown password.
 *
 * @param {object} world      - the cucumber World (this).
 * @param {string} username   - the account name to look up.
 * @param {string} password   - the password to set.
 *
 * @return {Promise<boolean>} TRUE when the account was found and saved.
 */
async function setUserPassword(world, username, password) {
  const base = world.parameters.launchUrl;
  await gotoUrl(world.page, `${base}/admin/people?user=${encodeURIComponent(username)}`);
  await waitForPageLoad(world.page);
  const href = await world.page.evaluate((username) => {
    const row = Array.from(document.querySelectorAll('tr')).find(
      (candidate) => candidate.textContent.includes(username),
    );
    if (!row) {
      return '';
    }
    const link = Array.from(row.querySelectorAll('a')).find((candidate) =>
      /\/user\/\d+\/edit/.test(candidate.getAttribute('href') || ''),
    );
    return link ? link.getAttribute('href') : '';
  }, username);
  if (!href) {
    return false;
  }
  await gotoUrl(world.page, href.startsWith('http') ? href : `${base}${href}`);
  await waitForPageLoad(world.page);
  await world.page.locator('#edit-pass-pass1').fill(password);
  await world.page.locator('#edit-pass-pass2').fill(password);
  await world.page.evaluate(() => document.querySelector('#edit-submit').click());
  await waitForPageLoad(world.page);
  return true;
}

/**
 * Provision every non-admin user from worldParameters.users via Drupal's
 * /admin/people/create form. Entries flagged isAdmin: true are skipped.
 * Idempotent. Must be invoked while logged in as the Webmaster.
 *
 * "Notify user of new account" is unchecked before the form is filled, and that
 * is load-bearing on Drupal CMS. Its drupal_cms_authentication recipe ships the
 * "User registration" ECA model (eca.eca.user_register), which checks that box
 * by default, hides the password fields behind an #states rule while it is
 * checked, and on form validate REPLACES the submitted password with a random
 * 15-character string (Activity_get_random_string -> Activity_set_password) so
 * the new account is activated from the emailed one-time login link instead.
 * With the box left checked, the accounts are created and active but with a
 * password nobody knows, so every later login as one of them fails.
 *
 * When the account already exists (from a site provisioned before this step
 * unchecked the box, or simply a re-run), the create form reports the name as
 * taken and the password is set from the account edit form instead, so the
 * step's contract holds either way: the user exists AND has the password
 * worldParameters.users declares.
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
      const password = info.password;
      await gotoUrl(this.page, `${this.parameters.launchUrl}/admin/people/create`);
      await waitForPageLoad(this.page);
      // Uncheck first: while it is checked the password fields are invisible,
      // so they cannot be filled, and the submitted password is discarded.
      const notify = this.page.locator('#edit-notify');
      if (await notify.count()) {
        await notify.uncheck();
      }
      await this.page.locator('#edit-name').fill(info.username);
      await this.page.locator('#edit-mail').fill(info.email || `${info.username}@example.test`);
      await this.page.locator('#edit-pass-pass1').fill(password);
      await this.page.locator('#edit-pass-pass2').fill(password);
      for (const role of info.roles || []) {
        const checkbox = this.page.locator(`input[name="roles[${role}]"]`);
        if (await checkbox.count()) {
          await checkbox.check();
        }
      }
      await this.page.evaluate(() => document.querySelector('#edit-submit').click());
      await waitForPageLoad(this.page);
      const body = await this.page.locator('body').textContent() || '';
      if (body.includes('is already taken')) {
        if (!await setUserPassword(this, info.username, password)) {
          throw new Error(`"${info.username}" already exists but no account edit link was found for it on /admin/people`);
        }
      }
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
 * Read the current page body as text. The options endpoint answers with
 * application/json, which the browser renders as the whole body text.
 *
 * @param {object} page - the Playwright page.
 *
 * @return {Promise<string>} the body text.
 */
async function readBodyText(page) {
  return page.evaluate(() => (document.body ? document.body.innerText : ''));
}

/**
 * Read the current page body and decode it as JSON.
 *
 * @param {object} page - the Playwright page.
 *
 * @return {Promise<object>} the decoded payload.
 */
async function readJsonBody(page) {
  const body = await readBodyText(page);
  try {
    return JSON.parse(body);
  } catch (err) {
    throw new Error(`Response body is not valid JSON. Body: ${body.slice(0, 300)}`);
  }
}

/**
 * Assert a JSON response body (rendered as text by the browser) contains - or
 * does not contain - the given fragment. Used for the Canvas AI options
 * endpoint, whose JSON the Canvas panel JS fetches to build the dropdown
 * client-side.
 *
 * Example #1: Then the JSON response should contain "All, let the assistant decide"
 * Example #2: And the JSON response should contain "Focus on Child One"
 * Example #3: Then the JSON response should not contain "Focus on Child One"
 * Example #4: And the JSON response should not contain "mode:test_focus_one"
 */
Then(/^the JSON response should( not)* contain "([^"]*)"$/, async function (negated, fragment) {
  await attempt(async () => {
    const body = await readBodyText(this.page);
    if (negated && body.includes(fragment)) {
      throw new Error(`JSON response still contained "${fragment}". URL: ${this.page.url()}. Body: ${body.slice(0, 300)}`);
    }
    if (!negated && !body.includes(fragment)) {
      // Include the page URL: a body that looks like a rendered HTML page
      // (not JSON) usually means this request was denied or redirected
      // instead of reaching the endpoint, and the URL confirms which.
      throw new Error(`JSON response did not contain "${fragment}". URL: ${this.page.url()}. Body: ${body.slice(0, 300)}`);
    }
  }, `Expected the JSON response${negated ? ' not' : ''} to contain "${fragment}"`);
});

/**
 * Assert how many options the mode options endpoint offers for the agent in the
 * current URL.
 *
 * This is the number both client-side surfaces gate their dropdown on: the
 * Canvas AI panel (js/canvas-ai.js) and the AI Chatbot DeepChat block
 * (js/chatbot-deepchat.js) each bail out of injecting anything while
 * `data.options.length <= 1`, so a payload of exactly one option means the
 * person in the chat is offered no dropdown at all.
 *
 * Example #1: Then the options endpoint should offer 1 option
 * Example #2: Then the options endpoint should offer more than 1 option
 * Example #3: Then the options endpoint should offer 2 options
 * Example #4: And the options endpoint should offer more than 2 options
 * Example #5: And the options endpoint should offer 3 options
 */
Then(/^the options endpoint should offer (more than )?(\d+) options?$/, async function (atLeast, expected) {
  await attempt(async () => {
    const data = await readJsonBody(this.page);
    const options = Array.isArray(data.options) ? data.options : [];
    const labels = options.map((option) => option.label).join(' | ');
    if (atLeast && options.length <= Number(expected)) {
      throw new Error(`Expected more than ${expected} option(s), got ${options.length}: ${labels}`);
    }
    if (!atLeast && options.length !== Number(expected)) {
      throw new Error(`Expected ${expected} option(s), got ${options.length}: ${labels}`);
    }
  }, `Expected the options endpoint to offer ${atLeast ? 'more than ' : ''}${expected} option(s)`);
});

/**
 * Assert the dropdown placement the options endpoint reports.
 *
 * The placement configured at /admin/config/ai/agent-modes/settings rides on
 * this payload (SelectionController::options()), and js/canvas-ai.js places the
 * control from it: `top` and `below_input` in the panel's light DOM, and
 * `above_input` / `toolbar` anchored inside the message box.
 *
 * Example #1: Then the options endpoint should report the "toolbar" dropdown placement
 * Example #2: Then the options endpoint should report the "top" dropdown placement
 * Example #3: And the options endpoint should report the "above_input" dropdown placement
 */
Then(/^the options endpoint should report the "([^"]*)" dropdown placement$/, async function (placement) {
  await attempt(async () => {
    const data = await readJsonBody(this.page);
    if (data.position !== placement) {
      throw new Error(`Expected placement "${placement}", got "${data.position}"`);
    }
  }, `Expected the options endpoint to report the "${placement}" dropdown placement`);
});

/**
 * Open a named operation link from the row of an admin listing, addressing the
 * row by a piece of its visible text (a mode label here).
 *
 * Drupal renders row operations in a dropbutton whose secondary links (Delete)
 * stay collapsed until the toggle is pressed, so the link's own href is read
 * from that row and followed. That keeps the step theme-independent (Claro and
 * Gin collapse the dropbutton differently) and keeps entity IDs out of the
 * feature files: the row is found by the label the scenario itself typed.
 *
 * Example #1: When I open the "Delete" operation in the "Focus on Child One" row
 * Example #2: When I open the "Edit" operation in the "Prompt only mode" row
 * Example #3: And I open the "Delete" operation in the "Generic mode" row
 */
When(/^(?:I |we )?open the "([^"]*)" operation in( the)* "([^"]*)" row$/, async function (operation, theCase, rowText) {
  await attempt(async () => {
    const href = await this.page.evaluate(([operation, rowText]) => {
      const rows = Array.from(document.querySelectorAll('tr'));
      const row = rows.find((candidate) => candidate.textContent.includes(rowText));
      if (!row) {
        return '';
      }
      const link = Array.from(row.querySelectorAll('a')).find(
        (candidate) => candidate.textContent.replace(/\s+/g, ' ').trim() === operation,
      );
      return link ? link.getAttribute('href') : '';
    }, [operation, rowText]);
    if (!href) {
      throw new Error(`No "${operation}" operation link in the "${rowText}" row`);
    }
    const url = href.startsWith('http') ? href : `${this.parameters.launchUrl}${href}`;
    await gotoUrl(this.page, url);
    await waitForPageLoad(this.page, this.minWaitTime && this.minWaitTime.page);
  }, `Could not open the "${operation}" operation in the "${rowText}" row`);
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
