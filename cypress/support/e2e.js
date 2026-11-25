import './commands';

// Each spec starts from a clean slate: no leftover test users, no per-IP rate limits.
beforeEach(() => {
  cy.task('db:reset');
});
