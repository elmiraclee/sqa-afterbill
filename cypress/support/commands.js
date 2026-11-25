import { TEST_DOMAIN } from './constants';

const PASSWORD = 'Cypr3ss!Tests';

/** A unique test email on the cleanup domain. */
Cypress.Commands.add('testEmail', (prefix = 'user') => {
  return cy.wrap(`${prefix}.${Date.now()}@${TEST_DOMAIN}`, { log: false });
});

/** Create a verified user in the DB and yield { email, password, fullName }. */
Cypress.Commands.add('createUser', (overrides = {}) => {
  return cy.testEmail().then((email) => {
    const user = { email, password: PASSWORD, fullName: 'Cypress Tester', verified: true, ...overrides };
    return cy.task('db:createUser', user).then((id) => ({ ...user, id }));
  });
});

/** Fill and submit the login form. */
Cypress.Commands.add('loginViaUi', (email, password) => {
  cy.visit('/auth/login.php');
  cy.get('#email').clear().type(email);
  cy.get('#password').clear().type(password, { log: false });
  cy.get('form button[type=submit]').click();
});

/** Create a user and log in as them through the real form. Yields the user. */
Cypress.Commands.add('login', (overrides = {}) => {
  return cy.createUser(overrides).then((user) => {
    cy.loginViaUi(user.email, user.password);
    cy.location('pathname').should('match', /dashboard\.php$/);
    return cy.wrap(user);
  });
});
