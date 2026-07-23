// Default cucumber-js config for the AI Agent Modes suite.
//
// The feature set is split by flavour the same way the sibling AI Figma suites
// split theirs:
//   tests/features/drupal/   - the Drupal CMS acceptance suite (default).
//
// Browser-only BDD (Playwright + Cucumber via webship-js). Point it at any
// running site that has the ai_agent_modes module enabled and the
// tests/recipes/ai_agent_modes_test fixtures applied:
//
//   LAUNCH_URL=https://your-site.ddev.site npm test
//
// Loads webship-js's built-in step library plus this module's custom steps.

const baseWorldParameters = require('./cucumber.shared.js');

// The default lane is the always-green, provider-free set (see the tags note
// below). The amazee.ai leg selects the live-provider set with
//   CUCUMBER_TAGS="@ai and not @wip" npm test
// rather than a CLI `--tags`, because cucumber-js ANDs a CLI `--tags` with the
// profile tags (which would zero out any @ai selection).
const tagExpression = process.env.CUCUMBER_TAGS || 'not @ai and not @canvas-editor';

// webship-js auto-generates an HTML report at process exit and defaults its
// input to tests/reports/cucumber_report.json; keep both pointed at the same
// path the json formatter writes so the exit hook never throws ENOENT.
process.env.WEBSHIP_REPORT_JSON =
  process.env.WEBSHIP_REPORT_JSON || 'tests/reports/cucumber_report.json';
process.env.WEBSHIP_REPORT_OUT =
  process.env.WEBSHIP_REPORT_OUT || 'tests/reports/cucumber_report.html';

module.exports = {
  default: {
    timeout: 45000,
    requireModule: ['tsx/cjs'],
    require: [
      'node_modules/webship-js/tests/step-definitions/**/*.js',
      'tests/step-definitions/**/*.js',
    ],
    paths: ['tests/features/drupal/**/*.feature'],
    // The default lane is the always-green, provider-free CI set. Two families
    // are opt-in and excluded here:
    //   @ai            - live-provider scenarios. They need a real chat
    //                    provider (wired via the amazee.ai free-trial recipe in
    //                    CI, no API key). Run with:
    //                      npx cucumber-js --config cucumber.js --tags @ai
    //   @canvas-editor - the Drupal Canvas editor is a React single-page app;
    //                    asserting the injected dropdown inside its web-component
    //                    shadow DOM is slow and brittle, so it is kept out of CI.
    //                    Run with:
    //                      CUCUMBER_TAGS=@canvas-editor npm test
    tags: tagExpression,
    format: [
      'pretty',
      'json:tests/reports/cucumber_report.json',
    ],
    worldParameters: baseWorldParameters,
  },
};
