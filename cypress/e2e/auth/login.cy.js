describe('Login', () => {
  it('logs a verified user in and lands on the dashboard', () => {
    cy.createUser({ fullName: 'Juan Dela Cruz' }).then((user) => {
      cy.loginViaUi(user.email, user.password);
      cy.location('pathname').should('match', /dashboard\.php$/);
      cy.contains('h1', 'Juan');
    });
  });

  it('rejects a wrong password', () => {
    cy.createUser().then((user) => {
      cy.loginViaUi(user.email, 'Wr0ng!Password');
      cy.location('pathname').should('match', /login\.php$/);
      cy.get('.alert-error').should('contain', 'Invalid email or password.');
    });
  });

  it('gives the same error for an unknown email', () => {
    cy.testEmail('nobody').then((email) => {
      cy.loginViaUi(email, 'Some!Passw0rd');
      cy.get('.alert-error').should('contain', 'Invalid email or password.');
    });
  });

  it('blocks an unverified account and offers a new link', () => {
    cy.createUser({ verified: false }).then((user) => {
      cy.loginViaUi(user.email, user.password);
      cy.get('.alert-error')
        .should('contain', 'Please verify your email address first')
        .find('a[href="resend_verification.php"]');
    });
  });

  it('validates empty fields server-side', () => {
    cy.visit('/auth/login.php');
    cy.get('form button[type=submit]').click();
    cy.get('.alert-error')
      .should('contain', 'Please enter a valid email address.')
      .and('contain', 'Password is required.');
  });

  it('redirects a logged-in user away from the login page', () => {
    cy.login();
    cy.visit('/auth/login.php');
    cy.location('pathname').should('match', /dashboard\.php$/);
  });

  it('sends guests on protected pages back to login', () => {
    cy.visit('/dashboard.php');
    cy.location('pathname').should('match', /login\.php$/);
  });

  it('logs out', () => {
    cy.login();
    cy.get('.btn-logout').first().click();
    cy.location('pathname').should('match', /login\.php$/);
    cy.visit('/dashboard.php');
    cy.location('pathname').should('match', /login\.php$/);
  });
});
